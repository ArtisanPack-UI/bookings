---
title: Events
---

# Events

Every lifecycle change dispatches a typed event under `ArtisanPackUI\Bookings\Events`. Each uses `Dispatchable` + `SerializesModels`, has a `readonly` constructor, and **implements `ShouldDispatchAfterCommit`** — so a listener never runs before the row it describes is committed. None implement `ShouldQueue` by default; a listener may be queued because the payloads serialize.

These names are public API. They are what an application (and packages like a future `artisanpack-ui/crm`) subscribe to, and they will not be renamed without a deprecation cycle.

## Booking events

| Event | Constructor | Dispatched when |
| --- | --- | --- |
| `BookingRequested` | `( Booking $booking )` | A booking row is written (after the slot is held) |
| `BookingConfirmed` | `( Booking $booking, BookingActor $actor = System )` | A booking becomes an appointment |
| `BookingRescheduled` | `( Booking $booking, TimeRange $previousPeriod, BookingActor $actor = System )` | A booking's time changes |
| `BookingReassigned` | `( Booking $booking, ?int $previousProviderId, BookingActor $actor = System )` | A booking moves to another provider (`previousProviderId` null if previously unassigned) |
| `BookingCancelled` | `( Booking $booking, BookingActor $actor, ?string $reason = null )` | A booking is called off (`actor` deliberately non-defaulted) |
| `BookingCompleted` | `( Booking $booking, BookingActor $actor = System )` | A booking is marked as having happened |
| `BookingNoShow` | `( Booking $booking, BookingActor $actor = System )` | The customer did not arrive |

A booking that is auto-confirmed dispatches `BookingRequested` and then `BookingConfirmed` in the same request.

`BookingCancelled` carries a `BookingActor` because "the customer cancelled" and "we cancelled on the customer" are the same status change and completely different events downstream.

## Series events

| Event | Constructor | Notes |
| --- | --- | --- |
| `SeriesCreated` | `( BookingSeries $series, int $occurrenceCount )` | Once per series; each occurrence also fires `BookingRequested` |
| `SeriesEdited` | `( BookingSeries $series, SeriesEditScope $scope, BookingActor $actor = System, ?BookingSeries $splitSeries = null )` | `splitSeries` is set when a `ThisAndFollowing` edit split the series |
| `SeriesCancelled` | `( BookingSeries $series, BookingActor $actor, int $cancelledOccurrenceCount, ?string $reason = null )` | Constructor throws `InvalidArgumentException` on a negative count; each occurrence also fires `BookingCancelled` |

`SeriesEdited` carries a `SeriesEditScope` (`This`, `ThisAndFollowing`, or `All`) for the same reason `BookingCancelled` carries an actor. See [Recurring Bookings](Usage-Recurring-Bookings).

## Calendar & webhook events

| Event | Constructor | Notes |
| --- | --- | --- |
| `CalendarSynced` | `( CalendarConnection $connection, Booking $booking, string $externalEventId )` | Per booking per connection |
| `CalendarSyncFailed` | `( CalendarConnection $connection, string $reason, ?Booking $booking = null )` | Fires on **every** failed attempt, retries included; reason is a string (exceptions do not serialize) |
| `CalendarConnectionDisabled` | `( CalendarConnection $connection, string $reason )` | Dispatched from `CalendarConnection::disable()` |
| `WebhookDisabled` | `( Webhook $webhook, string $reason )` | Dispatched from `Webhook::disable()`; this is where an application tells a consumer their integration stopped |

## Listening

```php
use ArtisanPackUI\Bookings\Events\BookingConfirmed;
use Illuminate\Support\Facades\Event;

Event::listen( BookingConfirmed::class, function ( BookingConfirmed $event ) {
    // $event->booking, $event->actor
} );
```

## The package's own listeners

Three event subscribers are registered by the service provider. None is `ShouldQueue`:

| Subscriber | Reacts to | Does |
| --- | --- | --- |
| `SendBookingNotifications` | `BookingConfirmed`, `BookingCancelled`, `BookingRescheduled`, `BookingReassigned`, `BookingNoShow` | Sends notifications (no listener for `BookingRequested` — a requested booking is not yet confirmed) |
| `DispatchBookingWebhooks` | `BookingRequested`, `BookingConfirmed`, `BookingRescheduled`, `BookingReassigned`, `BookingCancelled`, `BookingCompleted`, `BookingNoShow` | Writes a delivery row per endpoint and queues its job |
| `SyncBookingToCalendar` | `BookingConfirmed`, `BookingRescheduled`, `BookingReassigned` | Pushes to / removes from connected calendars |

## Related

- [Hooks & Filters](Api-Hooks) — the `ap.bookings.*` action and filter that fire alongside each event
- [Creating a Booking](Usage-Creating-Bookings) — which method fires which event
