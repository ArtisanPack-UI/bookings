<?php

/**
 * Notification channel contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Contracts;

use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use Throwable;

/**
 * Delivers one lifecycle message over one medium.
 *
 * Mail, SMS, and outbound webhooks are the channels the package ships; the
 * interface exists so an application can add its own — a Slack channel, a
 * postal queue — without the notification service knowing what they are.
 * Which channels run for a given message is decided by the
 * `ap.bookings.notification.channels` filter, not by the channel itself.
 *
 * Every attempt is written to `booking_notification_log` by the caller, keyed
 * by channel, booking, and type. That is what makes {@see self::send()} safe to
 * retry: the log is checked before a send, so a queue that runs a job twice
 * does not send a customer two confirmations.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface NotificationChannel
{
    /**
     * Gets the identifier this channel is configured and logged under.
     *
     * Matches an entry in `artisanpack.bookings.notifications.channels` and is
     * written to the `channel` column of the notification log.
     *
     * @since 1.0.0
     *
     * @return string The channel key, such as `mail` or `sms`.
     */
    public function key(): string;

    /**
     * Determines whether this channel can carry a message for a booking.
     *
     * Answers "is there anywhere to send it" — an SMS channel says no when the
     * customer left the phone field blank — not "should it be sent", which is
     * configuration's decision and is made before this is asked.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return bool True when the channel has somewhere to deliver to.
     */
    public function supports( NotificationType $type, Booking $booking ): bool;

    /**
     * Sends the message.
     *
     * Only called after {@see self::supports()} has returned true. Throw on
     * failure rather than returning quietly: the caller catches, records the
     * attempt as failed, and schedules a retry, none of which can happen if the
     * failure never surfaces.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @throws Throwable When delivery fails.
     *
     * @return void
     */
    public function send( NotificationType $type, Booking $booking ): void;
}
