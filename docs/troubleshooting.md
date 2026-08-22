---
title: Troubleshooting
---

# Troubleshooting

## Every customer hits the rate limit at once

Behind a load balancer or CDN without trusted proxies configured, `Request::ip()` returns the proxy's address, so every customer shares one `post` bucket and the fifth booking of the minute is refused for all of them. Configure Laravel's trusted proxies in `bootstrap/app.php`. See [Trusted Proxies](Advanced-Trusted-Proxies).

## The booking widget doesn't appear

The widget is a Livewire component, registered only when `livewire/livewire` is installed:

```bash
composer require livewire/livewire
```

If it still doesn't render, confirm your layout includes `@livewireStyles` / `@livewireScripts` (or `@livewire` directives per your setup).

## Frontend change isn't showing

Rebuild assets:

```bash
npm run build   # or: npm run dev
```

## `SlotUnavailableException` on create

`create()` throws this only when nobody at all could take the slot — the provider is fully booked, outside their schedule, or on a blackout date at that time. Check the provider's `AvailabilitySchedule`, existing bookings, and any `ServiceBlackoutDate`. See [Creating a Booking](Usage-Creating-Bookings).

## Two bookings landed on the same slot

This should not happen with MySQL or PostgreSQL, which use server-side advisory locks. On SQLite (or any engine without them) the lock falls back to the cache store — exclusive within one app server only. If you run more than one, point `artisanpack.bookings.lock.store` at a shared store like redis. See [Requirements](Installation-Requirements).

## A webhook delivery is marked `dead` or the endpoint disabled

A delivery is retried on `webhooks.delivery_backoff_minutes` (six attempts over ~14 hours) before it is marked `dead`. An endpoint is disabled after `webhooks.failure_threshold` consecutive failures — and a single success resets the count. Disabling fires the `WebhookDisabled` event. Check the reason on the `booking_webhook_deliveries` row. See [Outbound Webhooks](Notifications-Webhooks).

## A webhook to an internal host is refused

The SSRF guard refuses any URL whose host resolves to a private, loopback, link-local, or unique-local address, at delivery time. To deliver to an internal host on purpose, add it to `webhooks.url_guard.allowed_hosts`. See [Webhook Security](Advanced-Webhook-Security).

## A calendar sweep reports "nothing synced"

The calendar sweeps find what is due and then need a `CalendarSyncDriver` to act on it. Google ships here (gated on `artisanpack-ui/google`); Microsoft and Apple ship in their companion packages. Until a driver is installed and enabled, the sweeps report what they found and warn that nothing was synced. See [Calendar Sync](Integrations-Calendar-Sync).

## A subscribed iCal feed stopped updating

A feed 404s silently after its token is rotated. Rotating a provider's token (running `bookings:ical-token` again) or reissuing manage tokens invalidates the old subscription URLs — every subscribed client then has to be given the new URL. See [iCal Feeds](Usage-Ical-Feeds).

## SMS "sends" but nothing arrives

The default `sms_driver` is `null`, which logs the message and sends nothing. Set `notifications.sms_driver` to a real gateway class and add `sms` to `notifications.channels`. A driver name that resolves to nothing throws rather than silently falling back. See [Text Messages](Notifications-Sms).

## Reminders sent twice

The notification log is what stops a reminder being sent twice. If you pruned `booking_notification_log` inside a reminder's own window, the claim was removed and the reminder can fire again. Keep `retention.notification_log_days` longer than your longest reminder in `hours_before`. See [Reminders](Notifications-Reminders).

## Staff database notifications go missing

The `laravel` database driver expects Laravel's own notification storage (a UUID key, a JSON `data` column). `artisanpack-ui/cms-framework` ships an incompatible `notifications` table, so under both, point `notifications.database.notifiable` at storage of your own — or use the `cms` driver. A failed write is logged, not thrown, so the customer's email still goes out. See [CMS Framework](Integrations-Cms-Framework).

## Running the tests

```bash
composer test            # everything that runs on in-memory SQLite
composer test:mysql      # the mysql group, against a real MySQL server
composer test:postgres   # the postgres group, against a real Postgres server
```

A few race-safety tests need a real MySQL/Postgres advisory lock and **skip** when the server is unreachable — so a plain `composer test` is green without one. CI sets `BOOKINGS_REQUIRE_EXTERNAL_DB=1` to turn that skip into a failure, since a skipped lock test reads as "race-safety verified" while verifying nothing.

## Code style

Both formatters come from `artisanpack-ui/code-style-pint`. Run them together — Pint first, then PHP-CS-Fixer for the WordPress-style spacing Pint cannot produce:

```bash
composer fix    # pint, then php-cs-fixer
composer lint   # php-cs-fixer --dry-run + pint --test + phpcs
```
