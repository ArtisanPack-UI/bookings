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

use ArtisanPackUI\Bookings\Events\BookingCancelled;
use ArtisanPackUI\Bookings\Events\BookingConfirmed;
use ArtisanPackUI\Bookings\Events\BookingReassigned;
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
 * `BookingReassigned` does both halves of a move between providers: it re-pushes
 * the booking so the new provider's calendars gain it, and unsyncs the previous
 * provider's calendars so the event comes off the diary it has left. The push is
 * the same {@see CalendarSyncOrchestrator::sync()} the other events use — the
 * booking already carries its new provider, so `sync()` writes to their
 * connections — and the removal goes through
 * {@see CalendarSyncOrchestrator::unsync()}, which finds the stale events through
 * the ledger and dispatches a {@see \ArtisanPackUI\Bookings\Jobs\RemoveBookingFromCalendars}
 * job per calendar.
 *
 * `BookingCancelled` takes the appointment back off every calendar it was written
 * to. The removal `BookingReassigned` uses is per-provider — it takes a booking
 * off the diary it has left — whereas cancellation wants every calendar the
 * booking is on, whichever provider connected it, so it goes through
 * {@see CalendarSyncOrchestrator::unsyncAll()}, which drives the removal straight
 * off the calendar-event ledger. A booking that was never written to a calendar
 * has no ledger rows and is unaffected.
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
            BookingReassigned::class  => 'handleReassigned',
            BookingCancelled::class   => 'handleCancelled',
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

    /**
     * Moves a reassigned booking from the old provider's calendars to the new.
     *
     * The push comes first so the new provider gains the appointment; the unsync
     * takes it off the previous provider's calendars. It is skipped when the
     * booking had no provider before the move — there is nothing to take down —
     * and when the move somehow lands back on the same provider, so the removal
     * cannot pull off a calendar the push has just written to. The two halves are
     * otherwise independent, each keyed to its own provider's connections, so the
     * order between them carries no state.
     *
     * @since 1.0.0
     *
     * @param  BookingReassigned  $event  The event.
     *
     * @return void
     */
    public function handleReassigned( BookingReassigned $event ): void
    {
        $this->orchestrator->sync( $event->booking );

        $previousProviderId = $event->previousProviderId;

        if ( null !== $previousProviderId && $previousProviderId !== $event->booking->provider_id ) {
            $this->orchestrator->unsync( $event->booking, $previousProviderId );
        }
    }

    /**
     * Takes a cancelled booking back off every calendar it was written to.
     *
     * @since 1.0.0
     *
     * @param  BookingCancelled  $event  The event.
     *
     * @return void
     */
    public function handleCancelled( BookingCancelled $event ): void
    {
        $this->orchestrator->unsyncAll( $event->booking );
    }
}
