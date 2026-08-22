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
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Delivers one lifecycle message over one medium.
 *
 * Mail and database are the channels the package ships; the interface exists so
 * an application can add its own — an SMS gateway, a Slack channel, a postal
 * queue — without the notification service knowing what they are. Which
 * channels run for a given message is decided by the
 * `ap.bookings.notification.channels` filter and by configuration, not by the
 * channel itself.
 *
 * Every attempt is written to `booking_notification_log` by the caller, keyed by
 * booking, type, channel, and scheduled time. That is what makes {@see
 * self::send()} safe to retry: the log row is *claimed* before a send rather
 * than read, so a queue that runs a job twice cannot send a customer two
 * confirmations. Because the channel is part of that key, channels claim
 * independently — a mail confirmation and a database confirmation for the same
 * booking are two claims, and both go out.
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
     * @return string The channel key, such as `mail` or `database`.
     */
    public function key(): string;

    /**
     * Determines whether this channel can carry a message for a booking.
     *
     * Answers "is there anywhere to send it" — an SMS channel says no when the
     * customer left the phone field blank, the database channel says no when the
     * application has not named its admins — rather than "should it be sent",
     * which is configuration's decision and is made before this is asked.
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
     * Gets what the notification log should record as the recipient.
     *
     * Only called after {@see self::supports()} has returned true. This is what
     * lands in the log's `recipient` column, so a channel carrying customer
     * contact details should return them — they are redacted along with the
     * booking on erasure — and a channel delivering to staff should return an
     * internal reference rather than a staff address, so that erasing a
     * customer's data does not blank the record of who was told about it.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return string The recipient, as the log should record it.
     */
    public function recipient( NotificationType $type, Booking $booking ): string;

    /**
     * Sends the message.
     *
     * Only called after {@see self::supports()} has returned true and the log row
     * has been claimed. Throw on failure rather than returning quietly: the
     * caller catches, records the attempt as failed, and leaves the row for an
     * operator to see, none of which can happen if the failure never surfaces.
     *
     * The notification is passed in rather than built here because it has
     * already been through the `ap.bookings.notification.sending` and
     * `ap.bookings.notification.subject` filters — a channel constructing its
     * own would deliver an unfiltered message and silently discard whatever a
     * subscriber did to it.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     * @param  Notification  $notification  The filtered notification to deliver.
     *
     * @throws Throwable When delivery fails.
     *
     * @return void
     */
    public function send( NotificationType $type, Booking $booking, Notification $notification ): void;
}
