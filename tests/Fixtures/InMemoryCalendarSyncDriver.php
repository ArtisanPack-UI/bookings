<?php

/**
 * Calendar sync driver fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Bookings\Contracts\CalendarSyncDriver;
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Support\TimeRange;

/**
 * A calendar that lives in an array.
 *
 * Honours the two rules the real drivers are held to — writes are idempotent,
 * and deleting something already gone is a success — so a test can lean on
 * them without a network.
 *
 * @since 1.0.0
 */
class InMemoryCalendarSyncDriver implements CalendarSyncDriver
{
    /**
     * The events the calendar is holding, keyed by external identifier.
     *
     * @since 1.0.0
     *
     * @var array<string, TimeRange>
     */
    public array $events = [];

    /**
     * The busy periods this calendar reports back.
     *
     * @since 1.0.0
     *
     * @var array<int, TimeRange>
     */
    public array $busy = [];

    /**
     * Gets the calendar system this driver talks to.
     *
     * @since 1.0.0
     *
     * @return CalendarDriver The external calendar system.
     */
    public function driver(): CalendarDriver
    {
        return CalendarDriver::Ical;
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
        // Derived from the booking rather than counted, so a retried create
        // lands on the event it already made instead of making a second one.
        $externalEventId = sprintf( 'evt-%d', $booking->id );

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
        if ( ! array_key_exists( $externalEventId, $this->events ) ) {
            return;
        }

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
     * @return array<int, TimeRange> The busy periods falling inside the window.
     */
    public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array
    {
        $overlapping = [];

        foreach ( $this->busy as $period ) {
            if ( $period->overlaps( $window ) ) {
                $overlapping[] = $period;
            }
        }

        return $overlapping;
    }

    /**
     * Gets how many events the calendar is holding.
     *
     * @since 1.0.0
     *
     * @return int The event count.
     */
    public function eventCount(): int
    {
        return count( $this->events );
    }
}
