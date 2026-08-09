<?php

/**
 * Booking confirmed event.
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
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a booking's status becomes `confirmed`.
 *
 * This is the event that means the appointment is really happening, which makes
 * it the one to hang confirmation emails, calendar pushes, and CRM records off
 * rather than {@see BookingRequested}.
 *
 * It fires once per booking. A booking confirmed, cancelled, and rebooked is a
 * different booking row and gets its own event.
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
class BookingConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking that was confirmed.
     * @param  BookingActor  $actor  Who confirmed it. `System` when the service
     *                               confirms automatically.
     */
    public function __construct(
        public Booking $booking,
        public BookingActor $actor = BookingActor::System,
    ) {
    }
}
