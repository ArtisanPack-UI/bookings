<?php

/**
 * Booking actor enum.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Enums;

/**
 * Who caused a change to a booking.
 *
 * Cancellation is the case this exists for. "The customer cancelled" and "we
 * cancelled on the customer" are the same status change and completely
 * different events to anybody downstream — one is routine, the other owes
 * somebody an apology and possibly a refund. A listener cannot tell them apart
 * from the booking row alone, so the actor rides along on the event.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum BookingActor: string
{
    case Customer = 'customer';

    case Provider = 'provider';

    case Admin = 'admin';

    case Api = 'api';

    case System = 'system';
}
