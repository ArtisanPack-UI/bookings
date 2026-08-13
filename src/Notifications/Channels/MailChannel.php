<?php

/**
 * Mail notification channel.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Notifications\Channels;

use ArtisanPackUI\Bookings\Contracts\NotificationChannel;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * Delivers a lifecycle message to the customer by email.
 *
 * The customer has no account, so there is nothing to notify in the Laravel
 * sense — {@see Notifier::route()} builds an on-demand notifiable from the
 * address on the booking instead.
 *
 * A booking whose personal data has been erased is refused. `customer_email`
 * holds a redaction placeholder rather than a null after erasure, so a
 * reminder cron reaching an erased booking would otherwise try to mail the
 * placeholder — and either bounce, or, if the placeholder ever became a
 * deliverable address, mail a stranger somebody else's appointment.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class MailChannel implements NotificationChannel
{
    /**
     * Gets the identifier this channel is configured and logged under.
     *
     * @since 1.0.0
     *
     * @return string The channel key.
     */
    public function key(): string
    {
        return 'mail';
    }

    /**
     * Determines whether this channel can carry a message for a booking.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return bool True when there is a deliverable address to send to.
     */
    public function supports( NotificationType $type, Booking $booking ): bool
    {
        if ( $booking->isPiiErased() ) {
            return false;
        }

        return false !== filter_var( (string) $booking->customer_email, FILTER_VALIDATE_EMAIL );
    }

    /**
     * Gets what the notification log should record as the recipient.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return string The customer's email address.
     */
    public function recipient( NotificationType $type, Booking $booking ): string
    {
        return (string) $booking->customer_email;
    }

    /**
     * Sends the message.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     * @param  Notification  $notification  The filtered notification to deliver.
     *
     * @return void
     */
    public function send( NotificationType $type, Booking $booking, Notification $notification ): void
    {
        Notifier::route( 'mail', [
            $this->recipient( $type, $booking ) => $booking->customer_name,
        ] )->notify( $notification );
    }
}
