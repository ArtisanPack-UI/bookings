<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Events\BookingCancelled;
use ArtisanPackUI\Bookings\Events\BookingRescheduled;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // The same Monday every booking helper works around, far enough ahead of the
    // 1 June diary that the twenty-four hour cancellation window is wide open.
    $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );
} );

afterEach( function (): void {
    removeAllActions( 'ap.bookings.cancelled' );
    removeAllActions( 'ap.bookings.rescheduled' );
} );

/**
 * Books an appointment and hands back the link its customer would be emailed.
 *
 * @param  string  $start  The provider-local clock face to book.
 *
 * @return array{0: Booking, 1: string} The booking and its plain manage token.
 */
function managedBooking( string $start = '10:00' ): array
{
    [ $service ] = bookableService();

    $booking = bookingService()->create( bookingCustomer( [
        'service'    => $service,
        'start_time' => bookingStart( $start ),
    ] ) );

    return [ $booking, app( ManageTokenService::class )->issueFor( $booking ) ];
}

/**
 * Builds the manage URL for a token.
 *
 * @param  string  $token  The plain manage token.
 * @param  string  $action  The action to append, if any.
 *
 * @return string The URL.
 */
function manageUrl( string $token, string $action = '' ): string
{
    return '/api/bookings/manage/' . $token . ( '' === $action ? '' : '/' . $action );
}

describe( 'GET manage', function (): void {
    it( 'shows the booking the token was minted for', function (): void {
        [ $booking, $token ] = managedBooking();

        $this->getJson( manageUrl( $token ) )
            ->assertOk()
            ->assertJsonPath( 'data.id', (int) $booking->getKey() )
            ->assertJsonPath( 'data.status', BookingStatus::Confirmed->value )
            ->assertJsonPath( 'data.customer_email', 'sam@example.test' )
            ->assertJsonPath( 'meta.can_cancel', true )
            ->assertJsonPath( 'meta.can_reschedule', true )
            // Twenty-four hours before the 15:00 UTC appointment.
            ->assertJsonPath( 'meta.changes_allowed_until', '2026-05-31T15:00:00+00:00' );
    } );

    it( 'hands back no part of the credential it was given', function (): void {
        [ , $token ] = managedBooking();

        $body = $this->getJson( manageUrl( $token ) )->assertOk()->json( 'data' );

        expect( $body )->not->toHaveKey( 'manage_token' )
            ->and( $body )->not->toHaveKey( 'manage_token_hash' );
    } );

    it( 'says the same thing to every token that does not manage a booking', function ( string $token ): void {
        managedBooking();

        $body = $this->getJson( manageUrl( $token ) )->assertNotFound()->json();

        expect( $body['message'] ?? '' )->not->toContain( 'ArtisanPackUI' )
            ->and( $body['message'] ?? '' )->toBe(
                'That booking link is no longer valid. Please check your confirmation email.',
            );
    } )->with( [
        'an unknown token'       => str_repeat( 'a', 64 ),
        'a short token'          => 'abc123',
        'the hash of a real one' => str_repeat( 'A', 64 ),
        'a path traversal'       => 'not-a-token',
    ] );

    it( 'refuses a token whose hash was presented instead of the token', function (): void {
        [ $booking] = managedBooking();

        // What a leaked database row hands an attacker. It has to be useless.
        $this->getJson( manageUrl( (string) $booking->manage_token_hash ) )->assertNotFound();
    } );

    it( 'stops answering for a token that has been reissued', function (): void {
        [ $booking, $token ] = managedBooking();

        $reissued = app( ManageTokenService::class )->issueFor( $booking );

        $this->getJson( manageUrl( $token ) )->assertNotFound();
        $this->getJson( manageUrl( $reissued ) )->assertOk();
    } );

    it( 'does not answer for a booking belonging to another site', function (): void {
        [ $booking, $token ] = managedBooking();

        $booking->newQueryWithoutScopes()->whereKey( $booking->getKey() )->toBase()->update( [ 'site_id' => 1 ] );

        scopeToSite( 2 );

        $this->getJson( manageUrl( $token ) )->assertNotFound();
    } );

    it( 'reports a cancelled booking as beyond changing', function (): void {
        [ $booking, $token ] = managedBooking();

        bookingService()->cancel( $booking, BookingActor::Customer );

        $this->getJson( manageUrl( $token ) )
            ->assertOk()
            ->assertJsonPath( 'data.status', BookingStatus::Cancelled->value )
            ->assertJsonPath( 'meta.can_cancel', false )
            ->assertJsonPath( 'meta.can_reschedule', false );
    } );

    it( 'reports an appointment inside the notice period as beyond changing', function (): void {
        [ , $token ] = managedBooking();

        $this->travelTo( CarbonImmutable::parse( '2026-06-01 12:00:00', 'UTC' ) );

        $this->getJson( manageUrl( $token ) )
            ->assertOk()
            ->assertJsonPath( 'meta.can_cancel', false )
            ->assertJsonPath( 'meta.can_reschedule', false );
    } );
} );

describe( 'POST cancel', function (): void {
    it( 'cancels the booking on the customer\'s behalf', function (): void {
        Event::fake( [ BookingCancelled::class ] );

        [ $booking, $token ] = managedBooking();

        $this->postJson( manageUrl( $token, 'cancel' ), [ 'reason' => 'Something came up' ] )
            ->assertOk()
            ->assertJsonPath( 'data.status', BookingStatus::Cancelled->value )
            ->assertJsonPath( 'meta.can_cancel', false );

        expect( $booking->fresh()->status )->toBe( BookingStatus::Cancelled );

        Event::assertDispatched(
            BookingCancelled::class,
            static fn ( BookingCancelled $event ): bool => BookingActor::Customer === $event->actor
                && 'Something came up' === $event->reason,
        );
    } );

    it( 'runs a consumer\'s ap.bookings.cancelled callback', function (): void {
        $seen = [];

        addAction( 'ap.bookings.cancelled', function ( Booking $booking ) use ( &$seen ): void {
            $seen[] = (int) $booking->getKey();
        } );

        [ $booking, $token ] = managedBooking();

        $this->postJson( manageUrl( $token, 'cancel' ) )->assertOk();

        expect( $seen )->toBe( [ (int) $booking->getKey() ] );
    } );

    it( 'cleans the reason before it travels anywhere', function (): void {
        Event::fake( [ BookingCancelled::class ] );

        [ , $token ] = managedBooking();

        $this->postJson( manageUrl( $token, 'cancel' ), [ 'reason' => 'Ill <script>alert(1)</script>' ] )
            ->assertOk();

        Event::assertDispatched(
            BookingCancelled::class,
            static fn ( BookingCancelled $event ): bool => 'Ill alert(1)' === $event->reason,
        );
    } );

    it( 'refuses a booking that has already been cancelled', function (): void {
        [ $booking, $token ] = managedBooking();

        bookingService()->cancel( $booking, BookingActor::Customer );

        $this->postJson( manageUrl( $token, 'cancel' ) )->assertConflict();
    } );

    it( 'refuses when the installation does not offer self-serve cancellation', function (): void {
        config()->set( 'artisanpack.bookings.cancellation.allowed', false );

        [ $booking, $token ] = managedBooking();

        $this->postJson( manageUrl( $token, 'cancel' ) )->assertForbidden();

        expect( $booking->fresh()->status )->toBe( BookingStatus::Confirmed );
    } );

    it( 'refuses once the appointment is inside its notice period', function (): void {
        [ $booking, $token ] = managedBooking();

        // Three hours before a 15:00 UTC appointment, against a day's notice.
        $this->travelTo( CarbonImmutable::parse( '2026-06-01 12:00:00', 'UTC' ) );

        $this->postJson( manageUrl( $token, 'cancel' ) )->assertUnprocessable();

        expect( $booking->fresh()->status )->toBe( BookingStatus::Confirmed );
    } );

    it( 'lets a customer cancel up to the start when no notice is required', function (): void {
        config()->set( 'artisanpack.bookings.cancellation.min_advance_minutes', 0 );

        [ $booking, $token ] = managedBooking();

        $this->travelTo( CarbonImmutable::parse( '2026-06-01 14:59:00', 'UTC' ) );

        $this->postJson( manageUrl( $token, 'cancel' ) )->assertOk();

        expect( $booking->fresh()->status )->toBe( BookingStatus::Cancelled );
    } );

    it( 'refuses a token that manages nothing', function (): void {
        managedBooking();

        $this->postJson( manageUrl( str_repeat( 'b', 64 ), 'cancel' ) )->assertNotFound();
    } );

    it( 'refuses a reason longer than the column will take', function (): void {
        [ , $token ] = managedBooking();

        $this->postJson( manageUrl( $token, 'cancel' ), [ 'reason' => str_repeat( 'x', 1001 ) ] )
            ->assertUnprocessable()
            ->assertJsonValidationErrors( 'reason' );
    } );
} );

describe( 'POST reschedule', function (): void {
    it( 'moves the booking to another free slot', function (): void {
        Event::fake( [ BookingRescheduled::class ] );

        [ $booking, $token ] = managedBooking();

        $this->postJson( manageUrl( $token, 'reschedule' ), [
            'start_time' => bookingStart( '14:00' )->toIso8601String(),
        ] )
            ->assertOk()
            ->assertJsonPath( 'data.start_time', bookingStart( '14:00' )->toIso8601String() )
            ->assertJsonPath( 'data.end_time', bookingStart( '15:00' )->toIso8601String() );

        expect( $booking->fresh()->start_time->equalTo( bookingStart( '14:00' ) ) )->toBeTrue();

        Event::assertDispatched(
            BookingRescheduled::class,
            static fn ( BookingRescheduled $event ): bool => BookingActor::Customer === $event->actor,
        );
    } );

    it( 'runs a consumer\'s ap.bookings.rescheduled callback', function (): void {
        $seen = [];

        addAction( 'ap.bookings.rescheduled', function () use ( &$seen ): void {
            $seen[] = true;
        } );

        [ , $token ] = managedBooking();

        $this->postJson( manageUrl( $token, 'reschedule' ), [
            'start_time' => bookingStart( '14:00' )->toIso8601String(),
        ] )->assertOk();

        expect( $seen )->toHaveCount( 1 );
    } );

    it( 'refuses a time nobody is available for', function (): void {
        [ $booking, $token ] = managedBooking();

        // Three in the morning, against a nine-to-five diary.
        $this->postJson( manageUrl( $token, 'reschedule' ), [
            'start_time' => bookingStart( '03:00' )->toIso8601String(),
        ] )->assertUnprocessable();

        expect( $booking->fresh()->start_time->equalTo( bookingStart( '10:00' ) ) )->toBeTrue();
    } );

    it( 'refuses a slot another customer is already holding', function (): void {
        [ $service ] = bookableService();

        $mine = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '10:00' ),
        ] ) );

        bookingService()->create( bookingCustomer( [
            'service'        => $service,
            'customer_email' => 'alex@example.test',
            'start_time'     => bookingStart( '11:00' ),
        ] ) );

        $token = app( ManageTokenService::class )->issueFor( $mine );

        $this->postJson( manageUrl( $token, 'reschedule' ), [
            'start_time' => bookingStart( '11:00' )->toIso8601String(),
        ] )->assertUnprocessable();
    } );

    it( 'refuses the time the booking is already at', function (): void {
        [ , $token ] = managedBooking();

        $this->postJson( manageUrl( $token, 'reschedule' ), [
            'start_time' => bookingStart( '10:00' )->toIso8601String(),
        ] )->assertUnprocessable();
    } );

    it( 'refuses a time outside the window the installation books in', function ( string $start ): void {
        [ , $token ] = managedBooking();

        $this->postJson( manageUrl( $token, 'reschedule' ), [
            'start_time' => CarbonImmutable::parse( $start, 'UTC' )->toIso8601String(),
        ] )
            ->assertUnprocessable()
            ->assertJsonValidationErrors( 'start_time' );
    } )->with( [
        'already gone'    => '2026-05-04 15:00:00',
        'inside the hour' => '2026-05-25 12:30:00',
        'years ahead'     => '2029-06-01 15:00:00',
    ] );

    it( 'requires a time at all', function (): void {
        [ , $token ] = managedBooking();

        $this->postJson( manageUrl( $token, 'reschedule' ), [] )
            ->assertUnprocessable()
            ->assertJsonValidationErrors( 'start_time' );
    } );

    it( 'refuses once the appointment is inside its notice period', function (): void {
        [ , $token ] = managedBooking();

        $this->travelTo( CarbonImmutable::parse( '2026-06-01 12:00:00', 'UTC' ) );

        $this->postJson( manageUrl( $token, 'reschedule' ), [
            'start_time' => bookingStart( '16:00' )->toIso8601String(),
        ] )->assertUnprocessable();
    } );

    it( 'refuses a booking that is no longer holding a slot', function (): void {
        [ $booking, $token ] = managedBooking();

        bookingService()->cancel( $booking, BookingActor::Customer );

        $this->postJson( manageUrl( $token, 'reschedule' ), [
            'start_time' => bookingStart( '14:00' )->toIso8601String(),
        ] )->assertConflict();
    } );

    it( 'stops taking new time for a service that has been switched off', function (): void {
        [ $booking, $token ] = managedBooking();

        $booking->service->forceFill( [ 'is_active' => false ] )->save();

        $this->postJson( manageUrl( $token, 'reschedule' ), [
            'start_time' => bookingStart( '14:00' )->toIso8601String(),
        ] )->assertNotFound();

        // The link still reads and still cancels, which is the point of the
        // check being on this action rather than on the token.
        $this->getJson( manageUrl( $token ) )->assertOk();
        $this->postJson( manageUrl( $token, 'cancel' ) )->assertOk();
    } );
} );

describe( 'rate limiting', function (): void {
    it( 'refuses the twenty-first read from one address in a minute', function (): void {
        [ , $token ] = managedBooking();

        config()->set( 'artisanpack.bookings.public.rate_limits.manage_token', 1000 );

        for ( $attempt = 0; $attempt < 20; $attempt++ ) {
            $this->getJson( manageUrl( $token ) )->assertOk();
        }

        $this->getJson( manageUrl( $token ) )
            ->assertStatus( 429 )
            ->assertHeader( 'Retry-After' );
    } );

    it( 'counts reads against the token as well as the address', function (): void {
        [ , $first ]  = managedBooking( '10:00' );
        [ , $second ] = managedBooking( '14:00' );

        config()->set( 'artisanpack.bookings.public.rate_limits.manage_token', 2 );

        $this->getJson( manageUrl( $first ) )->assertOk();
        $this->getJson( manageUrl( $first ) )->assertOk();
        $this->getJson( manageUrl( $first ) )->assertStatus( 429 );

        // The per-address bucket is nowhere near full, so a different link from
        // the same machine still works — which is the whole reason the token has
        // a bucket of its own.
        $this->getJson( manageUrl( $second ) )->assertOk();
    } );

    it( 'reports the tighter of the two buckets a read passes through', function (): void {
        [ , $token ] = managedBooking();

        config()->set( 'artisanpack.bookings.public.rate_limits.manage_get', 20 );
        config()->set( 'artisanpack.bookings.public.rate_limits.manage_token', 60 );

        $this->getJson( manageUrl( $token ) )
            ->assertOk()
            ->assertHeader( 'X-RateLimit-Limit', '20' )
            ->assertHeader( 'X-RateLimit-Remaining', '19' );
    } );

    it( 'bounds writes the way it bounds a public booking', function (): void {
        [ , $token ] = managedBooking();

        config()->set( 'artisanpack.bookings.public.rate_limits.post', 2 );

        $this->postJson( manageUrl( $token, 'reschedule' ), [] )->assertUnprocessable();
        $this->postJson( manageUrl( $token, 'reschedule' ), [] )->assertUnprocessable();
        $this->postJson( manageUrl( $token, 'reschedule' ), [] )->assertStatus( 429 );
    } );

    it( 'counts a guess before it looks one up', function (): void {
        config()->set( 'artisanpack.bookings.public.rate_limits.manage_get', 3 );

        // Nothing here resolves to a booking, and the limiter still stops the
        // fourth attempt — so a scanner pays for the cache read rather than for
        // an indexed lookup per guess.
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            $this->getJson( manageUrl( str_repeat( (string) $attempt, 64 ) ) )->assertNotFound();
        }

        $this->getJson( manageUrl( str_repeat( '9', 64 ) ) )->assertStatus( 429 );
    } );
} );
