<?php

/**
 * Series cancelled event.
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
use ArtisanPackUI\Bookings\Models\BookingSeries;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * Fired when a whole recurring series is called off.
 *
 * Only future occurrences are cancelled — a series cancelled halfway through
 * keeps the appointments that already happened, because they did happen. Each
 * cancelled occurrence fires its own {@see BookingCancelled} as well, so a
 * listener that reacts per booking will hear about them individually; this
 * event exists for anything that should fire once for the arrangement rather
 * than once per remaining appointment.
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
class SeriesCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series that was cancelled.
     * @param  BookingActor  $actor  Who cancelled it.
     * @param  int  $cancelledOccurrenceCount  How many future bookings were
     *                                         cancelled with it. Required rather
     *                                         than defaulting to zero: the
     *                                         billing and refund listeners this
     *                                         count exists for cannot tell a
     *                                         caller who omitted it from a series
     *                                         that had nothing left to cancel,
     *                                         and quietly refunding nothing is a
     *                                         worse outcome than a TypeError at
     *                                         the call site.
     * @param  string|null  $reason  Why, when a reason was given. Free text
     *                               supplied by whoever cancelled, so treat it as
     *                               untrusted when displaying it.
     *
     * @throws InvalidArgumentException When the occurrence count is negative.
     */
    public function __construct(
        public readonly BookingSeries $series,
        public readonly BookingActor $actor,
        public readonly int $cancelledOccurrenceCount,
        public readonly ?string $reason = null,
    ) {
        // Zero is legitimate — a series whose remaining occurrences had all
        // been cancelled individually already. A negative count is not, and a
        // refund listener multiplying by it would move money the wrong way.
        if ( $this->cancelledOccurrenceCount < 0 ) {
            throw new InvalidArgumentException( sprintf(
                'A cancelled occurrence count cannot be negative; got %d.',
                $this->cancelledOccurrenceCount,
            ) );
        }
    }
}
