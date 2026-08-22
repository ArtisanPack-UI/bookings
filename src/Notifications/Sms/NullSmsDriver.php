<?php

/**
 * Null SMS driver.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Notifications\Sms;

use ArtisanPackUI\Bookings\Contracts\SmsDriver;
use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the log and sends nothing.
 *
 * This is the shipped driver, and the default. SMS costs money per message and
 * reaches a real phone belonging to a real person, so the one thing a package
 * must not do is send one because an installation left a setting alone — an
 * `sms` channel switched on before a gateway is configured should be visible,
 * not expensive.
 *
 * It logs at info rather than debug because a message that was meant to go out
 * and did not is worth seeing in a default log configuration; debug is off in
 * production, which is exactly where somebody wondering "why did the customer
 * not get their text" will be looking.
 *
 * The number is logged in full. It is already in `bookings.customer_phone` and
 * in the notification log's `recipient` column, so redacting it here would hide
 * it from the one place that is trying to explain a delivery while leaving it
 * everywhere else — and the whole use of this driver is confirming that the
 * right number would have been texted.
 *
 * That does put a customer's number and the time of their appointment in a file
 * the erasure routine does not sweep: it walks `bookings` and
 * `booking_notification_log`, and nothing walks `storage/logs`. Deliberate, and
 * the reason this is documented as a development aid rather than a way to run
 * with `sms` enabled and no gateway. An installation that cannot make that
 * disclosure binds a driver that discards the body, or takes `sms` back out of
 * the channel list.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class NullSmsDriver implements SmsDriver
{
    /**
     * Logs the message that would have been sent.
     *
     * @since 1.0.0
     *
     * @param  string  $phone  The destination number.
     * @param  string  $message  The message body.
     *
     * @return void
     */
    public function send( string $phone, string $message ): void
    {
        Log::info( 'A booking SMS was not sent: no SMS driver is configured.', [
            'phone'   => $phone,
            'message' => $message,
        ] );
    }
}
