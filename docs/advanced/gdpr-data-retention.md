---
title: GDPR, Retention & Erasure
---

# GDPR, Retention & Erasure

The package separates two obligations that are easy to conflate: **retention** (dropping data you no longer need to keep) and **erasure** (scrubbing a person's data on request). Different commands, different behaviour.

## Retention: pruning

Four scheduled prune commands drop data past its retention window. `bookings:prune` **soft-deletes** — the row and its personal data stay, for the legal or accounting record, and only drop out of the default queries.

| Command | Prunes | Retention key |
| --- | --- | --- |
| `bookings:prune` | Bookings past their window (soft-delete) | `retention.prune_after_days` (default 3 years) |
| `bookings:prune-notification-log` | Notification log rows | `retention.notification_log_days` (90) |
| `bookings:prune-webhook-deliveries` | Settled delivery attempts | `retention.webhook_delivery_days` (or `webhooks.delivery_retention_days`) |
| `bookings:prune-calendar-events` | Calendar mappings for bookings long over | `retention.calendar_events_ttl_days` (30) |

A window of zero or less is read as "not configured" and prunes nothing, rather than as "keep nothing" — a blank environment variable is a likelier way to reach zero than a retention policy is. That holds for `webhook_delivery_days` too: only leaving it *unset* defers to `webhooks.delivery_retention_days`, so zeroing it switches the prune off.

Windows are measured from the booking's **end time**, not the row's age, so a booking taken well ahead of time is not counted old the day it is made. Keep `notification_log_days` comfortably longer than your longest reminder in `hours_before`: the log row is what stops a reminder being sent twice. A `pending` webhook delivery is never pruned however old it is, because deleting it makes the delivery stop existing rather than fail.

```bash
php artisan bookings:prune --dry-run
```

## Erasure: right to be forgotten

`bookings:erase` is the other obligation. It **overwrites the personal columns in place** and marks the row erased, so aggregate reporting keeps working on a row that no longer names anyone. It is request-driven — never scheduled — and requires **exactly one** of `--booking` or `--email` (erasing "everything" or "nothing" is treated as a mistake):

```bash
# Scrub one booking by its reference number
php artisan bookings:erase --booking=BK-7F3A9C

# Scrub every booking for an email address
php artisan bookings:erase --email=customer@example.com

# Report what would be scrubbed, change nothing
php artisan bookings:erase --booking=BK-7F3A9C --dry-run
```

Erasure reaches soft-deleted bookings too, so a booking already pruned for retention is still reachable by the request to scrub it. A booking already erased reports success and does nothing.

> **There is no data-export / subject-access command.** GDPR support here is deletion and anonymisation only. Build an export from the models if you need to answer an access request.

## What erasure does not reach

The erasure routine sweeps `bookings` and `booking_notification_log` — **not** `storage/logs`. The `null` SMS driver writes phone numbers and appointment times to your log; erasing a booking does not remove those. If you leave the `sms` channel enabled without a real gateway in production, that is a disclosure you have to be able to make. See [Text Messages](Notifications-Sms).

The `ap.bookings.calendarSync.pullReceived` hook and any subscriber that logs a webhook payload can also put personal data outside the package's reach — treat both as third-party data. See [Hooks & Filters](Api-Hooks).

## Every destructive command takes `--dry-run`

`--dry-run` reports what a run would do and changes nothing. On `bookings:complete-past` that includes firing no hooks and dispatching no events, so a subscriber cannot email a customer about a completion that has not happened. See [Artisan Commands](Advanced-Artisan-Commands).
