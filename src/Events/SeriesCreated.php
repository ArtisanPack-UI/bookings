<?php

/**
 * Series created event.
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

use ArtisanPackUI\Bookings\Models\BookingSeries;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a recurrence rule has been saved and expanded into occurrences.
 *
 * Fires once for the series, after the occurrences exist. Each occurrence also
 * fires its own {@see BookingRequested}, so a listener interested in individual
 * appointments does not need this one — this is for anything that cares about
 * the arrangement rather than the appointments, such as a subscription or an
 * invoice covering the whole run.
 *
 * The occurrence count is carried separately because the series row does not
 * hold one: an RRULE bounded by `UNTIL` rather than `COUNT` only reveals how
 * many occurrences it produced by being expanded.
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
class SeriesCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series that was created.
     * @param  int  $occurrenceCount  How many bookings the rule produced.
     */
    public function __construct(
        public BookingSeries $series,
        public int $occurrenceCount,
    ) {
    }
}
