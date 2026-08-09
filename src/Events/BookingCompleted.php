<?php

/**
 * Booking completed event.
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
 * Fired when a booking is marked as delivered.
 *
 * Completion is a claim about the real world, not a consequence of the clock:
 * a booking whose end time has passed is not complete until somebody — a staff
 * member, or the sweep that closes out past bookings — says it is. That is why
 * the actor is here, and why a listener should not assume this fires promptly
 * after the end time.
 *
 * This is the event a follow-up, a review request, or an invoice hangs off.
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
class BookingCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking that was delivered.
     * @param  BookingActor  $actor  Who marked it complete.
     */
    public function __construct(
        public Booking $booking,
        public BookingActor $actor = BookingActor::System,
    ) {
    }
}
