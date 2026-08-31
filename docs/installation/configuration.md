---
title: Configuration
---

# Configuration

Publish the config with `php artisan vendor:publish --tag=bookings-config`. It writes `config/artisanpack/bookings.php`, which loads under the runtime key `artisanpack.bookings` (Laravel prefixes nested config directories with the directory name). Read any value with dot notation:

```php
config( 'artisanpack.bookings.slot_interval' );   // 15
```

The published file documents every key inline. The full reference follows.

## The keys worth knowing up front

| Key | Default | What it controls |
| --- | --- | --- |
| `timezone` | `config( 'app.timezone' )` | Zone that unqualified availability is authored in |
| `slot_interval` | `15` | Minutes between candidate slot start times |
| `booking_window` | 60 min – 90 days | How soon and how far ahead a customer may book |
| `cancellation` | allowed, 24h notice | Self-serve cancellation policy |
| `calendar.drivers` | all disabled | Google / Microsoft / Apple sync, opt-in per driver |
| `admin.route_prefix` | `bookings-admin` | Prefix for the staff-facing routes |
| `public.route_prefix` | `bookings` | Prefix for the customer-facing routes |

## Scheduling & timezone

| Key | Default | Controls |
| --- | --- | --- |
| `timezone` | `env( 'BOOKING_DEFAULT_TIMEZONE', config( 'app.timezone' ) )` | Zone availability is authored in when a service/provider declares none |
| `user_model` | `App\Models\User` | User model resolving the optional account behind a provider (nullable, no FK) |
| `slot_interval` | `env( 'BOOKING_SLOT_INTERVAL', 15 )` | Granularity, in minutes, at which bookable slots may start |
| `auto_confirm` | `true` | Whether a new booking confirms itself immediately vs. staying `requested` |

## Availability cache & locking

| Key | Default | Controls |
| --- | --- | --- |
| `availability_cache.enabled` | `true` | Whether computed availability is cached per service / provider / date |
| `availability_cache.ttl_seconds` | `300` | Backstop TTL for the cache (writes invalidate it directly) |
| `lock.wait_seconds` | `5` | How long a request queues for the provider slot lock |
| `lock.store` | `null` | Cache store used for the lock on engines without native advisory locks (e.g. sqlite); `null` = default store |

## Booking window & cancellation

| Key | Default | Controls |
| --- | --- | --- |
| `booking_window.min_advance_minutes` | `60` | Minimum minutes ahead a customer must book; non-positive = no constraint |
| `booking_window.max_advance_minutes` | `129600` (90 days) | Maximum minutes ahead bookable; non-positive = no constraint |
| `cancellation.allowed` | `true` | Whether customers may cancel their own bookings |
| `cancellation.min_advance_minutes` | `1440` (24h) | How long before start the self-serve cancellation link stops working |

## Recurring series

| Key | Default | Controls |
| --- | --- | --- |
| `series.max_occurrences` | `52` | Hard cap on occurrences generated when an RRULE is expanded |

## Notifications

| Key | Default | Controls |
| --- | --- | --- |
| `notifications.channels` | `['mail', 'database', 'webhook']` | Channels used for booking notifications (`sms` deliberately absent) |
| `notifications.confirmation.enabled` | `true` | Whether confirmation notices are sent |
| `notifications.reminder.enabled` | `true` | Whether reminders are sent (also gates `bookings:send-reminders`) |
| `notifications.reminder.hours_before` | `[24]` | Hours before start each reminder is sent |
| `notifications.reminder.max_lookahead_hours` | `0` | How far ahead `bookings:send-reminders` scans; `0` = longest `hours_before` |
| `notifications.reminder.max_catch_up_hours` | `0` | Skip a reminder whose moment is more than this many hours past (after cron downtime); `0` = always catch up |
| `notifications.cancellation.enabled` | `true` | Whether cancellation notices are sent |
| `notifications.reschedule.enabled` | `true` | Whether reschedule notices are sent |
| `notifications.no_show.enabled` | `true` | Whether no-show notices are sent |
| `notifications.provider_assigned.enabled` | `true` | Email a provider when a booking is assigned to them |
| `notifications.provider_unassigned.enabled` | `true` | Email a provider when a booking leaves their calendar |
| `notifications.database.driver` | `'auto'` | Staff `database`-channel implementation: `auto`, `cms`, or `laravel` |
| `notifications.database.role` | `null` | Role that receives booking notices via cms-framework's notification centre |
| `notifications.database.notifiable` | `null` | Model class notified via Laravel-native database notifications |
| `notifications.database.ids` | `[]` | Primary keys of the notifiable model |
| `notifications.admin.email.enabled` | `false` | Also email the staff-facing confirmation / cancellation / reschedule / no-show notices to the `database`-channel recipients (reminder excluded) |
| `notifications.sms_driver` | `env( 'BOOKING_SMS_DRIVER', 'null' )` | Gateway class implementing `Contracts\SmsDriver`; `null` logs and sends nothing |

See [Notifications Overview](Notifications) and [Text Messages](Notifications-Sms).

## Calendar sync

| Key | Default | Controls |
| --- | --- | --- |
| `calendar.default_sync_mode` | `'outbound'` | Default sync mode for a connection (`outbound` vs two-way) |
| `calendar.two_way_grace_hours` | `6` | Grace hours before a two-way connection is downgraded on failure |
| `calendar.two_way_lookahead_days` | `60` | How many days ahead two-way sync reads busy blocks |
| `calendar.connection_failure_threshold` | `5` | Consecutive failures before a connection stops retrying |
| `calendar.ical_feed.enabled` | `true` | Whether the subscribable iCal feed routes are registered; `false` unregisters them so a feed URL 404s |
| `calendar.drivers.google.enabled` | `env( 'BOOKING_GOOGLE_ENABLED', false )` | Enables the Google driver (also requires `artisanpack-ui/google`) |
| `calendar.drivers.microsoft.enabled` | `env( 'BOOKING_MICROSOFT_ENABLED', false )` | Enables the Microsoft driver (requires `artisanpack-ui/microsoft`) |
| `calendar.drivers.apple.enabled` | `env( 'BOOKING_APPLE_ENABLED', false )` | Enables the Apple (CalDAV) driver; drives the 15-min poll schedule |

See [Calendar Sync](Integrations-Calendar-Sync).

## Webhooks

| Key | Default | Controls |
| --- | --- | --- |
| `webhooks.failure_threshold` | `10` | Consecutive delivery failures before an endpoint is disabled |
| `webhooks.delivery_backoff_minutes` | `[1, 5, 30, 120, 720]` | Retry backoff schedule in minutes (6 attempts total) |
| `webhooks.delivery_retention_days` | `30` | How long webhook delivery records are retained |
| `webhooks.timeout_seconds` | `10` | Per-attempt total timeout |
| `webhooks.connect_timeout_seconds` | `5` | Per-attempt connect timeout |
| `webhooks.queue` | `null` | Queue for delivery jobs; `null` = connection default |
| `webhooks.url_guard.enabled` | `true` | Whether the SSRF URL guard runs |
| `webhooks.url_guard.allowed_schemes` | `['https']` | Allowed URL schemes (add `http` for local dev) |
| `webhooks.url_guard.allowed_hosts` | `[]` | Hosts that skip the private-range check |
| `webhooks.url_guard.blocked_hosts` | `[]` | Hosts refused outright |

See [Outbound Webhooks](Notifications-Webhooks) and [Webhook Security](Advanced-Webhook-Security).

## Admin & public surfaces

| Key | Default | Controls |
| --- | --- | --- |
| `admin.route_prefix` | `'bookings-admin'` | Route prefix for staff-facing admin screens |
| `admin.gate` | `'bookings.manage'` | Authorization gate name for admin screens |
| `admin.auto_register_cms_nav` | `true` | Register the CMS nav entry (only when cms-framework installed) |
| `admin.routes_enabled` | `true` | Mount the `bookings-admin/*` Livewire screens; set `false` when the host brings its own admin |
| `forms.auto_register` | `true` | Auto-register the `booking_slot` field type and submission listener |
| `public.route_prefix` | `'bookings'` | Route prefix for the customer-facing widget/API and iCal feeds |
| `public.widget_enabled` | `true` | Mount the no-JS Blade widget's `POST {prefix}/widget` target; set `false` when booking through your own forms |
| `public.manage_url` | `env( 'ARTISANPACK_BOOKINGS_MANAGE_URL' )` | URL template of your self-serve manage page with `{token}` |

## Public rate limits & iCal window

| Key | Default | Controls |
| --- | --- | --- |
| `public.rate_limits.post` | `5` | Booking POST limit, per IP per minute |
| `public.rate_limits.read` | `60` | Service / provider / slot GET limit, per IP per minute |
| `public.rate_limits.manage_get` | `20` | Manage GET limit, per IP per minute |
| `public.rate_limits.manage_token` | `60` | Manage limit, per manage token per minute |
| `public.rate_limits.ical` | `30` | iCal feed limit, per IP per minute |
| `public.rate_limits.ical_token` | `30` | iCal feed limit, per feed token per minute |
| `public.ical.past_days` | `30` | Past days an iCal feed carries; ≤0 = shipped default |
| `public.ical.future_days` | `365` | Future days an iCal feed carries; ≤0 = shipped default |
| `public.ical.max_age` | `300` | How long (seconds) a client may reuse a feed before revalidating |

See [Rate Limiting](Advanced-Rate-Limiting) and [Trusted Proxies](Advanced-Trusted-Proxies).

## Retention

| Key | Default | Controls |
| --- | --- | --- |
| `retention.prune_after_days` | `env( 'BOOKING_PRUNE_DAYS', 1095 )` (3 years) | Age after booking end before `bookings:prune` soft-deletes; ≤0 = prune nothing |
| `retention.notification_log_days` | `90` | Age before `bookings:prune-notification-log` prunes; keep longer than longest reminder |
| `retention.webhook_delivery_days` | `null` | Age before `bookings:prune-webhook-deliveries` prunes; `null` defers to `webhooks.delivery_retention_days` |
| `retention.calendar_events_ttl_days` | `30` | Age (from booking end) before `bookings:prune-calendar-events` prunes |

A window of zero or less is read as "not configured" and prunes nothing, rather than as "keep nothing". See [GDPR, Retention & Erasure](Advanced-Gdpr-Data-Retention).

## Multi-site

Site scoping is **not** configured here — it reads `artisanpack.core.multi_tenant.*`. See [Multi-Site](Advanced-Multi-Site).

## Environment variables

The settings most likely to differ per environment have env-var overrides:

```dotenv
BOOKING_DEFAULT_TIMEZONE=America/Chicago
BOOKING_SLOT_INTERVAL=15
BOOKING_SMS_DRIVER=null
BOOKING_GOOGLE_ENABLED=false
BOOKING_MICROSOFT_ENABLED=false
BOOKING_APPLE_ENABLED=false
BOOKING_PRUNE_DAYS=1095
ARTISANPACK_BOOKINGS_MANAGE_URL="https://example.test/bookings/manage/{token}"
```

## Translations

Every user-facing string in the package runs through Laravel's `__()`, keyed on its English source — so the package reads correctly in English out of the box with no lang file. The package also ships `lang/en.json`, the canonical catalogue of every one of those strings, registered with `loadJsonTranslationsFrom()`.

To translate the package into another language, add a JSON file for the locale to your application's `lang/` directory — for example `lang/fr.json` — keyed on the same English source strings:

```json
{
    "Booking confirmed": "Réservation confirmée",
    "That appointment time is too soon to book.": "Ce créneau est trop proche pour être réservé."
}
```

Copy `lang/en.json` from the package as the starting list of keys, or publish it into your application's `lang/` directory to edit in place:

```bash
php artisan vendor:publish --tag=bookings-lang
```
