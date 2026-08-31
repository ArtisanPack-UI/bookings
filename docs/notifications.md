---
title: Notifications & Webhooks Overview
---

# Notifications & Webhooks

How the booking lifecycle reaches customers, staff, and your own systems. A lifecycle transition fans out across the channels you configure:

```php
// config/artisanpack/bookings.php
'notifications' => [
    'channels' => [ 'mail', 'database', 'webhook' ],   // 'sms' is opt-in
],
```

## In this section

- [Email Notifications](Notifications-Email) - Confirmation, cancellation, and provider notices
- [Admin Email Copies](Notifications-Admin-Emails) - Emailing staff a copy of the lifecycle notices
- [Reminders](Notifications-Reminders) - Scheduled reminders and the cadence filter
- [Text Messages (SMS)](Notifications-Sms) - The SMS channel and writing a gateway driver
- [Outbound Webhooks](Notifications-Webhooks) - Signed, retried, SSRF-guarded deliveries

## The channels

| Channel | Delivers |
| --- | --- |
| `mail` | Customer and provider emails — see [Email](Notifications-Email) |
| `database` | Staff-facing notices, into cms-framework's centre or Laravel's own storage |
| `webhook` | The lifecycle to subscribed endpoints — see [Webhooks](Notifications-Webhooks) |
| `sms` | Text messages, once a gateway is bound — see [SMS](Notifications-Sms) (opt-in) |

## Which transitions notify

Notifications are sent by the `SendBookingNotifications` subscriber, which reacts to `BookingConfirmed`, `BookingCancelled`, `BookingRescheduled`, `BookingReassigned`, and `BookingNoShow`. A merely *requested* booking is not yet confirmed, so it does not notify. Each notice type has its own enable flag:

| Flag | Default |
| --- | --- |
| `notifications.confirmation.enabled` | `true` |
| `notifications.cancellation.enabled` | `true` |
| `notifications.reminder.enabled` | `true` |
| `notifications.provider_assigned.enabled` | `true` |
| `notifications.provider_unassigned.enabled` | `true` |

## The database channel

The `database` channel writes staff notices. Its `notifications.database.driver` picks the implementation:

- `auto` (default) — uses cms-framework's notification centre when it is installed, and Laravel's own database notifications otherwise.
- `cms` — forces the cms-framework centre; set `notifications.database.role` to the role that should receive them.
- `laravel` — forces Laravel-native database notifications; set `notifications.database.notifiable` (a model class) and `notifications.database.ids`.

Laravel's own notification storage expects a UUID key and a JSON `data` column. `artisanpack-ui/cms-framework` ships its own `notifications` table with an incompatible schema, so an application running both under the `laravel` driver has to point its notifiable at storage of its own. A failed write is recorded against the notification log rather than thrown, so the customer's email goes out either way; the admin row is what goes missing. See [CMS Framework](Integrations-Cms-Framework).

The same staff this channel resolves can be sent an email copy of the lifecycle notices, off by default — see [Admin Email Copies](Notifications-Admin-Emails).

## The notification log & idempotency

Every send is claimed in `booking_notification_log` before it goes out, so a reminder is never sent twice even if a sweep overlaps itself. That log is what the four notification filters run *before*, which keeps them inside the idempotency guarantee rather than outside it:

- `ap.bookings.notification.sending` — return the notification, a replacement, or `null` to suppress
- `ap.bookings.notification.channels` — add or remove channels per event
- `ap.bookings.notification.subject` — rewrite the subject line
- `ap.bookings.reminderScheduling` — filter the reminder cadence

See [Hooks & Filters](Api-Hooks) for the exact payloads and rules.

Keep `retention.notification_log_days` comfortably longer than your longest reminder in `hours_before`: the log row is what stops a reminder being sent twice, so pruning one inside its own reminder window un-claims a send that already happened. See [GDPR, Retention & Erasure](Advanced-Gdpr-Data-Retention).

## Subscribe a webhook

```php
use ArtisanPackUI\Bookings\Models\Webhook;

Webhook::create( [
    'name'   => 'Zapier',
    'url'    => 'https://hooks.zapier.test/bookings',
    'secret' => Str::random( 40 ),
    'events' => [ 'booking.confirmed', 'booking.cancelled' ],
] );
```

See [Outbound Webhooks](Notifications-Webhooks) for the full delivery, signing, and retry contract.
