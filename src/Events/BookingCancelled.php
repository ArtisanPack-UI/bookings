<?php

/**
 * Booking cancelled event.
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
 * Fired when a booking is cancelled and stops holding its slot.
 *
 * The actor is the reason this event carries anything beyond the booking. "The
 * customer cancelled" and "we cancelled on the customer" are the same status
 * change and completely different things to a listener — the second one owes
 * somebody an apology, and possibly a refund — and the booking row cannot tell
 * them apart on its own.
 *
 * The payload is readonly and dispatched after commit. Plan §5.8 writes
 * bookings inside a transaction and behind an advisory lock, and
 * {@see SerializesModels} restores a payload by re-reading it from the
 * database — so an event dispatched mid-transaction can reach a queue worker on
 * another connection before the commit lands, and the listener dies with a
 * ModelNotFoundException on a row that does exist. Outside a transaction the
 * interface changes nothing.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking that was cancelled.
     * @param  BookingActor  $actor  Who cancelled it.
     * @param  string|null  $reason  Why, when a reason was given. Free text
     *                               supplied by whoever cancelled, so treat it as
     *                               untrusted when displaying it.
     */
    public function __construct(
        public readonly Booking $booking,
        public readonly BookingActor $actor,
        public readonly ?string $reason = null,
    ) {
    }
}
