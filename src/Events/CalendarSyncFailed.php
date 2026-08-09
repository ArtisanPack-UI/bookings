<?php

/**
 * Calendar sync failed event.
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
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a calendar operation did not succeed.
 *
 * Fires on every failed attempt, including ones that will be retried, so a
 * listener that alerts on it will alert on transient network trouble. Anything
 * that should only fire when the connection has genuinely given up belongs on
 * {@see CalendarConnectionDisabled} instead.
 *
 * The failure is carried as a message rather than the exception itself.
 * Exceptions are not reliably serializable — a queued listener can be handed
 * one whose class no longer exists, or one holding a database connection — and
 * this event has to survive the queue.
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
class CalendarSyncFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection that failed.
     * @param  string  $reason  What went wrong, for an operator to read.
     * @param  Booking|null  $booking  The booking being synced, when the failure
     *                                 happened pushing one. Null for failures
     *                                 reading busy periods back.
     */
    public function __construct(
        public CalendarConnection $connection,
        public string $reason,
        public ?Booking $booking = null,
    ) {
    }
}
