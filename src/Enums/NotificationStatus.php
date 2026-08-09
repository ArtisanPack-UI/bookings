<?php

/**
 * Notification status enum.
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
 * Whether a logged notification actually went out.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum NotificationStatus: string
{
    case Pending = 'pending';

    case Sent = 'sent';

    case Failed = 'failed';
}
