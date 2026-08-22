<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Http\Middleware\RateLimitBookings;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\IcalFeedService;
use ArtisanPackUI\Bookings\Services\IcalTokenService;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // The Monday every booking helper works around, far enough ahead of the
    // 1 June diary that nothing the feed serves is in the past.
    $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );
} );

describe( 'the ical_feed.enabled off-switch', function (): void {
    it( 'registers the feed routes while the feed is enabled', function (): void {
        expect( Route::has( 'artisanpack.bookings.ical.provider' ) )->toBeTrue()
            ->and( Route::has( 'artisanpack.bookings.ical.customer' ) )->toBeTrue();
    } );

    it( 'registers no feed routes when the feed is switched off', function (): void {
        config()->set( 'artisanpack.bookings.calendar.ical_feed.enabled', false );

        // Re-register the public routes under the new config against a fresh
        // collection: the feed group is gated at registration, so the routes
        // simply never appear when the switch is off.
        app( 'router' )->setRoutes( new RouteCollection() );
        Route::middleware( 'api' )->group( dirname( __DIR__, 3 ) . '/routes/public.php' );
        app( 'router' )->getRoutes()->refreshNameLookups();

        expect( Route::has( 'artisanpack.bookings.ical.provider' ) )->toBeFalse()
            ->and( Route::has( 'artisanpack.bookings.ical.customer' ) )->toBeFalse()
            ->and( Route::has( 'artisanpack.bookings.api.services.index' ) )->toBeTrue();
    } );
} );

/**
 * Books an appointment and hands back the provider it landed on, with a feed
 * token minted for them.
 *
 * The token is returned rather than fetched back later because only its hash is
 * stored — this is the one moment the plain value exists, which is the whole
 * point of the scheme under test.
 *
 * @param  string  $start  The provider-local clock face to book.
 *
 * @return array{0: Booking, 1: ServiceProvider, 2: string} The booking, its
 *                                                          provider, and the
 *                                                          provider's feed token.
 */
function feedBooking( string $start = '10:00' ): array
{
    [ $service ] = bookableService();

    $booking = bookingService()->create( bookingCustomer( [
        'service'    => $service,
        'start_time' => bookingStart( $start ),
    ] ) );

    return [ $booking, $booking->provider, issueFeedToken( $booking->provider ) ];
}

/**
 * Mints a calendar feed token for a provider.
 *
 * @param  ServiceProvider  $provider  The provider to issue for.
 *
 * @return string The plain feed token.
 */
function issueFeedToken( ServiceProvider $provider ): string
{
    return app( IcalTokenService::class )->issueFor( $provider );
}

/**
 * Builds the URL a provider's calendar client would be pointed at.
 *
 * @param  string  $token  The provider's plain feed token.
 *
 * @return string The URL.
 */
function providerFeedUrl( string $token ): string
{
    return '/bookings/ical/providers/' . $token . '.ics';
}

/**
 * Builds the URL a customer's calendar client would be pointed at.
 *
 * @param  string  $token  The plain manage token.
 *
 * @return string The URL.
 */
function customerFeedUrl( string $token ): string
{
    return '/bookings/ical/customers/' . $token . '.ics';
}

/**
 * Undoes RFC 5545 line folding so a test can look for a whole value.
 *
 * Serialised calendars wrap at seventy-five octets and continue on the next line
 * behind a space, so an email address or a customer's name is routinely split
 * across two lines — and a naive `toContain()` would report it missing.
 *
 * @param  false|string  $body  The serialised calendar.
 *
 * @return string The calendar with its folds joined up.
 */
function unfolded( string|false $body ): string
{
    return str_replace( [ "\r\n ", "\n " ], '', (string) $body );
}

describe( 'GET provider feed', function (): void {
    it( 'serves the provider\'s diary as a calendar', function (): void {
        [ $booking, $provider, $token ] = feedBooking();

        $response = $this->get( providerFeedUrl( $token ) )->assertOk();

        expect( $response->headers->get( 'Content-Type' ) )->toBe( 'text/calendar; charset=utf-8' );

        $body = unfolded( $response->getContent() );

        expect( $body )->toContain( 'BEGIN:VCALENDAR' )
            ->and( $body )->toContain( 'PRODID:' . IcalFeedService::PRODUCT_ID )
            ->and( $body )->toContain( 'X-WR-CALNAME:' . $provider->name )
            ->and( $body )->toContain( 'UID:' . $booking->booking_number . '@' )
            ->and( $body )->toContain( 'DTSTART:20260601T150000Z' )
            ->and( $body )->toContain( 'DTEND:20260601T160000Z' )
            ->and( $body )->toContain( 'END:VCALENDAR' );
    } );

    it( 'names the file after the provider so a client can label the subscription', function (): void {
        [ , $provider, $token ] = feedBooking();

        $disposition = $this->get( providerFeedUrl( $token ) )
            ->assertOk()
            ->headers->get( 'Content-Disposition' );

        expect( $disposition )->toBe( 'inline; filename="' . $provider->slug . '.ics"' );
    } );

    it( 'refuses to let a slug write its own headers', function ( string $slug ): void {
        [ , $provider, $token ] = feedBooking();

        // A slug is written by staff rather than derived, and it still reaches
        // the quoted filename in Content-Disposition even now that it is out of
        // the URL — where a `"` closes the quoting early and a CR ends the
        // header. Reachable end to end precisely because the route no longer
        // refuses these on the way in.
        $provider->newQueryWithoutScopes()->whereKey( $provider->getKey() )->toBase()->update( [
            'slug' => $slug,
        ] );

        $disposition = (string) $this->get( providerFeedUrl( $token ) )
            ->assertOk()
            ->headers->get( 'Content-Disposition' );

        expect( $disposition )->toBe( 'inline; filename="ax1.ics"' );
    } )->with( [
        'a quote'           => 'a"; x=1',
        'a carriage return' => "a\r\nx\r\n1",
        'a space'           => 'a x 1',
    ] );

    it( 'falls back to a fixed filename when a slug sanitises to nothing', function (): void {
        [ , $provider, $token ] = feedBooking();

        $provider->newQueryWithoutScopes()->whereKey( $provider->getKey() )->toBase()->update( [
            'slug' => '///',
        ] );

        $disposition = (string) $this->get( providerFeedUrl( $token ) )
            ->assertOk()
            ->headers->get( 'Content-Disposition' );

        expect( $disposition )->toBe( 'inline; filename="calendar.ics"' );
    } );

    it( 'tells a client how long it may hold the feed', function (): void {
        [ , $provider, $token ] = feedBooking();

        $response = $this->get( providerFeedUrl( $token ) )->assertOk();

        expect( $response->headers->get( 'Cache-Control' ) )->toContain( 'private' )
            ->and( $response->headers->get( 'Cache-Control' ) )->toContain( 'max-age=300' )
            ->and( $response->headers->get( 'ETag' ) )->toMatch( '/^"[0-9a-f]{40}"$/' );
    } );

    it( 'answers a conditional fetch with 304 and no body', function (): void {
        [ , $provider, $token ] = feedBooking();

        $etag = $this->get( providerFeedUrl( $token ) )->assertOk()->headers->get( 'ETag' );

        $response = $this->get( providerFeedUrl( $token ), [ 'If-None-Match' => $etag ] );

        $response->assertStatus( Response::HTTP_NOT_MODIFIED );

        // The caching headers have to come back with it, or the client is left
        // holding a copy it may not reuse and the next poll is a full fetch.
        expect( unfolded( $response->getContent() ) )->toBe( '' )
            ->and( $response->headers->get( 'ETag' ) )->toBe( $etag )
            ->and( $response->headers->get( 'Cache-Control' ) )->toContain( 'max-age=300' );
    } );

    it( 'matches a tag a proxy has weakened, or sent alongside others', function ( string $template ): void {
        [ , $provider, $token ] = feedBooking();

        $etag = (string) $this->get( providerFeedUrl( $token ) )->assertOk()->headers->get( 'ETag' );

        $this->get( providerFeedUrl( $token ), [ 'If-None-Match' => str_replace( '{etag}', $etag, $template ) ] )
            ->assertStatus( Response::HTTP_NOT_MODIFIED );
    } )->with( [
        'weakened'      => 'W/{etag}',
        'one of a list' => '"0000000000000000000000000000000000000000", {etag}',
        'a wildcard'    => '*',
    ] );

    it( 'serves the feed again when a tag no longer matches', function (): void {
        [ , $provider, $token ] = feedBooking();

        $this->get( providerFeedUrl( $token ), [ 'If-None-Match' => '"not-the-current-one"' ] )
            ->assertOk();
    } );

    it( 'moves the tag when a booking is added', function (): void {
        [ , $provider, $token ] = feedBooking();

        $before = $this->get( providerFeedUrl( $token ) )->headers->get( 'ETag' );

        bookingService()->create( bookingCustomer( [
            'service'    => Service::query()->firstOrFail(),
            'provider'   => $provider,
            'start_time' => bookingStart( '13:00' ),
        ] ) );

        expect( $this->get( providerFeedUrl( $token ) )->headers->get( 'ETag' ) )->not->toBe( $before );
    } );

    it( 'moves the tag when the only booking is cancelled', function (): void {
        [ $booking, $provider, $token ] = feedBooking();

        $before = $this->get( providerFeedUrl( $token ) )->headers->get( 'ETag' );

        bookingService()->cancel( $booking, BookingActor::Customer );

        $response = $this->get( providerFeedUrl( $token ) )->assertOk();

        // The whole point of counting rows alongside the newest timestamp: a
        // cancellation removes the event, and a stamp built from the maximum
        // alone would read exactly as it did before the booking went away.
        expect( $response->headers->get( 'ETag' ) )->not->toBe( $before )
            ->and( unfolded( $response->getContent() ) )->not->toContain( 'BEGIN:VEVENT' );
    } );

    it( 'moves the tag when the provider themselves is renamed', function (): void {
        [ , $provider, $token ] = feedBooking();

        $before = $this->get( providerFeedUrl( $token ) )->headers->get( 'ETag' );

        $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:05:00', 'UTC' ) );
        $provider->update( [ 'name' => 'Dr. Renamed' ] );

        $response = $this->get( providerFeedUrl( $token ) )->assertOk();

        expect( $response->headers->get( 'ETag' ) )->not->toBe( $before )
            ->and( unfolded( $response->getContent() ) )->toContain( 'X-WR-CALNAME:Dr. Renamed' );
    } );

    it( 'names the customer, which only an unguessable address makes safe', function (): void {
        [ , $provider, $token ] = feedBooking();

        $body = unfolded( $this->get( providerFeedUrl( $token ) )->assertOk()->getContent() );

        // The whole reason the route stopped being addressed by the slug: a
        // provider looking at their week needs to know who they are seeing, and
        // a slug is published by the public providers endpoint.
        expect( $body )->toContain( 'Sam Rivera' )
            ->and( $body )->toContain( 'sam@example.test' );
    } );

    it( 'marks a booking nobody has approved as tentative', function (): void {
        config()->set( 'artisanpack.bookings.auto_confirm', false );

        [ , $provider, $token ] = feedBooking();

        // The hour is spoken for and it is not yet a commitment, which is what a
        // provider wants to see in their week view.
        expect( unfolded( $this->get( providerFeedUrl( $token ) )->getContent() ) )->toContain( 'STATUS:TENTATIVE' );
    } );

    it( 'marks a confirmed booking confirmed', function (): void {
        [ , $provider, $token ] = feedBooking();

        expect( unfolded( $this->get( providerFeedUrl( $token ) )->getContent() ) )->toContain( 'STATUS:CONFIRMED' );
    } );

    it( 'leaves out bookings outside the window it publishes', function (): void {
        config()->set( 'artisanpack.bookings.public.ical.future_days', 1 );

        [ $booking, $provider, $token ] = feedBooking();

        $body = unfolded( $this->get( providerFeedUrl( $token ) )->assertOk()->getContent() );

        expect( $body )->not->toContain( 'UID:' . $booking->booking_number . '@' )
            ->and( $body )->toContain( 'BEGIN:VCALENDAR' );
    } );

    it( 'leaves out another provider\'s bookings', function (): void {
        [ $service, $providers ] = bookableService( 2 );
        [ $first, $second ]      = $providers;

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'provider'   => $first,
            'start_time' => bookingStart(),
        ] ) );

        expect( unfolded( $this->get( providerFeedUrl( issueFeedToken( $second ) ) )->getContent() ) )
            ->not->toContain( 'UID:' . $booking->booking_number . '@' );
    } );

    it( 'does not answer for a provider belonging to another site', function (): void {
        [ , $provider, $token ] = feedBooking();

        $provider->newQueryWithoutScopes()->whereKey( $provider->getKey() )->toBase()->update( [ 'site_id' => 1 ] );

        scopeToSite( 2 );

        $this->get( providerFeedUrl( $token ) )->assertNotFound();
    } );

    it( 'gives every token that addresses no feed the same refusal', function ( string $scenario ): void {
        [ , $provider, $token ] = feedBooking();
        $tokens                 = app( IcalTokenService::class );

        $presented = match ( $scenario ) {
            'unknown'     => str_repeat( 'a', 64 ),
            'hash'        => $tokens->hash( $token ),
            'deactivated' => tap( $token, static function () use ( $provider ): void {
                $provider->newQueryWithoutScopes()->whereKey( $provider->getKey() )->toBase()->update( [
                    'is_active' => false,
                ] );
            } ),
            'revoked'     => tap( $token, static function () use ( $tokens, $provider ): void {
                $tokens->revokeFor( $provider );
            } ),
            'rotated'     => tap( $token, static function () use ( $provider ): void {
                issueFeedToken( $provider );
            } ),
        };

        $response = $this->get( providerFeedUrl( $presented ) );

        $response->assertNotFound();

        // One answer for all of them. Anything that told an unknown token apart
        // from a revoked one would confirm which guesses were closer, and the
        // refusal must not name the model class the way firstOrFail() would.
        expect( (string) $response->getContent() )->not->toContain( 'ArtisanPackUI' );
    } )->with( [
        'an unknown token'        => 'unknown',
        'the hash of a real one'  => 'hash',
        'a deactivated provider'  => 'deactivated',
        'a revoked feed'          => 'revoked',
        'a rotated token'         => 'rotated',
    ] );

    it( 'refuses a token that is not the right shape before it reaches the database', function ( string $token ): void {
        feedBooking();

        // The route pattern, which is what keeps a scanner walking the path down
        // to a regular expression rather than an indexed lookup per guess.
        $this->get( '/bookings/ical/providers/' . rawurlencode( $token ) . '.ics' )->assertNotFound();
    } )->with( [
        'too short'      => 'abc123',
        'not hex at all' => 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz',
        'upper case'     => 'A0000000000000000000000000000000000000000000000000000000000000AA',
    ] );

    it( 'serves nothing for a provider who has never been issued a token', function (): void {
        [ $service ] = bookableService();

        $provider = ServiceProvider::query()->firstOrFail();

        expect( $provider->ical_token_hash )->toBeNull()
            ->and( $service->is_active )->toBeTrue();

        // A provider has no feed until somebody asks for one, so there is no URL
        // in existence that answers for them.
        $this->get( providerFeedUrl( str_repeat( 'b', 64 ) ) )->assertNotFound();
    } );

    it( 'bounds how often one address may poll the feed', function (): void {
        config()->set( 'artisanpack.bookings.public.ical.max_age', 300 );
        config()->set( 'artisanpack.bookings.public.rate_limits.ical', 2 );

        [ , $provider, $token ] = feedBooking();

        $this->get( providerFeedUrl( $token ) )->assertOk();
        $this->get( providerFeedUrl( $token ) )->assertOk();
        $this->get( providerFeedUrl( $token ) )->assertStatus( Response::HTTP_TOO_MANY_REQUESTS );
    } );
} );

describe( 'GET customer feed', function (): void {
    it( 'serves the booking the token stands for', function (): void {
        [ $booking ] = feedBooking();

        $token = app( ManageTokenService::class )->issueFor( $booking );

        $response = $this->get( customerFeedUrl( $token ) )->assertOk();

        expect( $response->headers->get( 'Content-Type' ) )->toBe( 'text/calendar; charset=utf-8' );
        expect( unfolded( $response->getContent() ) )->toContain( 'UID:' . $booking->booking_number . '@' )
            ->and( unfolded( $response->getContent() ) )->toContain( 'DTSTART:20260601T150000Z' );
    } );

    it( 'carries only the one booking the token manages', function (): void {
        [ $booking, $provider, $token ] = feedBooking();

        $other = bookingService()->create( bookingCustomer( [
            'service'    => Service::query()->firstOrFail(),
            'provider'   => $provider,
            'start_time' => bookingStart( '13:00' ),
        ] ) );

        $token = app( ManageTokenService::class )->issueFor( $booking );

        expect( unfolded( $this->get( customerFeedUrl( $token ) )->getContent() ) )
            ->not->toContain( 'UID:' . $other->booking_number . '@' );
    } );

    it( 'answers a conditional fetch with 304', function (): void {
        [ $booking ] = feedBooking();

        $token = app( ManageTokenService::class )->issueFor( $booking );
        $etag  = $this->get( customerFeedUrl( $token ) )->assertOk()->headers->get( 'ETag' );

        $this->get( customerFeedUrl( $token ), [ 'If-None-Match' => $etag ] )
            ->assertStatus( Response::HTTP_NOT_MODIFIED );
    } );

    it( 'moves the tag when the appointment moves', function (): void {
        [ $booking ] = feedBooking();

        $token  = app( ManageTokenService::class )->issueFor( $booking );
        $before = $this->get( customerFeedUrl( $token ) )->headers->get( 'ETag' );

        bookingService()->reschedule( $booking, bookingStart( '13:00' ), BookingActor::Customer );

        $response = $this->get( customerFeedUrl( $token ) )->assertOk();

        expect( $response->headers->get( 'ETag' ) )->not->toBe( $before )
            ->and( unfolded( $response->getContent() ) )->toContain( 'DTSTART:20260601T180000Z' );
    } );

    it( 'keeps the same UID across a reschedule so the client moves the event', function (): void {
        [ $booking ] = feedBooking();

        $token = app( ManageTokenService::class )->issueFor( $booking );

        bookingService()->reschedule( $booking, bookingStart( '13:00' ), BookingActor::Customer );

        expect( unfolded( $this->get( customerFeedUrl( $token ) )->getContent() ) )
            ->toContain( 'UID:' . $booking->booking_number . '@' );
    } );

    it( 'gives the same refusal to every token that does not manage a booking', function ( string $token ): void {
        feedBooking();

        $this->get( customerFeedUrl( $token ) )->assertNotFound();
    } )->with( [
        'an unknown token' => str_repeat( 'a', 64 ),
        'a short token'    => 'abc123',
        'not hex at all'   => str_repeat( 'z', 64 ),
    ] );

    it( 'refuses a token whose hash was presented instead of the token', function (): void {
        [ $booking ] = feedBooking();

        app( ManageTokenService::class )->issueFor( $booking );

        $this->get( customerFeedUrl( (string) $booking->manage_token_hash ) )->assertNotFound();
    } );

    it( 'stops answering for a token that has been reissued', function (): void {
        [ $booking ] = feedBooking();

        $tokens = app( ManageTokenService::class );
        $token  = $tokens->issueFor( $booking );

        $reissued = $tokens->issueFor( $booking );

        $this->get( customerFeedUrl( $token ) )->assertNotFound();
        $this->get( customerFeedUrl( $reissued ) )->assertOk();
    } );
} );

describe( 'rate limit buckets', function (): void {
    it( 'knows the feed buckets by name', function ( string $bucket ): void {
        $limiter = app( RateLimitBookings::class );

        $response = $limiter->handle(
            Request::create( '/bookings/ical/providers/' . str_repeat( 'a', 64 ) . '.ics' ),
            static fn (): Response => new Response( '' ),
            $bucket,
        );

        expect( $response->headers->get( 'X-RateLimit-Limit' ) )->toBe( '30' );
    } )->with( [ 'ical', 'ical_token' ] );

    it( 'counts the provider feed per token as well as per address', function (): void {
        config()->set( 'artisanpack.bookings.public.rate_limits.ical_token', 1 );

        [ , $first, $firstToken ] = feedBooking();

        $second = ServiceProvider::factory()->inTimezone( 'America/Chicago' )->create();

        $this->get( providerFeedUrl( $firstToken ) )->assertOk();
        $this->get( providerFeedUrl( $firstToken ) )->assertStatus( Response::HTTP_TOO_MANY_REQUESTS );

        // The per-token bucket has to be per token: one leaked URL being fetched
        // from everywhere must not take everybody else's feed down with it.
        $this->get( providerFeedUrl( issueFeedToken( $second ) ) )->assertOk();
    } );
} );
