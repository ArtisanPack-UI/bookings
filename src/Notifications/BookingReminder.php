<?php

/**
 * Booking reminder notification.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Notifications;

use ArtisanPackUI\Bookings\Enums\NotificationType;

/**
 * Reminds the customer that their appointment is coming up.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingReminder extends BookingNotification
{
    /**
     * Gets which lifecycle message this is.
     *
     * @since 1.0.0
     *
     * @return NotificationType The notification type.
     */
    public function type(): NotificationType
    {
        return NotificationType::Reminder;
    }

    /**
     * Gets the subject line this notification would use unfiltered.
     *
     * @since 1.0.0
     *
     * @return string The default subject.
     */
    protected function defaultSubject(): string
    {
        return __( 'Reminder: your booking on :date', [ 'date' => $this->booking->startTimeForCustomer()->format( 'j F' ) ] );
    }

    /**
     * Gets the first line of the message body.
     *
     * @since 1.0.0
     *
     * @return string The opening line.
     */
    protected function openingLine(): string
    {
        return __( 'This is a reminder about your upcoming appointment.' );
    }

    /**
     * Gets the subject line the staff copy would use unfiltered.
     *
     * @since 1.0.0
     *
     * @return string The staff subject.
     */
    protected function adminSubject(): string
    {
        return __( 'Upcoming booking: :name on :date (:number)', [
            'name'   => $this->booking->customer_name,
            'date'   => $this->booking->startTimeForProvider()->format( 'j F' ),
            'number' => $this->booking->booking_number,
        ] );
    }

    /**
     * Gets the first line of the staff copy.
     *
     * @since 1.0.0
     *
     * @return string The staff opening line.
     */
    protected function adminOpeningLine(): string
    {
        return __( 'This is a reminder about an upcoming appointment on the diary.' );
    }
}
