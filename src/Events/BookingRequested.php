<?php

/**
 * Booking requested event.
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

use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once a booking row exists, before anybody has confirmed it.
 *
 * The slot is already held at this point — `requested` occupies a slot exactly
 * as `confirmed` does — so this is not "somebody asked", it is "the appointment
 * is on the books and is waiting on approval". A service that confirms
 * automatically fires this and {@see BookingConfirmed} in the same request.
 *
 * Carries the booking as saved. Anything a listener changes on it needs saving
 * itself; the dispatcher does not save again afterwards.
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
class BookingRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking that was created.
     */
    public function __construct(
        public Booking $booking,
    ) {
    }
}
