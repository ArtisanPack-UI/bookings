<?php

/**
 * Notification audience enum.
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
 * Who a lifecycle message is being written for.
 *
 * The same booking event is more than one message depending on who reads it.
 * The customer is told what *their* appointment is doing, in the zone they
 * booked from, with the link that lets them change it. Staff are told what has
 * happened on the diary, in the provider's working zone, with the customer's
 * contact details attached and no manage link — that credential belongs to the
 * customer and to nobody else. The provider is a staff reader in their own
 * right: a message addressed to the person the appointment was just moved onto
 * or off of, told in their working zone with the customer's details to act on,
 * and — like every staff copy — without the manage link.
 *
 * The value is also the view directory each variant renders from, so
 * `resources/views/emails/customer/`, `resources/views/emails/admin/`, and
 * `resources/views/emails/provider/` are the halves of this enum and adding a
 * case without adding its directory is a missing view rather than a silent
 * fallback to the wrong audience's wording.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum NotificationAudience: string
{
    case Customer = 'customer';

    case Admin = 'admin';

    case Provider = 'provider';
}
