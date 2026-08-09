<?php

/**
 * Calendar connection disabled event.
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

use ArtisanPackUI\Bookings\Models\CalendarConnection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a calendar connection stops being synced.
 *
 * A disabled connection is a silent failure for whoever owns the calendar —
 * their bookings quietly stop appearing in it — so the event exists to give an
 * application somewhere to hang a notification.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class CalendarConnectionDisabled
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection that was disabled.
     * @param  string  $reason  Why it was disabled.
     */
    public function __construct(
        public CalendarConnection $connection,
        public string $reason,
    ) {
    }
}
