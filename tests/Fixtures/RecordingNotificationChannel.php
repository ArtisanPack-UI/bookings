<?php

/**
 * Notification channel fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Bookings\Contracts\NotificationChannel;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use RuntimeException;

/**
 * A channel that remembers what it was asked to send instead of sending it.
 *
 * Refuses bookings with no phone number, which is the shape of the real SMS
 * channel's `supports()` and what the contract's "is there anywhere to send it"
 * reading is meant to cover.
 *
 * @since 1.0.0
 */
class RecordingNotificationChannel implements NotificationChannel
{
    /**
     * What the channel has been asked to send.
     *
     * @since 1.0.0
     *
     * @var array<int, array{type: NotificationType, booking_id: int}>
     */
    public array $sent = [];

    /**
     * Constructs the channel.
     *
     * @since 1.0.0
     *
     * @param  bool  $fails  Whether sending should throw.
     */
    public function __construct( protected bool $fails = false )
    {
    }

    /**
     * Gets the identifier this channel is configured and logged under.
     *
     * @since 1.0.0
     *
     * @return string The channel key.
     */
    public function key(): string
    {
        return 'recording';
    }

    /**
     * Determines whether this channel can carry a message for a booking.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return bool True when the customer left a phone number.
     */
    public function supports( NotificationType $type, Booking $booking ): bool
    {
        return null !== $booking->customer_phone;
    }

    /**
     * Sends the message.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @throws RuntimeException When the channel is configured to fail.
     *
     * @return void
     */
    public function send( NotificationType $type, Booking $booking ): void
    {
        if ( $this->fails ) {
            throw new RuntimeException( 'The recording channel was told to fail.' );
        }

        $this->sent[] = [ 'type' => $type, 'booking_id' => $booking->id ];
    }
}
