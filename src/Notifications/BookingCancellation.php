<?php

/**
 * Booking cancellation notification.
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
 * Tells the customer their appointment has been called off.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingCancellation extends BookingNotification
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
        return NotificationType::Cancellation;
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
        return __( 'Your booking has been cancelled (:number)', [ 'number' => $this->booking->booking_number ] );
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
        return __( 'Your appointment has been cancelled. It was booked for the time below.' );
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
        return __( 'Booking cancelled: :name on :date (:number)', [
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
        return __( 'This booking has been cancelled and its slot is free again.' );
    }
}
