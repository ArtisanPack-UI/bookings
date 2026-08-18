<?php

/**
 * Booking provider-unassigned notification.
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

use ArtisanPackUI\Bookings\Enums\NotificationAudience;
use ArtisanPackUI\Bookings\Enums\NotificationType;

/**
 * Tells a provider an appointment has been taken off their calendar.
 *
 * Sent to the provider a booking was just reassigned *away from*. That provider
 * is gone from the booking row by the time this is built, so the notification
 * service is handed the provider to address rather than reading it off the
 * booking — which now names the new provider. Like its counterpart {@see
 * BookingProviderAssigned} it is a staff-facing message and defaults to the
 * {@see NotificationAudience::Provider} audience.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingProviderUnassigned extends BookingNotification
{
    /**
     * Who this message is written for.
     *
     * @since 1.0.0
     *
     * @var NotificationAudience
     */
    protected NotificationAudience $audience = NotificationAudience::Provider;

    /**
     * Gets which lifecycle message this is.
     *
     * @since 1.0.0
     *
     * @return NotificationType The notification type.
     */
    public function type(): NotificationType
    {
        return NotificationType::ProviderUnassigned;
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
        return __( 'An appointment has been removed from your calendar (:number)', [
            'number' => $this->booking->booking_number,
        ] );
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
        return __( 'This appointment has been reassigned to another provider and is no longer on your calendar. The details are below.' );
    }
}
