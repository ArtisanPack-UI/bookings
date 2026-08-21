---
title: Text Messages (SMS)
---

# Text Messages (SMS)

An `sms` notification channel ships, and sends nothing until you give it a gateway. Two switches, deliberately separate:

```php
// config/artisanpack/bookings.php
'notifications' => [
    'channels'   => [ 'mail', 'database', 'webhook', 'sms' ],
    'sms_driver' => App\Sms\TwilioSmsDriver::class,
],
```

`sms_driver` decides *how* a text is sent and `channels` decides *whether* one is. The default driver — `null` — logs the message at info level and sends nothing, so an installation that lists the channel before it has a gateway can see exactly what would have gone out, and to which number, without paying for it.

`sms` is not in the shipped channel list, and installing a driver does not add it. Texts cost money per message and arrive on a real phone, so sending one is never something the package decides on your behalf.

## Writing a driver

Writing a driver is one method. It is handed a number and a string, and knows nothing about bookings:

```php
use ArtisanPackUI\Bookings\Contracts\SmsDriver;

class TwilioSmsDriver implements SmsDriver
{
    public function send( string $phone, string $message ): void
    {
        // Throw if the gateway refuses it. The send is recorded against
        // booking_notification_log as failed, the other channels carry on,
        // and an operator has something to read.
    }
}
```

Name the class in `sms_driver`, or bind `SmsDriver` in the container if it needs constructing your way. A name that resolves to nothing throws rather than falling back to the null driver — an installation that thinks it configured Twilio and is quietly writing log lines has every customer unreachable and nothing obviously wrong.

Twilio and Vonage drivers ship in v1.1.

## Texting on some events only

To text on some events only — or only customers who asked to be texted — leave `sms` out of the configured list and add it from the channels filter:

```php
use ArtisanPackUI\Bookings\Models\Booking;

addFilter(
    'ap.bookings.notification.channels',
    function ( array $channels, string $event, Booking $booking ): array {
        if ( 'cancellation' === $event ) {
            $channels[] = 'sms';
        }

        return $channels;
    },
);
```

The message body is the email's opening line and appointment details, without the greeting. Replace it by returning your own notification with a `toSms(): string` method from `ap.bookings.notification.sending`. The channel declines a booking with no phone number, and one whose personal data has been erased.

## Two things to know before production

**The null driver writes the number and the message to your log.** That is what it is for, and it is also customer contact details and an appointment time sitting in a file that erasing a booking does not reach — the erasure routine sweeps `bookings` and `booking_notification_log`, not `storage/logs`. Fine in development, and a disclosure you have to be able to make if you leave `sms` enabled without a gateway in production. Bind a driver that discards the body, or take `sms` back out of the channel list, if you cannot.

**A phone number on a booking is attacker-supplied.** If your widget is public, anyone can submit a number they do not own and make your application text it, at your expense — the abuse is called SMS pumping. Before you bind a real gateway to a public widget: rate-limit bookings per address and per source, hold texts until a booking is confirmed rather than requested, and reject numbers outside the regions you serve. The package cannot do any of that for you, because only you know which numbers are legitimate.
