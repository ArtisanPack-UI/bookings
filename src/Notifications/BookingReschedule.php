<?php

/**
 * Booking reschedule notification.
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
 * Tells the customer their appointment has moved.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingReschedule extends BookingNotification
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
        return NotificationType::Reschedule;
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
        return __( 'Your booking has moved (:number)', [ 'number' => $this->booking->booking_number ] );
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
        return __( 'Your appointment has been rescheduled. The new time is below.' );
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
        return __( 'Booking moved: :name to :date (:number)', [
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
        return __( 'This booking has been rescheduled. The new time is below.' );
    }
}
