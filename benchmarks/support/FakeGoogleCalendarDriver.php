<?php

/**
 * Fake Google calendar driver for benchmarking.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Benchmarks;

use ArtisanPackUI\Bookings\Contracts\CalendarSyncDriver;
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Support\TimeRange;

/**
 * Stands in for Google so the sync throughput measured is the package's, not the network's.
 *
 * Registered under the `google` key so a connection built by the factory routes
 * to it, this honours the two rules the real drivers are held to — writes are
 * idempotent, and deleting something already gone is a success — and counts every
 * call so a run can assert it exercised the path it meant to. A per-call delay
 * models the round-trip a real calendar API would add without ever leaving the
 * process, so a soak can be run against a realistic latency without a network or
 * a Google account.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class FakeGoogleCalendarDriver implements CalendarSyncDriver
{
    /**
     * How many events the fake calendar is currently holding.
     *
     * @since 1.0.0
     *
     * @var array<string, TimeRange>
     */
    private array $events = [];

    /**
     * How many times each write method has been called.
     *
     * @since 1.0.0
     *
     * @var array{create: int, update: int, delete: int, busy: int}
     */
    private array $calls = [
        'create' => 0,
        'update' => 0,
        'delete' => 0,
        'busy'   => 0,
    ];

    /**
     * Constructs the driver.
     *
     * @since 1.0.0
     *
     * @param  int  $latencyMicros  Microseconds to sleep on each write, modelling
     *                              the calendar API's round-trip. Zero measures the
     *                              package's own overhead with no simulated network.
     */
    public function __construct( private readonly int $latencyMicros = 0 )
    {
    }

    /**
     * Gets the calendar system this driver talks to.
     *
     * @since 1.0.0
     *
     * @return CalendarDriver The external calendar system.
     */
    public function driver(): CalendarDriver
    {
        return CalendarDriver::Google;
    }

    /**
     * Creates the external event for a booking.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  Booking  $booking  The booking to represent on the calendar.
     *
     * @return string The identifier the calendar gave the event.
     */
    public function createEvent( CalendarConnection $connection, Booking $booking ): string
    {
        $this->calls['create']++;
        $this->pause();

        // Derived from the booking so a retried create lands on the event it
        // already made rather than making a second one, the same idempotency the
        // real drivers promise.
        $externalEventId = sprintf( 'bench-evt-%d', $booking->getKey() );

        $this->events[ $externalEventId ] = new TimeRange( $booking->start_time, $booking->end_time );

        return $externalEventId;
    }

    /**
     * Updates the external event for a booking that has changed.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  Booking  $booking  The booking in its current state.
     * @param  string  $externalEventId  The event to update.
     *
     * @return string The event identifier after the update.
     */
    public function updateEvent( CalendarConnection $connection, Booking $booking, string $externalEventId ): string
    {
        $this->calls['update']++;
        $this->pause();

        $this->events[ $externalEventId ] = new TimeRange( $booking->start_time, $booking->end_time );

        return $externalEventId;
    }

    /**
     * Removes an external event.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  string  $externalEventId  The event to remove.
     *
     * @return void
     */
    public function deleteEvent( CalendarConnection $connection, string $externalEventId ): void
    {
        $this->calls['delete']++;
        $this->pause();

        unset( $this->events[ $externalEventId ] );
    }

    /**
     * Reads back the periods the external calendar considers busy.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to read from.
     * @param  TimeRange  $window  The span of time to read.
     *
     * @return array<int, TimeRange> Always empty; the fake reports nothing busy.
     */
    public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array
    {
        $this->calls['busy']++;

        return [];
    }

    /**
     * Gets how many times each write method has been called.
     *
     * @since 1.0.0
     *
     * @return array{create: int, update: int, delete: int, busy: int} The counts.
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Gets how many events the fake calendar is holding.
     *
     * @since 1.0.0
     *
     * @return int The event count.
     */
    public function eventCount(): int
    {
        return count( $this->events );
    }

    /**
     * Sleeps for the configured latency, modelling the calendar API's round-trip.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function pause(): void
    {
        if ( $this->latencyMicros > 0 ) {
            usleep( $this->latencyMicros );
        }
    }
}
