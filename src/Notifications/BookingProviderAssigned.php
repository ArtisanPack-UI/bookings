<?php

/**
 * Booking provider-assigned notification.
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
 * Tells a provider an appointment has been put on their calendar.
 *
 * Sent to the provider a booking was just reassigned *to*. It is a staff-facing
 * message — the customer's contact details are on it, the time is in the
 * provider's working zone, and there is no manage link — so it defaults to the
 * {@see NotificationAudience::Provider} audience rather than the customer one
 * the base class assumes, and every audience-dependent line below resolves to
 * the provider copy through {@see BookingNotification::isStaffAudience()}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingProviderAssigned extends BookingNotification
{
    /**
     * Who this message is written for.
     *
     * Provider rather than the customer default: this notification only ever
     * goes to the provider it names, and a direct dispatch that skipped the
     * notification service would otherwise render the customer's wording.
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
        return NotificationType::ProviderAssigned;
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
        return __( 'An appointment has been assigned to you (:number)', [
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
        return __( 'This appointment has been assigned to you. The details are below.' );
    }
}
