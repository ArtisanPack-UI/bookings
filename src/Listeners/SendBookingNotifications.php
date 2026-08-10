<?php

/**
 * Booking notification listener.
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

use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Events\BookingCancelled;
use ArtisanPackUI\Bookings\Events\BookingConfirmed;
use ArtisanPackUI\Bookings\Events\BookingNoShow;
use ArtisanPackUI\Bookings\Events\BookingRescheduled;
use ArtisanPackUI\Bookings\Services\NotificationService;
use Illuminate\Events\Dispatcher;

/**
 * Turns booking lifecycle events into notifications.
 *
 * Hung off the events rather than called from {@see
 * \ArtisanPackUI\Bookings\Services\BookingService} directly, for the reason the
 * events exist: a booking can be confirmed by the widget, by an admin screen, by
 * an importer, or by a series materialising, and every one of those goes through
 * the same event. A notification call inside the service would cover them all
 * too — but it would also put mail delivery inside the advisory lock and the
 * transaction that a booking write holds, where a slow SMTP handshake becomes a
 * slot nobody else can book.
 *
 * The events are `ShouldDispatchAfterCommit`, so by the time this runs the
 * booking is committed and readable by a queue worker on another connection.
 *
 * **No listener for `BookingRequested`.** A requested booking is not yet an
 * appointment; the confirmation goes out when it becomes one. A configuration
 * that auto-confirms gets both at once, which is the same single email.
 *
 * **Do not make this `ShouldQueue` without giving these sends a schedule key.**
 * A lifecycle send is claimed with a null `scheduled_for`, and NULLs are
 * distinct in a unique index — so unlike a reminder, nothing here deduplicates.
 * That is correct as long as the events fire once, which they do:
 * {@see \ArtisanPackUI\Bookings\Services\BookingService} refuses every terminal
 * transition whose booking is not in a state to make it, so a booking cannot be
 * confirmed, cancelled, or marked no-show twice, and this listener runs
 * synchronously with no delivery to retry. Reschedules are the exception by
 * design: a booking that moves twice warrants two notices.
 *
 * Queueing this would break that reasoning rather than the code — a job retried
 * after a timeout would find no claim to lose and send the customer a second
 * confirmation. Whoever wants it queued needs a per-transition key in the log
 * first, and coalescing every null into one value is not that: it would suppress
 * the second reschedule along with the duplicate.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SendBookingNotifications
{
    /**
     * Constructs the listener.
     *
     * @since 1.0.0
     *
     * @param  NotificationService  $notifications  The notification service.
     */
    public function __construct( protected NotificationService $notifications )
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
            BookingCancelled::class   => 'handleCancelled',
            BookingRescheduled::class => 'handleRescheduled',
            BookingNoShow::class      => 'handleNoShow',
        ];
    }

    /**
     * Sends the confirmation for a newly confirmed booking.
     *
     * @since 1.0.0
     *
     * @param  BookingConfirmed  $event  The event.
     *
     * @return void
     */
    public function handleConfirmed( BookingConfirmed $event ): void
    {
        $this->notifications->send( NotificationType::Confirmation, $event->booking );
    }

    /**
     * Sends the cancellation notice for a cancelled booking.
     *
     * @since 1.0.0
     *
     * @param  BookingCancelled  $event  The event.
     *
     * @return void
     */
    public function handleCancelled( BookingCancelled $event ): void
    {
        $this->notifications->send( NotificationType::Cancellation, $event->booking );
    }

    /**
     * Sends the reschedule notice for a moved booking.
     *
     * @since 1.0.0
     *
     * @param  BookingRescheduled  $event  The event.
     *
     * @return void
     */
    public function handleRescheduled( BookingRescheduled $event ): void
    {
        $this->notifications->send( NotificationType::Reschedule, $event->booking );
    }

    /**
     * Sends the no-show notice for a missed booking.
     *
     * @since 1.0.0
     *
     * @param  BookingNoShow  $event  The event.
     *
     * @return void
     */
    public function handleNoShow( BookingNoShow $event ): void
    {
        $this->notifications->send( NotificationType::NoShow, $event->booking );
    }
}
