<?php

/**
 * Booking no-show event.
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
 * Fired when a booking is marked as a no-show.
 *
 * Deliberately distinct from {@see BookingCancelled}: the slot was held, the
 * provider's time was spent, and nobody turned up. Anything that counts against
 * a customer — a strike, a fee, a deposit forfeit — belongs on this event and
 * not on cancellation.
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
class BookingNoShow implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking nobody attended.
     * @param  BookingActor  $actor  Who marked it as a no-show.
     */
    public function __construct(
        public readonly Booking $booking,
        public readonly BookingActor $actor = BookingActor::System,
    ) {
    }
}
