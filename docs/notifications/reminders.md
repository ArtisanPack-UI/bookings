---
title: Reminders
---

# Reminders

Booking reminders are sent by the `bookings:send-reminders` command, which the package puts on your application's schedule every 15 minutes. Run Laravel's scheduler and it happens — see [Artisan Commands](Advanced-Artisan-Commands).

## Configuring the cadence

```php
// config/artisanpack/bookings.php
'notifications' => [
    'reminder' => [
        'enabled'             => true,
        'hours_before'        => [ 24 ],   // one reminder, 24h out
        'max_lookahead_hours' => 0,        // 0 = derive from the longest hours_before
    ],
],
```

Add entries to `hours_before` for multiple reminders — for example `[ 72, 24, 2 ]` sends three. The reminder sweep is dropped entirely when `notifications.reminder.enabled` is false; that is a gate on the schedule, not on the command, so you can always run it by hand.

`max_lookahead_hours` is how far ahead the sweep scans for bookings to remind. Left at `0` it defaults to the longest window in `hours_before`. Raise it only when a subscriber adds a *longer* window through the filter below than anything in config — the sweep has to decide how far ahead to look before it has a booking to hand the filter.

## Idempotency

Each reminder is claimed in `booking_notification_log` before it is sent, so an overlapping sweep never sends the same reminder twice. Keep `retention.notification_log_days` comfortably longer than your longest reminder: pruning a log row inside its own reminder window would un-claim a send that already happened. See [GDPR, Retention & Erasure](Advanced-Gdpr-Data-Retention).

## Per-booking cadence with a filter

`ap.bookings.reminderScheduling` filters the cadence, in whole hours before the start, for one booking:

```php
use ArtisanPackUI\Bookings\Models\Booking;

addFilter(
    'ap.bookings.reminderScheduling',
    function ( array $hoursBefore, Booking $booking ): array {
        if ( $booking->service->slug === 'surgery' ) {
            $hoursBefore[] = 168;   // add a one-week reminder
        }

        return $hoursBefore;
    },
);
```

Duplicate windows are collapsed — appending `24` to a config that already has it changes nothing rather than fighting the unique index on every cron run. A window *longer* than anything in config also needs `notifications.reminder.max_lookahead_hours` raised to match.

## Channels

A reminder is delivered on every channel in `notifications.channels`, filtered per booking by `ap.bookings.notification.channels`. To text a reminder, add `sms` from that filter — see [Text Messages](Notifications-Sms).
