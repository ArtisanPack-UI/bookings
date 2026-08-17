<?php

/**
 * Calendar sync listener.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Listeners;

use ArtisanPackUI\Bookings\Events\BookingConfirmed;
use ArtisanPackUI\Bookings\Events\BookingRescheduled;
use ArtisanPackUI\Bookings\Services\CalendarSyncOrchestrator;
use Illuminate\Events\Dispatcher;

/**
 * Drives the outbound calendar push off a booking's lifecycle events.
 *
 * The orchestrator is the entry point for writing a booking out to its provider's
 * connected calendars, but nothing calls it on its own — it only runs if
 * something hands it a booking. This listener is that something, hung off the
 * events rather than called from
 * {@see \ArtisanPackUI\Bookings\Services\BookingService} for the same reason
 * {@see SendBookingNotifications} and {@see DispatchBookingWebhooks} are: a
 * booking can be confirmed or moved by the widget, an admin screen, an importer,
 * or a series materialising, and every one of those goes through the same event.
 * Keeping the push here keeps calendar concerns out of the service and matches
 * the subscriber pattern already established beside it.
 *
 * `BookingConfirmed` pushes the appointment out (plan §5.9). `BookingRescheduled`
 * re-pushes it: the orchestrator keys its ledger on the booking and the
 * connection together, so the second push updates the calendar event already
 * recorded rather than stacking a second one.
 *
 * **No listener for `BookingCancelled`.** Removing a booking from its calendars
 * needs `Jobs\RemoveBookingFromCalendars` (plan §5.6), which does not exist yet;
 * cancellation is tracked separately. A booking whose provider has no active,
 * event-writing connection is unaffected, since the orchestrator no-ops on it.
 *
 * The events are `ShouldDispatchAfterCommit`, so by the time this runs the
 * booking is committed and readable by the queue worker each push is dispatched
 * onto.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SyncBookingToCalendar
{
    /**
     * Constructs the listener.
     *
     * @since 1.0.0
     *
     * @param  CalendarSyncOrchestrator  $orchestrator  The calendar sync orchestrator.
     */
    public function __construct( protected CalendarSyncOrchestrator $orchestrator )
    {
    }

    /**
     * Registers the listener's handlers with the event dispatcher.
     *
     * @since 1.0.0
     *
     * @param  Dispatcher  $events  The event dispatcher.
     *
     * @return array<class-string, string> The events mapped to their handlers.
     */
    public function subscribe( Dispatcher $events ): array
    {
        return [
            BookingConfirmed::class   => 'handleConfirmed',
            BookingRescheduled::class => 'handleRescheduled',
        ];
    }

    /**
     * Pushes a newly confirmed booking out to its provider's calendars.
     *
     * @since 1.0.0
     *
     * @param  BookingConfirmed  $event  The event.
     *
     * @return void
     */
    public function handleConfirmed( BookingConfirmed $event ): void
    {
        $this->orchestrator->sync( $event->booking );
    }

    /**
     * Re-pushes a rescheduled booking so its calendar event moves in place.
     *
     * @since 1.0.0
     *
     * @param  BookingRescheduled  $event  The event.
     *
     * @return void
     */
    public function handleRescheduled( BookingRescheduled $event ): void
    {
        $this->orchestrator->sync( $event->booking );
    }
}
