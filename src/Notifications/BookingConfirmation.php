<?php

/**
 * Booking confirmation notification.
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
 * Tells the customer their appointment is booked.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingConfirmation extends BookingNotification
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
        return NotificationType::Confirmation;
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
        return __( 'Your booking is confirmed (:number)', [ 'number' => $this->booking->booking_number ] );
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
        return __( 'Your appointment is confirmed. Here are the details.' );
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
        return __( 'New booking: :name on :date (:number)', [
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
        return __( 'A new booking has been made. Here are the details.' );
    }
}
