<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Events\BookingCompleted;
use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    Carbon::setTestNow( '2026-06-01 12:00:00' );
} );

afterEach( function (): void {
    Carbon::setTestNow();

    // Pest runs every file in one process, so a zone left set by the
    // timezone cases would follow every later test in the suite.
    date_default_timezone_set( 'UTC' );

    removeAllActions( 'ap.bookings.completed' );
} );

/**
 * Builds a confirmed booking that ran between two offsets from the fixed now.
 *
 * @param  string  $start  A relative time the booking started at.
 * @param  string  $end  A relative time it ended at.
 *
 * @return Booking The booking.
 */
function pastBooking( string $start = '-2 hours', string $end = '-1 hour' ): Booking
{
    return Booking::factory()->confirmed()->create( [
        'start_time' => Carbon::parse( $start ),
        'end_time'   => Carbon::parse( $end ),
    ] );
}

it( 'completes a confirmed booking whose end time has passed', function (): void {
    Event::fake( [ BookingCompleted::class ] );

    $booking = pastBooking();

    $this->artisan( 'bookings:complete-past' )
        ->expectsOutputToContain( '1 booking(s) completed.' )
        ->assertSuccessful();

    expect( $booking->fresh()->status )->toBe( BookingStatus::Completed );

    Event::assertDispatched(
        BookingCompleted::class,
        static fn ( BookingCompleted $event ): bool => BookingActor::System === $event->actor,
    );
} );

it( 'fires ap.bookings.completed exactly once per booking', function (): void {
    // The reason the sweep goes through BookingService rather than updating the
    // status column: a bare update moves the same rows and tells nobody.
    $seen = [];

    addAction( 'ap.bookings.completed', function ( Booking $booking ) use ( &$seen ): void {
        $seen[] = $booking->getKey();
    } );

    $first  = pastBooking();
    $second = pastBooking( '-4 hours', '-3 hours' );

    $this->artisan( 'bookings:complete-past' )->assertSuccessful();

    expect( $seen )->toHaveCount( 2 )
        ->and( $seen )->toContain( $first->getKey() )
        ->and( $seen )->toContain( $second->getKey() );
} );

it( 'completes nothing a second time when it is run twice', function (): void {
    // Scheduled hourly, with `withoutOverlapping()` saving the work rather than
    // the correctness — so a doubled run has to be a no-op on its own terms.
    $seen = 0;

    addAction( 'ap.bookings.completed', function () use ( &$seen ): void {
        $seen++;
    } );

    pastBooking();

    $this->artisan( 'bookings:complete-past' )
        ->expectsOutputToContain( '1 booking(s) completed.' )
        ->assertSuccessful();
    $this->artisan( 'bookings:complete-past' )
        ->expectsOutputToContain( '0 booking(s) completed.' )
        ->assertSuccessful();

    expect( $seen )->toBe( 1 );
} );

it( 'leaves a booking that has not finished yet alone', function (): void {
    $booking = Booking::factory()->confirmed()->create( [
        'start_time' => Carbon::parse( '+1 hour' ),
        'end_time'   => Carbon::parse( '+2 hours' ),
    ] );

    $this->artisan( 'bookings:complete-past' )
        ->expectsOutputToContain( '0 booking(s) completed.' )
        ->assertSuccessful();

    expect( $booking->fresh()->status )->toBe( BookingStatus::Confirmed );
} );

it( 'leaves an unapproved booking for staff to dispose of', function (): void {
    // Marking a "requested" booking delivered would be the package asserting an
    // appointment happened that nobody ever accepted.
    $booking = Booking::factory()->create( [
        'status'     => BookingStatus::Requested,
        'start_time' => Carbon::parse( '-2 hours' ),
        'end_time'   => Carbon::parse( '-1 hour' ),
    ] );

    $this->artisan( 'bookings:complete-past' )
        ->expectsOutputToContain( '0 booking(s) completed.' )
        ->assertSuccessful();

    expect( $booking->fresh()->status )->toBe( BookingStatus::Requested );
} );

it( 'leaves a cancelled booking cancelled', function (): void {
    $booking = Booking::factory()->create( [
        'status'     => BookingStatus::Cancelled,
        'start_time' => Carbon::parse( '-2 hours' ),
        'end_time'   => Carbon::parse( '-1 hour' ),
    ] );

    $this->artisan( 'bookings:complete-past' )->assertSuccessful();

    expect( $booking->fresh()->status )->toBe( BookingStatus::Cancelled );
} );

describe( '--dry-run', function (): void {
    it( 'reports what it would complete without changing anything', function (): void {
        $booking = pastBooking();

        $this->artisan( 'bookings:complete-past', [ '--dry-run' => true ] )
            ->expectsOutputToContain( '1 booking(s) would be completed.' )
            ->assertSuccessful();

        expect( $booking->fresh()->status )->toBe( BookingStatus::Confirmed );
    } );

    it( 'fires no hooks and dispatches no events', function (): void {
        // A dry run an operator cannot trust to be silent is not a dry run: a
        // subscriber to the completion hook would email the customer about it.
        Event::fake( [ BookingCompleted::class ] );

        $fired = false;

        addAction( 'ap.bookings.completed', function () use ( &$fired ): void {
            $fired = true;
        } );

        pastBooking();

        $this->artisan( 'bookings:complete-past', [ '--dry-run' => true ] )->assertSuccessful();

        expect( $fired )->toBeFalse();

        Event::assertNotDispatched( BookingCompleted::class );
    } );
} );

it( 'does not complete a booking that has not finished, under a non-UTC app timezone', function (): void {
    // `end_time` is stored as UTC and a bound Carbon is rendered by `format()`
    // in its own zone with no conversion, so an unconverted `now()` in a UTC+
    // zone binds nine hours ahead of the truth. This booking ends three hours
    // from now; without the `->utc()` in candidates() it is completed anyway,
    // and `BookingCompleted` fires for an appointment still in progress.
    //
    // The zone is set on PHP rather than only in config: Laravel calls
    // `date_default_timezone_set()` once during bootstrap, so a config write
    // after boot leaves `now()` in UTC and the bug unreproducible.
    date_default_timezone_set( 'Asia/Tokyo' );
    Carbon::setTestNow( Carbon::parse( '2026-06-01 12:00:00', 'UTC' )->setTimezone( 'Asia/Tokyo' ) );

    $booking = Booking::factory()->confirmed()->create( [
        'start_time' => Carbon::parse( '2026-06-01 14:00:00', 'UTC' ),
        'end_time'   => Carbon::parse( '2026-06-01 15:00:00', 'UTC' ),
    ] );

    $this->artisan( 'bookings:complete-past' )
        ->expectsOutputToContain( '0 booking(s) completed.' )
        ->assertSuccessful();

    expect( $booking->fresh()->status )->toBe( BookingStatus::Confirmed );
} );

it( 'still completes a finished booking under a non-UTC app timezone', function (): void {
    // The other half of the same fix: converting must not stop it working.
    date_default_timezone_set( 'America/Chicago' );
    Carbon::setTestNow( Carbon::parse( '2026-06-01 12:00:00', 'UTC' )->setTimezone( 'America/Chicago' ) );

    $booking = Booking::factory()->confirmed()->create( [
        'start_time' => Carbon::parse( '2026-06-01 09:00:00', 'UTC' ),
        'end_time'   => Carbon::parse( '2026-06-01 10:00:00', 'UTC' ),
    ] );

    $this->artisan( 'bookings:complete-past' )
        ->expectsOutputToContain( '1 booking(s) completed.' )
        ->assertSuccessful();

    expect( $booking->fresh()->status )->toBe( BookingStatus::Completed );
} );

it( 'names the service and provider for a booking outside the resolved site', function (): void {
    // The webhook body names both, and the listener's own loadMissing() runs
    // through the site scope — so without the unscoped eager load here, every
    // tenant but the resolved one gets `service: null, provider: null` posted
    // to their integration, silently, because payloadFor() null-guards.
    $booking = pastBooking();

    $booking->forceFill( [ 'site_id' => 2 ] )->save();
    $booking->service->forceFill( [ 'site_id' => 2 ] )->save();
    $booking->provider->forceFill( [ 'site_id' => 2 ] )->save();

    scopeToSite( 1 );

    $seen = null;

    addAction( 'ap.bookings.completed', function ( Booking $completed ) use ( &$seen ): void {
        $seen = $completed;
    } );

    $this->artisan( 'bookings:complete-past' )->assertSuccessful();

    expect( $seen )->not->toBeNull()
        ->and( $seen->getRelation( 'service' ) )->not->toBeNull()
        ->and( $seen->getRelation( 'provider' ) )->not->toBeNull();
} );

it( 'completes bookings belonging to every site', function (): void {
    // The sweep runs from cron where no site is in context. An application whose
    // resolver answers in console too would otherwise close out one tenant's
    // bookings and leave every other tenant's confirmed forever.
    $first  = pastBooking();
    $second = pastBooking( '-4 hours', '-3 hours' );

    $first->forceFill( [ 'site_id' => 1 ] )->save();
    $second->forceFill( [ 'site_id' => 2 ] )->save();

    scopeToSite( 1 );

    $this->artisan( 'bookings:complete-past' )
        ->expectsOutputToContain( '2 booking(s) completed.' )
        ->assertSuccessful();

    $statuses = Booking::query()
        ->acrossAllSites()
        ->whereKey( [ $first->getKey(), $second->getKey() ] )
        ->pluck( 'status' )
        ->all();

    expect( $statuses )->toBe( [ BookingStatus::Completed, BookingStatus::Completed ] );
} );
