<?php

/**
 * Booking rescheduled event.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Events;

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a booking moves to a different time.
 *
 * The booking carries the new time; the old one is gone from the row by the
 * time any listener sees it, which is why it rides along on the event. A
 * listener that has to update something it already sent — a calendar event, a
 * reminder job — needs both.
 *
 * A change of provider without a change of time does not fire this. Only the
 * time moving does.
 *
 * Dispatched after commit. Plan §5.8 writes bookings inside a transaction and
 * behind an advisory lock, and {@see SerializesModels} restores a payload by
 * re-reading it from the database — so an event dispatched mid-transaction can
 * reach a queue worker on another connection before the commit lands, and the
 * listener dies with a ModelNotFoundException on a row that does exist. Outside
 * a transaction the interface changes nothing.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingRescheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking at its new time.
     * @param  TimeRange  $previousPeriod  The time the booking used to occupy.
     * @param  BookingActor  $actor  Who moved it.
     */
    public function __construct(
        public Booking $booking,
        public TimeRange $previousPeriod,
        public BookingActor $actor = BookingActor::System,
    ) {
    }
}
