---
title: Creating a Booking
---

# Creating a Booking

`Services\BookingService` is the front door to a booking's whole life. Creating one names a service, a start time, and the customer; naming a provider is optional, and leaving it out lets the service's assignment strategy pick:

```php
use ArtisanPackUI\Bookings\Services\BookingService;

$booking = app( BookingService::class )->create( [
    'service'           => $service,
    'start_time'        => $start,          // any Carbon or parseable string
    'customer_name'     => 'Sam Rivera',
    'customer_email'    => 'sam@example.test',
    'customer_timezone' => 'America/Chicago',
    'intake_data'       => [ 'goal' => 'Learn to juggle' ],
] );
```

## The slot lock

The whole read-availability-and-write sequence runs behind a lock on the provider's local **day**, so two customers after the same provider are decided before either reaches the database. A day rather than a slot, because bookable slots overlap: at the default fifteen-minute interval a sixty-minute service offers one every quarter hour, and per-slot locks would let 09:00 and 09:15 race through separately and double-book the provider for forty-five minutes.

Postgres and MySQL use the server's own advisory locks, which hold across every process talking to that database. Every other engine — sqlite, chiefly — has no such primitive, so the cache store's lock stands in: that is exclusive within one application server and only as wide as the cache store behind it, so point `artisanpack.bookings.lock.store` at a shared one if you run more than one.

Either way the lock is a first line of defence rather than the last. If a request still loses the race, the partial unique index on `bookings` catches it and the round-robin assigner falls through to the next free provider. `create()` throws `Exceptions\SlotUnavailableException` only when nobody at all could take the slot.

## Intake validation

Intake answers are validated against the service's current form and that version is snapshotted onto the booking, so the answers stay readable after an administrator edits the form. Answers the form did not ask for are dropped; answers it did ask for and did not get raise `Exceptions\IntakeValidationException`, which carries a `MessageBag`.

## The rest of the lifecycle

The rest of the lifecycle goes through the same service, and must — each transition takes an actor:

```php
use ArtisanPackUI\Bookings\Enums\BookingActor;

$bookings = app( BookingService::class );

$bookings->confirm( $booking, BookingActor::Admin );
$bookings->reschedule( $booking, $newStart, BookingActor::Customer );
$bookings->cancel( $booking, BookingActor::Customer, 'Something came up.' );
$bookings->complete( $booking, BookingActor::Provider );
$bookings->markNoShow( $booking, BookingActor::Admin );
```

Flipping a status directly would skip the action and the event that transition fires, so anything downstream — a calendar push, a confirmation email, a CRM record — would either never hear about it or hear about it twice.

## What each transition fires

Every transition dispatches a typed event and fires a matching hook. The events all implement `ShouldDispatchAfterCommit`, so a listener never runs before the row it describes is committed:

| Method | Event | Hook |
| --- | --- | --- |
| `create()` | `BookingRequested` (and `BookingConfirmed` if auto-confirm) | `ap.bookings.creating`, `ap.bookings.created` |
| `confirm()` | `BookingConfirmed` | `ap.bookings.confirmed` |
| `reschedule()` | `BookingRescheduled` | `ap.bookings.rescheduling`, `ap.bookings.rescheduled` |
| `reassign()` | `BookingReassigned` | `ap.bookings.reassigned` |
| `cancel()` | `BookingCancelled` | `ap.bookings.cancelling`, `ap.bookings.cancelled` |
| `complete()` | `BookingCompleted` | `ap.bookings.completed` |
| `markNoShow()` | `BookingNoShow` | `ap.bookings.noShow` |

`BookingActor` distinguishes *who* acted — `Customer`, `Admin`, `Provider`, or `System` — because "the customer cancelled" and "we cancelled on the customer" are the same status change and completely different events downstream.

See [Events](Api-Events) for the constructor payloads and [Hooks & Filters](Api-Hooks) for the fire-count semantics.

## Related

- [Recurring Bookings](Usage-Recurring-Bookings)
- [Services](Api-Services) — the full `BookingService` method reference
- [Outbound Webhooks](Notifications-Webhooks) — the lifecycle fanned out to your systems
