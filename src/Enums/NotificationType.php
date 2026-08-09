<?php

/**
 * Notification type enum.
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
 * Which lifecycle message a notification log entry records.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum NotificationType: string
{
    case Confirmation = 'confirmation';

    case Reminder = 'reminder';

    case Cancellation = 'cancellation';

    case Reschedule = 'reschedule';

    case NoShow = 'no_show';
}
