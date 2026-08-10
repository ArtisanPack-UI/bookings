<?php

/**
 * SMS driver contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Contracts;

use Throwable;

/**
 * Hands one text message to whatever actually sends text messages.
 *
 * Deliberately smaller than {@see NotificationChannel}: the channel is the half
 * that knows about bookings — whether there is a number to send to, what the
 * message says, what the log should record — and this is the half that knows
 * about a gateway. Splitting them is what keeps a Twilio or Vonage integration
 * to one class with one method, and keeps it out of the booking domain
 * entirely; a driver is handed a number and a string and has no idea an
 * appointment exists.
 *
 * The package ships only {@see \ArtisanPackUI\Bookings\Notifications\Sms\NullSmsDriver},
 * which logs and sends nothing. Real drivers land in v1.1; until then — and
 * after, for a gateway the package never ships — an application implements this
 * and names its class in `artisanpack.bookings.notifications.sms_driver`, or
 * binds it against this interface.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface SmsDriver
{
    /**
     * Sends one message to one number.
     *
     * Throw on failure rather than returning quietly. The caller is the SMS
     * channel, which is inside the notification service's per-channel try —
     * a throw is recorded against the notification log as a failed send and
     * left for an operator to find, none of which can happen if the failure
     * never surfaces. Returning normally is taken as "the gateway accepted
     * it", which is as much as any gateway can promise at the moment of the
     * call.
     *
     * @since 1.0.0
     *
     * @param  string  $phone  The destination number, as the booking holds it.
     * @param  string  $message  The message body, already rendered and filtered.
     *
     * @throws Throwable When the message could not be handed off.
     *
     * @return void
     */
    public function send( string $phone, string $message ): void;
}
