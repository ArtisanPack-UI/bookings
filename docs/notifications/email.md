---
title: Email Notifications
---

# Email Notifications

The `mail` channel sends the customer- and provider-facing booking notices. It is on by default (`mail` is in the shipped `notifications.channels` list) and uses your application's configured mailer — nothing bookings-specific to set up beyond Laravel's own mail config.

## What gets sent

| Notice | Sent to | Enable flag |
| --- | --- | --- |
| Confirmation | Customer | `notifications.confirmation.enabled` |
| Cancellation | Customer | `notifications.cancellation.enabled` |
| Reschedule | Customer | `notifications.reschedule.enabled` |
| No-show | Customer | `notifications.no_show.enabled` |
| Provider assigned | Provider | `notifications.provider_assigned.enabled` |
| Provider unassigned | Provider | `notifications.provider_unassigned.enabled` |

Reminders are also email (and any other enabled channel) — see [Reminders](Notifications-Reminders).

**Times are per-customer; wording is per-application.** Each message renders its dates and times in the booking's own `customer_timezone` (the provider's zone for a staff copy), so a customer in Auckland reads Auckland times. The message *wording*, though, renders in the application's configured locale — the package sets no per-recipient locale — so a multi-language installation that wants each customer emailed in their own language should set the locale around the send itself (for example from an `ap.bookings.notification.sending` subscriber).

## The manage link in confirmation emails

A confirmation email carries the customer's self-serve manage link, built from `public.manage_url`:

```dotenv
ARTISANPACK_BOOKINGS_MANAGE_URL="https://example.test/bookings/manage/{token}"
```

The `{token}` placeholder is filled with the booking's plain manage token at send time — the only moment it is readable. Leave `public.manage_url` unset and the email omits the link. See [Manage Tokens](Usage-Manage-Tokens) and the [Self-Serve Management Page](Usage-Self-Serve-Page).

## Customising the subject

Rewrite any notice's subject line with the `ap.bookings.notification.subject` filter, which runs on the notification itself so it applies even when the notice is sent through Laravel's `Notification` facade:

```php
use ArtisanPackUI\Bookings\Notifications\BookingNotification;
use ArtisanPackUI\Bookings\Models\Booking;

addFilter(
    'ap.bookings.notification.subject',
    function ( string $subject, BookingNotification $notification, Booking $booking ): string {
        return "[{$booking->service->name}] {$subject}";
    },
);
```

## Replacing the notification entirely

Return your own notification from `ap.bookings.notification.sending` to replace what goes out, or `null` to suppress it. Returning anything else throws — a subscriber meaning to veto says so with `null`. The filter runs once per channel, so suppressing the customer's email still leaves the admin's database copy:

```php
addFilter(
    'ap.bookings.notification.sending',
    function ( $notification, Booking $booking ) {
        return $booking->service->slug === 'internal'
            ? null                 // suppress
            : $notification;       // send as-is
    },
);
```

See [Hooks & Filters](Api-Hooks) for the full rules.
