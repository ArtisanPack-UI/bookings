<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Http\Controllers\Public\IcalFeedController;
use ArtisanPackUI\Bookings\Http\Middleware\RateLimitBookings;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\IcalFeedService;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // The Monday every booking helper works around, far enough ahead of the
    // 1 June diary that nothing the feed serves is in the past.
    $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );
} );

/**
 * Books an appointment and hands back the provider it landed on.
 *
 * @param  string  $start  The provider-local clock face to book.
 *
 * @return array{0: Booking, 1: ServiceProvider} The booking and its provider.
 */
function feedBooking( string $start = '10:00' ): array
{
    [ $service ] = bookableService();

    $booking = bookingService()->create( bookingCustomer( [
        'service'    => $service,
        'start_time' => bookingStart( $start ),
    ] ) );

    return [ $booking, $booking->provider ];
}

/**
 * Builds the URL a provider's calendar client would be pointed at.
 *
 * @param  ServiceProvider|string  $provider  The provider, or their slug.
 *
 * @return string The URL.
 */
function providerFeedUrl( ServiceProvider|string $provider ): string
{
    return '/bookings/ical/providers/'
        . ( $provider instanceof ServiceProvider ? $provider->slug : $provider )
        . '.ics';
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
        [ $booking, $provider ] = feedBooking();

        $response = $this->get( providerFeedUrl( $provider ) )->assertOk();

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
        [ , $provider ] = feedBooking();

        $disposition = $this->get( providerFeedUrl( $provider ) )
            ->assertOk()
            ->headers->get( 'Content-Disposition' );

        expect( $disposition )->toBe( 'inline; filename="' . $provider->slug . '.ics"' );
    } );

    it( 'refuses a slug that would write its own headers', function ( string $slug ): void {
        feedBooking();

        // The route pattern is the first of two guards: a slug is written by
        // staff rather than derived, and it ends up inside a quoted header value
        // where a `"` closes the quoting early and a newline ends the header.
        $this->get( '/bookings/ical/providers/' . rawurlencode( $slug ) . '.ics' )->assertNotFound();
    } )->with( [
        'a quote'           => 'a"; x=1',
        'a carriage return' => "a\r\nX-Injected: 1",
        'a space'           => 'a b',
    ] );

    it( 'strips anything a filename has no business carrying', function (): void {
        // The second guard, reached directly because the first one means nothing
        // hostile can arrive through the route. Both exist because they fail
        // differently: the pattern is per-route and easy to widen by accident.
        $sanitise   = new ReflectionMethod( IcalFeedController::class, 'filename' );
        $controller = app( IcalFeedController::class );

        expect( $sanitise->invoke( $controller, 'a"; x=1' ) )->toBe( 'ax1' )
            ->and( $sanitise->invoke( $controller, "sam\r\nX-Injected: 1" ) )->toBe( 'samX-Injected1' )
            ->and( $sanitise->invoke( $controller, 'clean-slug_1.0' ) )->toBe( 'clean-slug_1.0' )
            ->and( $sanitise->invoke( $controller, '///' ) )->toBe( 'calendar' );
    } );

    it( 'tells a client how long it may hold the feed', function (): void {
        [ , $provider ] = feedBooking();

        $response = $this->get( providerFeedUrl( $provider ) )->assertOk();

        expect( $response->headers->get( 'Cache-Control' ) )->toContain( 'private' )
            ->and( $response->headers->get( 'Cache-Control' ) )->toContain( 'max-age=300' )
            ->and( $response->headers->get( 'ETag' ) )->toMatch( '/^"[0-9a-f]{40}"$/' );
    } );

    it( 'answers a conditional fetch with 304 and no body', function (): void {
        [ , $provider ] = feedBooking();

        $etag = $this->get( providerFeedUrl( $provider ) )->assertOk()->headers->get( 'ETag' );

        $response = $this->get( providerFeedUrl( $provider ), [ 'If-None-Match' => $etag ] );

        $response->assertStatus( Response::HTTP_NOT_MODIFIED );

        // The caching headers have to come back with it, or the client is left
        // holding a copy it may not reuse and the next poll is a full fetch.
        expect( unfolded( $response->getContent() ) )->toBe( '' )
            ->and( $response->headers->get( 'ETag' ) )->toBe( $etag )
            ->and( $response->headers->get( 'Cache-Control' ) )->toContain( 'max-age=300' );
    } );

    it( 'matches a tag a proxy has weakened, or sent alongside others', function ( string $template ): void {
        [ , $provider ] = feedBooking();

        $etag = (string) $this->get( providerFeedUrl( $provider ) )->assertOk()->headers->get( 'ETag' );

        $this->get( providerFeedUrl( $provider ), [ 'If-None-Match' => str_replace( '{etag}', $etag, $template ) ] )
            ->assertStatus( Response::HTTP_NOT_MODIFIED );
    } )->with( [
        'weakened'      => 'W/{etag}',
        'one of a list' => '"0000000000000000000000000000000000000000", {etag}',
        'a wildcard'    => '*',
    ] );

    it( 'serves the feed again when a tag no longer matches', function (): void {
        [ , $provider ] = feedBooking();

        $this->get( providerFeedUrl( $provider ), [ 'If-None-Match' => '"not-the-current-one"' ] )
            ->assertOk();
    } );

    it( 'moves the tag when a booking is added', function (): void {
        [ , $provider ] = feedBooking();

        $before = $this->get( providerFeedUrl( $provider ) )->headers->get( 'ETag' );

        bookingService()->create( bookingCustomer( [
            'service'    => Service::query()->firstOrFail(),
            'provider'   => $provider,
            'start_time' => bookingStart( '13:00' ),
        ] ) );

        expect( $this->get( providerFeedUrl( $provider ) )->headers->get( 'ETag' ) )->not->toBe( $before );
    } );

    it( 'moves the tag when the only booking is cancelled', function (): void {
        [ $booking, $provider ] = feedBooking();

        $before = $this->get( providerFeedUrl( $provider ) )->headers->get( 'ETag' );

        bookingService()->cancel( $booking, BookingActor::Customer );

        $response = $this->get( providerFeedUrl( $provider ) )->assertOk();

        // The whole point of counting rows alongside the newest timestamp: a
        // cancellation removes the event, and a stamp built from the maximum
        // alone would read exactly as it did before the booking went away.
        expect( $response->headers->get( 'ETag' ) )->not->toBe( $before )
            ->and( unfolded( $response->getContent() ) )->not->toContain( 'BEGIN:VEVENT' );
    } );

    it( 'moves the tag when the provider themselves is renamed', function (): void {
        [ , $provider ] = feedBooking();

        $before = $this->get( providerFeedUrl( $provider ) )->headers->get( 'ETag' );

        $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:05:00', 'UTC' ) );
        $provider->update( [ 'name' => 'Dr. Renamed' ] );

        $response = $this->get( providerFeedUrl( $provider ) )->assertOk();

        expect( $response->headers->get( 'ETag' ) )->not->toBe( $before )
            ->and( unfolded( $response->getContent() ) )->toContain( 'X-WR-CALNAME:Dr. Renamed' );
    } );

    it( 'keeps the customer out of a feed anybody can address', function (): void {
        [ , $provider ] = feedBooking();

        $body = unfolded( $this->get( providerFeedUrl( $provider ) )->assertOk()->getContent() );

        // The slug is published by the public providers endpoint, so this URL is
        // guessable by construction — a customer directory behind it would be
        // available to whoever asks.
        expect( $body )->not->toContain( 'Sam Rivera' )
            ->and( $body )->not->toContain( 'sam@example.test' )
            ->and( $body )->toContain( 'BEGIN:VEVENT' );
    } );

    it( 'names the customer when the installation asks for it outright', function (): void {
        config()->set( 'artisanpack.bookings.public.ical.provider_feed_details', 'full' );

        [ , $provider ] = feedBooking();

        $body = unfolded( $this->get( providerFeedUrl( $provider ) )->assertOk()->getContent() );

        expect( $body )->toContain( 'Sam Rivera' )
            ->and( $body )->toContain( 'sam@example.test' );
    } );

    it( 'marks a booking nobody has approved as tentative', function (): void {
        config()->set( 'artisanpack.bookings.auto_confirm', false );

        [ , $provider ] = feedBooking();

        // The hour is spoken for and it is not yet a commitment, which is what a
        // provider wants to see in their week view.
        expect( unfolded( $this->get( providerFeedUrl( $provider ) )->getContent() ) )->toContain( 'STATUS:TENTATIVE' );
    } );

    it( 'marks a confirmed booking confirmed', function (): void {
        [ , $provider ] = feedBooking();

        expect( unfolded( $this->get( providerFeedUrl( $provider ) )->getContent() ) )->toContain( 'STATUS:CONFIRMED' );
    } );

    it( 'leaves out bookings outside the window it publishes', function (): void {
        config()->set( 'artisanpack.bookings.public.ical.future_days', 1 );

        [ $booking, $provider ] = feedBooking();

        $body = unfolded( $this->get( providerFeedUrl( $provider ) )->assertOk()->getContent() );

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

        expect( unfolded( $this->get( providerFeedUrl( $second ) )->getContent() ) )
            ->not->toContain( 'UID:' . $booking->booking_number . '@' );
    } );

    it( 'does not answer for a provider belonging to another site', function (): void {
        [ , $provider ] = feedBooking();

        $provider->newQueryWithoutScopes()->whereKey( $provider->getKey() )->toBase()->update( [ 'site_id' => 1 ] );

        scopeToSite( 2 );

        $this->get( providerFeedUrl( $provider ) )->assertNotFound();
    } );

    it( 'gives up on a slug no active provider answers to', function ( string $slug ): void {
        ServiceProvider::factory()->create( [ 'slug' => 'retired', 'is_active' => false ] );

        $response = $this->get( providerFeedUrl( $slug ) );

        $response->assertNotFound();

        // The refusal must not name the model class the way firstOrFail() would.
        expect( (string) $response->getContent() )->not->toContain( 'ArtisanPackUI' );
    } )->with( [
        'an unknown slug'    => 'nobody-here',
        'a retired provider' => 'retired',
    ] );

    it( 'bounds how often one address may poll the feed', function (): void {
        config()->set( 'artisanpack.bookings.public.ical.max_age', 300 );
        config()->set( 'artisanpack.bookings.public.rate_limits.ical', 2 );

        [ , $provider ] = feedBooking();

        $this->get( providerFeedUrl( $provider ) )->assertOk();
        $this->get( providerFeedUrl( $provider ) )->assertOk();
        $this->get( providerFeedUrl( $provider ) )->assertStatus( Response::HTTP_TOO_MANY_REQUESTS );
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
        [ $booking, $provider ] = feedBooking();

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
    it( 'knows the ical bucket by name', function (): void {
        $limiter = app( RateLimitBookings::class );

        $response = $limiter->handle(
            Request::create( '/bookings/ical/providers/anybody.ics' ),
            static fn (): Response => new Response( '' ),
            'ical',
        );

        expect( $response->headers->get( 'X-RateLimit-Limit' ) )->toBe( '30' );
    } );
} );
