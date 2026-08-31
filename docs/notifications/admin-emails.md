---
title: Admin Email Copies
---

# Admin Email Copies

Staff already hear about the booking lifecycle through the [`database` channel](Notifications) — a notice in cms-framework's centre, or a row in Laravel's own storage. `notifications.admin.email.enabled` additionally puts a copy of that notice in their mailbox. It is off by default: the people who would receive the email are the same people the database notice already reached, and emailing them as well costs and duplicates it.

```php
// config/artisanpack/bookings.php
'notifications' => [
    'admin' => [
        'email' => [ 'enabled' => true ],   // default false
    ],
],
```

## What gets emailed

Turned on, it emails the staff-facing copy of four notices:

| Notice | Also gated by |
| --- | --- |
| Confirmation | `notifications.confirmation.enabled` |
| Cancellation | `notifications.cancellation.enabled` |
| Reschedule | `notifications.reschedule.enabled` |
| No-show | `notifications.no_show.enabled` |

The reminder is deliberately left out — a staff mailbox does not need a nudge about a customer's appointment — and a lifecycle type switched off under `notifications` is not emailed here either, so this never sends a copy of a notice the installation has otherwise silenced.

## Who receives them

The recipients are exactly the staff the `database` channel resolves, chosen the same way it chooses them, so the two never target different people:

- When this installation's database notices go through cms-framework's notification centre (its `driver` is `auto` with cms-framework installed, or `cms`), the audience is `notifications.database.role` — every user carrying that role. A role stays right as staff join and leave.
- Otherwise — Laravel's own database notifications — the audience is the `notifications.database.notifiable` model and its `ids`.

Named nobody, nothing is emailed. A configured role that the user model cannot answer — no `roles` relationship to filter on — falls back to the id list rather than silently resolving no one.

## What the message says

The body is the provider-audience wording, not the customer's: the staff copy, rendered in the provider's working timezone, carrying the customer's contact details and **no** manage link — the same body the provider notices render. It is the diary-side view of the appointment, for the people running it.

## Preferences, erasure, and suppression

This is a direct email, not a second copy in the CMS centre, so it does **not** consult the per-user notification preferences that centre keeps: it goes to whichever staff the role or id list names. Two things still stop it, both matching the customer's own copy:

- A booking whose personal data has been erased sends nothing — there is nothing left to put in the message. See [GDPR, Retention & Erasure](Advanced-Gdpr-Data-Retention).
- The `ap.bookings.notification.sending` filter suppresses it per booking. The filter runs once per channel, so returning `null` for the admin email leaves the customer's email and the database notice untouched.

```php
addFilter(
    'ap.bookings.notification.sending',
    function ( $notification, Booking $booking ) {
        // Keep internal bookings out of the staff inbox, everything else as-is.
        return $booking->service->slug === 'internal' ? null : $notification;
    },
);
```

See [Hooks & Filters](Api-Hooks) for the payloads and the once-per-channel rule.

## Customising the templates

The four messages render from `resources/views/emails/admin/{confirmation,cancellation,reschedule,no_show}.blade.php`. Publish them to edit in place:

```bash
php artisan vendor:publish --tag=bookings-views
```

Rewrite a subject line without touching a template through the `ap.bookings.notification.subject` filter — see [Email Notifications](Notifications-Email).
