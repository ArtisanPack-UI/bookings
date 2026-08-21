---
title: Models
---

# Models

Eloquent models live under `ArtisanPackUI\Bookings\Models`. Every owned table carries a nullable `site_id` and the models that use `Models\Concerns\BelongsToSite` filter on the current site — see [Multi-Site](Advanced-Multi-Site).

## Core

### Service

What can be booked. Fillable:

```
name, slug, description, duration, buffer_before, buffer_after, price, is_free,
max_bookings_per_slot, is_active, intake_schema, intake_schema_version,
assignment_strategy, default_provider_id, image_media_id, image_url, color,
timezone, metadata
```

`site_id` is deliberately **not** fillable (it is resolved from context, never from request data), and neither is `pii_erased_at` (only the erasure routine sets it). Relationships: `providers()` (many-to-many), `bookings()`, `blackoutDates()`.

### ServiceProvider

Who delivers a service. Fillable:

```
user_id, name, slug, email, phone, bio, timezone, image_media_id, image_url,
round_robin_weight, round_robin_last_assigned_at, sort_order, is_active, metadata
```

`user_id` links an optional account (nullable, no FK). Relationships: `services()` (many-to-many), `availabilitySchedules()`, `availabilityOverrides()`, `bookings()`, `calendarConnections()`. The `ical_token_hash` column holds `sha256` of the provider's feed token — see [iCal Feeds](Usage-Ical-Feeds).

### Booking

A single appointment. Carries the customer's name, email, phone, and timezone; the service and provider; the UTC start/end; the `status`; a snapshot of the intake schema and answers; the `booking_number`; and `manage_token_hash` (`sha256` of the manage token). Soft-deletes. `pii_erased_at` marks a row scrubbed by [erasure](Advanced-Gdpr-Data-Retention). Status is a `BookingStatus` enum; transitions go through [`BookingService`](Api-Services), never a direct write.

### BookingSeries

A recurring arrangement: the `rrule`, the floating `dtstart_local` + `dtstart_timezone`, and the pinned provider. `occurrences()` are ordinary `Booking` rows linked by `series_id`. See [Recurring Bookings](Usage-Recurring-Bookings).

## Availability

| Model | Purpose |
| --- | --- |
| `AvailabilitySchedule` | A provider's recurring weekly availability |
| `AvailabilityOverride` | A one-off change to a provider's availability |
| `ServiceBlackoutDate` | Dates a service cannot be booked |
| `ServiceProviderService` | The service ↔ provider pivot |

## Calendar

| Model | Purpose |
| --- | --- |
| `CalendarConnection` | A provider's link to an external calendar (driver, sync mode, health, `oauth_connection_id`) |
| `CalendarEvent` | The mapping between a booking and its external event id |
| `CalendarBusyBlock` | An external busy period read back by a two-way connection |
| `CalendarWatchChannel` | A push registration (Google/Microsoft), renewed before it lapses |

`CalendarConnection::disable()` dispatches the `CalendarConnectionDisabled` event. See [Calendar Sync](Integrations-Calendar-Sync).

## Webhooks & notifications

| Model | Purpose |
| --- | --- |
| `Webhook` | A subscribed endpoint (url, secret, events, health). `disable()` dispatches `WebhookDisabled` |
| `WebhookDelivery` | The per-attempt delivery ledger |
| `NotificationLog` | The idempotency log that claims a notification before it is sent |

## Schema versions

| Model | Purpose |
| --- | --- |
| `IntakeSchemaVersion` | A historical intake schema a booking's answers were captured against |

## Factories

Model factories resolve to `ArtisanPackUI\Bookings\Database\Factories\<Model>Factory` and are used throughout the test suite. See [Troubleshooting](Troubleshooting) for the testing setup.
