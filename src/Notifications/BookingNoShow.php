<?php

/**
 * Booking no-show notification.
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
 * Records that the customer did not attend.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingNoShow extends BookingNotification
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
        return NotificationType::NoShow;
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
        return __( 'Missed appointment (:number)', [ 'number' => $this->booking->booking_number ] );
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
        return __( 'You were marked as not having attended the appointment below.' );
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
        return __( 'No-show: :name on :date (:number)', [
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
        return __( 'This customer was marked as not having attended.' );
    }
}
