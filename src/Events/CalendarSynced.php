<?php

/**
 * Calendar synced event.
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
 * Fired when a booking has been written to an external calendar.
 *
 * Fires per booking per connection, so a provider with two connected calendars
 * produces two of these for one booking. The external identifier is the one the
 * calendar assigned, and is what a later update or delete is addressed to.
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
class CalendarSynced implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection written through.
     * @param  Booking  $booking  The booking that was pushed.
     * @param  string  $externalEventId  The identifier the calendar assigned.
     */
    public function __construct(
        public CalendarConnection $connection,
        public Booking $booking,
        public string $externalEventId,
    ) {
    }
}
