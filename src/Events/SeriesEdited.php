<?php

/**
 * Series edited event.
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
use ArtisanPackUI\Bookings\Enums\SeriesEditScope;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a recurring series is edited.
 *
 * The scope is what makes this event usable. Without it a listener cannot tell
 * a one-off change from a rewrite of the whole arrangement, and those want
 * completely different responses — nobody wants to re-issue an invoice because
 * one week moved by an hour.
 *
 * A `ThisAndFollowing` edit splits the series in two. The event carries the
 * series the edit was applied to; when a split produced a second series, it is
 * carried alongside so a listener can follow the arrangement across the break.
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
class SeriesEdited implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series the edit was applied to.
     * @param  SeriesEditScope  $scope  How far through the series the edit reached.
     * @param  BookingActor  $actor  Who made the edit.
     * @param  BookingSeries|null  $splitSeries  The series carrying the change
     *                                           forward, when the edit split the
     *                                           original in two.
     */
    public function __construct(
        public readonly BookingSeries $series,
        public readonly SeriesEditScope $scope,
        public readonly BookingActor $actor = BookingActor::System,
        public readonly ?BookingSeries $splitSeries = null,
    ) {
    }
}
