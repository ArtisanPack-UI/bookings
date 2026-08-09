<?php

/**
 * Calendar driver enum.
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
 * The external calendar system a connection talks to.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum CalendarDriver: string
{
    /**
     * Determines whether this driver can register a push notification channel.
     *
     * Google and Microsoft both push; Apple and plain iCal have no mechanism
     * for it and are polled instead. A connection on a driver that answers
     * false here has no row in `booking_calendar_watch_channels`, which is why
     * the renewal sweep never finds one to renew.
     *
     * @since 1.0.0
     *
     * @return bool True when the driver supports push notifications.
     */
    public function supportsWatchChannels(): bool
    {
        return match ( $this ) {
            self::Google, self::Microsoft => true,
            self::Apple, self::Ical       => false,
        };
    }
    case Google = 'google';

    case Microsoft = 'microsoft';

    case Apple = 'apple';

    case Ical = 'ical';
}
