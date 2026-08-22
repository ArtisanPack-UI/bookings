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
 * booked from, with the link that lets them change it. The provider is a staff
 * reader: a message addressed to the person the appointment was just moved onto
 * or off of, told in their working zone with the customer's details to act on,
 * and — like every staff copy — without the manage link. Staff who are not the
 * provider are notified through the `database` channel rather than by email, so
 * there is no dedicated e-mail audience for them.
 *
 * The value is also the view directory each variant renders from, so
 * `resources/views/emails/customer/` and `resources/views/emails/provider/` are
 * the halves of this enum and adding a case without adding its directory is a
 * missing view rather than a silent fallback to the wrong audience's wording.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum NotificationAudience: string
{
    case Customer = 'customer';

    case Provider = 'provider';
}
