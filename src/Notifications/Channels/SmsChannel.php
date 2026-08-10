<?php

/**
 * SMS notification channel.
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
use ArtisanPackUI\Bookings\Contracts\SmsDriver;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Container\Container;
use Illuminate\Notifications\Notification;
use UnexpectedValueException;

use function get_debug_type;
use function is_string;
use function method_exists;
use function sprintf;
use function trim;

/**
 * Sends a lifecycle message to the customer's phone.
 *
 * Not in the default channel list, and deliberately so. SMS costs money per
 * message and the package cannot know an installation has a gateway, so `sms`
 * is opted into — by naming it in `notifications.channels`, or from a
 * subscriber to `ap.bookings.notification.channels`, which is how an
 * application sends texts for cancellations only, or only to customers who
 * asked for them.
 *
 * The channel knows about bookings and the {@see SmsDriver} knows about
 * gateways, and neither knows about the other's half. That is what makes a
 * Twilio integration one class with one method, and what lets this class stay
 * shipped and unchanged when one arrives.
 *
 * The driver is resolved when a message is sent rather than when the channel is
 * built, so an installation whose `sms_driver` names something unresolvable
 * fails one notification — recorded as failed, logged, the other channels
 * unaffected — instead of failing to boot.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SmsChannel implements NotificationChannel
{
    /**
     * Constructs the channel.
     *
     * @since 1.0.0
     *
     * @param  SmsDriver|null  $driver  The gateway to send through, or null to
     *                                  resolve the configured one on first use.
     */
    public function __construct( protected ?SmsDriver $driver = null )
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
        return 'sms';
    }

    /**
     * Determines whether this channel can carry a message for a booking.
     *
     * A booking whose personal data has been erased is refused for the same
     * reason mail refuses one: `customer_phone` holds a redaction placeholder
     * rather than a null afterwards, and a reminder cron reaching an erased
     * booking would otherwise text the placeholder.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return bool True when there is a number to send to.
     */
    public function supports( NotificationType $type, Booking $booking ): bool
    {
        if ( $booking->isPiiErased() ) {
            return false;
        }

        return '' !== $this->recipient( $type, $booking );
    }

    /**
     * Gets what the notification log should record as the recipient.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return string The customer's phone number.
     */
    public function recipient( NotificationType $type, Booking $booking ): string
    {
        return trim( (string) $booking->customer_phone );
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
     * @throws UnexpectedValueException When the notification renders no text.
     *
     * @return void
     */
    public function send( NotificationType $type, Booking $booking, Notification $notification ): void
    {
        $this->driver()->send(
            $this->recipient( $type, $booking ),
            $this->body( $notification ),
        );
    }

    /**
     * Gets the gateway to send through.
     *
     * @since 1.0.0
     *
     * @return SmsDriver The configured driver.
     */
    protected function driver(): SmsDriver
    {
        return $this->driver ??= Container::getInstance()->make( SmsDriver::class );
    }

    /**
     * Renders the notification as the text of a message.
     *
     * `toSms(): string` is asked for by name rather than by interface because
     * the notification reaching here has been through
     * `ap.bookings.notification.sending` and may be anything a subscriber
     * replaced it with. A notification that cannot render text is a programming
     * error rather than a delivery failure, so it throws: texting the customer
     * an empty message, or the word "Array", is worse than a failed row an
     * operator can read.
     *
     * @since 1.0.0
     *
     * @param  Notification  $notification  The notification to render.
     *
     * @throws UnexpectedValueException When there is no text to send.
     *
     * @return string The message body.
     */
    protected function body( Notification $notification ): string
    {
        if ( ! method_exists( $notification, 'toSms' ) ) {
            throw new UnexpectedValueException( sprintf(
                'The sms channel needs a notification with a toSms() method, got %s.',
                get_debug_type( $notification ),
            ) );
        }

        $body = $notification->toSms();

        if ( ! is_string( $body ) ) {
            throw new UnexpectedValueException( sprintf(
                'toSms() must return a string, got %s.',
                get_debug_type( $body ),
            ) );
        }

        $body = trim( $body );

        if ( '' === $body ) {
            throw new UnexpectedValueException( 'The sms channel was given an empty message to send.' );
        }

        return $body;
    }
}
