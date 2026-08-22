---
title: API Reference Overview
---

# API Reference

Technical reference for ArtisanPack UI Bookings classes, endpoints, events, and hooks. The package's programmatic surface is small and deliberate: a handful of services own the writes, models own the reads, and every state change announces itself through an event and a hook.

## In this section

- [REST API](Api-Rest-Api) - The public JSON HTTP endpoints
- [Services](Api-Services) - `BookingService`, `SeriesService`, and the token services
- [Models](Api-Models) - Eloquent model reference
- [Events](Api-Events) - Event classes and listeners
- [Hooks & Filters](Api-Hooks) - The full `ap.bookings.*` registry
- [Contracts](Api-Contracts) - The rebindable seams

## The shape of it

```
BookingService ──► writes bookings, fires events + hooks
SeriesService  ──► writes recurring series (delegates each occurrence to BookingService)
ManageTokenService / IcalTokenService ──► mint, hash, verify credentials
AvailabilityService ──► resolves bookable slots (the SlotResolver seam)

Events (ShouldDispatchAfterCommit) ──► SendBookingNotifications
                                   ──► DispatchBookingWebhooks
                                   ──► SyncBookingToCalendar

Hooks (ap.bookings.*) ──► your addAction / addFilter callbacks
```

## Three rules that run through everything

1. **Go through the services.** Flipping a booking's status directly skips the event and hook that transition fires, so anything downstream — a calendar push, a confirmation email, a CRM record — either never hears about it or hears about it twice. Always call `BookingService::confirm()`, `cancel()`, and so on.

2. **Events fire after commit.** Every event implements `ShouldDispatchAfterCommit`, so a listener never runs before the row it describes is durably written. None implement `ShouldQueue` by default.

3. **Hooks name their intent.** Actions observe; filters transform and must return a value. Names take an `ap.` prefix, `.`-separated segments, and camelCase within each segment.

## Services at a glance

| Service | Description |
| --- | --- |
| `BookingService` | The front door to a booking's whole lifecycle |
| `SeriesService` | Recurring (RFC 5545) series |
| `ManageTokenService` | Mint, hash, and verify customer manage tokens |
| `IcalTokenService` | Mint, rotate, and revoke provider feed tokens |
| `AvailabilityService` | Resolve bookable slots (bound to `SlotResolver`) |
| `NotificationService` | Send booking notifications |
| `WebhookDispatcher` | Queue outbound webhook deliveries |
| `CalendarSyncOrchestrator` | Fan a booking out to connected calendars |

See [Services](Api-Services) for the method reference.

## Models at a glance

| Model | Description |
| --- | --- |
| `Service` | What can be booked |
| `ServiceProvider` | Who delivers a service |
| `Booking` | A single appointment |
| `BookingSeries` | A recurring arrangement |
| `AvailabilitySchedule` / `AvailabilityOverride` | When a provider is bookable |
| `ServiceBlackoutDate` | Dates a service is unavailable |
| `CalendarConnection` / `CalendarEvent` / `CalendarBusyBlock` / `CalendarWatchChannel` | Calendar sync state |
| `Webhook` / `WebhookDelivery` | Outbound webhooks and their delivery ledger |
| `NotificationLog` | The idempotency log for notifications |

See [Models](Api-Models) for fields and relationships.
