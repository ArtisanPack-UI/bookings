<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Events\BookingCancelled;
use ArtisanPackUI\Bookings\Events\BookingCompleted;
use ArtisanPackUI\Bookings\Events\BookingConfirmed;
use ArtisanPackUI\Bookings\Events\BookingNoShow;
use ArtisanPackUI\Bookings\Events\BookingRequested;
use ArtisanPackUI\Bookings\Events\BookingRescheduled;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Services\CalendarSyncOrchestrator;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * Binds a spy orchestrator in place of the real one and returns it.
 */
function spyCalendarSyncOrchestrator(): CalendarSyncOrchestrator
{
    $spy = Mockery::spy( CalendarSyncOrchestrator::class );

    app()->instance( CalendarSyncOrchestrator::class, $spy );

    return $spy;
}

it( 'syncs a confirmed booking to its calendars', function (): void {
    $orchestrator = spyCalendarSyncOrchestrator();

    $booking = Booking::factory()->create();

    BookingConfirmed::dispatch( $booking );

    $orchestrator->shouldHaveReceived( 'sync' )
        ->once()
        ->with( Mockery::on( fn ( Booking $synced ): bool => $synced->is( $booking ) ) );
} );

it( 're-syncs a rescheduled booking so its calendar event moves in place', function (): void {
    $orchestrator = spyCalendarSyncOrchestrator();

    $booking = Booking::factory()->create();

    BookingRescheduled::dispatch( $booking, new TimeRange(
        CarbonImmutable::parse( '2026-03-01 09:00', 'UTC' ),
        CarbonImmutable::parse( '2026-03-01 09:30', 'UTC' ),
    ) );

    $orchestrator->shouldHaveReceived( 'sync' )
        ->once()
        ->with( Mockery::on( fn ( Booking $synced ): bool => $synced->is( $booking ) ) );
} );

it( 'leaves lifecycle events that are out of scope alone', function ( Closure $raise ): void {
    $orchestrator = spyCalendarSyncOrchestrator();

    $raise( Booking::factory()->create() );

    $orchestrator->shouldNotHaveReceived( 'sync' );
} )->with( [
    'booking.created'   => [ fn ( Booking $booking ) => BookingRequested::dispatch( $booking ) ],
    'booking.cancelled' => [ fn ( Booking $booking ) => BookingCancelled::dispatch( $booking, BookingActor::Customer, 'Ill.' ) ],
    'booking.completed' => [ fn ( Booking $booking ) => BookingCompleted::dispatch( $booking ) ],
    'booking.no_show'   => [ fn ( Booking $booking ) => BookingNoShow::dispatch( $booking ) ],
] );
