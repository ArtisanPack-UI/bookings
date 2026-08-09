<?php

/**
 * Calendar sync driver contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Contracts;

use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Throwable;

/**
 * Talks to one external calendar system on a connection's behalf.
 *
 * Each driver ships in the package for the service it integrates with —
 * `artisanpack-ui/google`, `artisanpack-ui/microsoft`, `artisanpack-ui/apple` —
 * and registers itself through the `ap.bookings.calendarSync.providers` filter.
 * This package never depends on any of them; a connection whose driver is not
 * installed simply has nothing to sync it.
 *
 * Two rules the sync service relies on and cannot enforce:
 *
 * - **Writes are idempotent.** Every write method takes or returns the external
 *   identifier, and calling one twice for the same booking must leave one event
 *   on the calendar, not two. Delivery is retried after a timeout, and a
 *   timeout is indistinguishable from a slow success.
 * - **Failures throw.** A driver that swallows an error and returns normally
 *   makes a connection look healthy while it silently stops syncing, which is
 *   the exact failure `consecutive_failure_count` exists to catch. Throw, and
 *   the sync service records it and eventually disables the connection.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface CalendarSyncDriver
{
    /**
     * Gets the calendar system this driver talks to.
     *
     * The registry keys drivers by this value, and a connection is routed to
     * the driver whose value matches its `driver` column.
     *
     * @since 1.0.0
     *
     * @return CalendarDriver The external calendar system.
     */
    public function driver(): CalendarDriver;

    /**
     * Creates the external event for a booking.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  Booking  $booking  The booking to represent on the calendar.
     *
     * @throws Throwable When the external calendar refuses or is unreachable.
     *
     * @return string The identifier the external calendar gave the event.
     */
    public function createEvent( CalendarConnection $connection, Booking $booking ): string;

    /**
     * Updates the external event for a booking that has changed.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  Booking  $booking  The booking in its current state.
     * @param  string  $externalEventId  The event to update.
     *
     * @throws Throwable When the external calendar refuses or is unreachable.
     *
     * @return string The event identifier after the update. Usually the one that
     *                was passed in, but a calendar that reissues identifiers on
     *                a move returns the new one.
     */
    public function updateEvent( CalendarConnection $connection, Booking $booking, string $externalEventId ): string;

    /**
     * Removes an external event.
     *
     * Deleting an event that is already gone is a success, not a failure. The
     * caller's intent is that the event not exist, and it does not.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  string  $externalEventId  The event to remove.
     *
     * @throws Throwable When the external calendar refuses or is unreachable.
     *
     * @return void
     */
    public function deleteEvent( CalendarConnection $connection, string $externalEventId ): void;

    /**
     * Reads back the periods the external calendar considers busy.
     *
     * Only called for connections in two-way mode, because this is what lets an
     * external calendar suppress availability. Return the busy periods as
     * instants in UTC; the caller does not know the calendar's display zone and
     * must not have to guess it.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to read from.
     * @param  TimeRange  $window  The span of time to read.
     *
     * @throws Throwable When the external calendar refuses or is unreachable.
     *
     * @return array<int, TimeRange> The busy periods, in no guaranteed order.
     */
    public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array;
}
