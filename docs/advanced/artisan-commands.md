---
title: Artisan Commands
---

# Artisan Commands

The package ships twelve `bookings:*` commands. Some go on the schedule automatically; the rest you run in response to something.

## Scheduled commands

The package puts its own recurring work on your application's schedule. You do not register anything — run Laravel's scheduler and it happens:

```bash
php artisan schedule:work    # or the usual cron entry for schedule:run
```

| Command | Cadence | What it does |
| --- | --- | --- |
| `bookings:send-reminders` | Every 15 minutes | Sends the reminders that have come due |
| `bookings:complete-past` | Hourly | Marks confirmed bookings whose end time has passed as completed |
| `bookings:retry-webhook-deliveries` | Every 15 minutes | Re-queues webhook deliveries stranded by a worker that died mid-retry |
| `bookings:calendar-refresh` | Daily | Re-reads busy blocks for two-way connections |
| `bookings:calendar-watch-renew` | Hourly | Offers due push registrations to the driver package (via `ap.bookings.calendarSync.renewChannels`) to renew before they lapse |
| `bookings:calendar-apple-poll` | Every 15 minutes | Polls Apple calendars, which cannot push |
| `bookings:prune` | Daily, 03:00 | Soft-deletes bookings past their retention window |
| `bookings:prune-notification-log` | Daily, 03:10 | Removes notification log rows past their window |
| `bookings:prune-webhook-deliveries` | Daily, 03:20 | Removes settled delivery attempts past their window |
| `bookings:prune-calendar-events` | Daily, 03:30 | Removes calendar mappings for bookings long over |

The reminder sweep is dropped when `notifications.reminder.enabled` is false, and the three calendar sweeps are registered only when a matching driver is switched on under `calendar.drivers`. Both are gates on the schedule, not on the commands — you can always run any of them by hand.

Every command is registered `withoutOverlapping()`. None needs it for correctness, but a doubled run is a lot of database round trips to do nothing.

## On-demand commands

Three commands are never scheduled — they are things you do in response to something, in front of the output:

| Command | Signature | What it does |
| --- | --- | --- |
| `bookings:erase` | `{--booking=} {--email=} {--dry-run}` | Scrubs personal data on a booking, or every booking for an address |
| `bookings:reissue-detached-manage-tokens` | `{--chunk=} {--force}` | Rotates every manage token, invalidating all outstanding links |
| `bookings:ical-token` | `{provider} {--revoke} {--force}` | Issues, rotates, or revokes a provider's feed token |

- `bookings:erase` — see [GDPR, Retention & Erasure](Advanced-Gdpr-Data-Retention).
- `bookings:reissue-detached-manage-tokens` — see [Manage Tokens](Usage-Manage-Tokens).
- `bookings:ical-token` — see [iCal Feeds](Usage-Ical-Feeds).

## `--dry-run` everywhere destructive

Everything destructive takes `--dry-run`, which reports what a run would do and changes nothing. On `bookings:complete-past` that includes firing no hooks and dispatching no events, so a subscriber cannot email a customer about a completion that has not happened:

```bash
php artisan bookings:complete-past --dry-run
php artisan bookings:prune-notification-log --dry-run
```

Only `confirmed` bookings are completed by `bookings:complete-past`. A `requested` one is an appointment nobody approved, and is left for staff to dispose of as a completion or a no-show.

## No seed / demo command

There is no Artisan seed or demo command. Demo data comes from the model factories (`Database\Factories\<Model>Factory`). The `demo/` directory is a JavaScript widget preview (`npm run demo`), not a PHP seeder.
