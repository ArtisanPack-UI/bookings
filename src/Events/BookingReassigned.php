<?php

/**
 * Booking reassigned event.
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
 * Fired when a booking moves to a different provider at the same time.
 *
 * The counterpart to {@see BookingRescheduled}: that one is the time moving with
 * the provider kept, this one is the provider moving with the time kept. The
 * booking carries its new provider; the previous provider is gone from the row
 * by the time any listener sees it, so it rides along on the event for a
 * listener that has to undo something it did for the old provider — a calendar
 * event synced to their diary, a heads-up sent to them — and set the new one up.
 *
 * The previous provider id can be null: a booking that was unassigned before it
 * was reassigned had no provider to move away from.
 *
 * The payload is readonly and dispatched after commit, for the reason
 * {@see BookingRescheduled} spells out — a reassignment is written behind the
 * same advisory lock, and a payload restored by {@see SerializesModels} on
 * another connection would otherwise race the commit.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingReassigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking on its new provider.
     * @param  int|null  $previousProviderId  The provider it moved away from, or
     *                                        null if it had none.
     * @param  BookingActor  $actor  Who reassigned it.
     */
    public function __construct(
        public readonly Booking $booking,
        public readonly ?int $previousProviderId,
        public readonly BookingActor $actor = BookingActor::System,
    ) {
    }
}
