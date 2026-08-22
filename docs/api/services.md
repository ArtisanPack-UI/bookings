---
title: Services
---

# Services

The write API. Resolve any of these through the container (`app( BookingService::class )`); the entry-point services are registered as singletons.

## BookingService

`ArtisanPackUI\Bookings\Services\BookingService` — the front door to a booking's whole life.

```php
public function create(
    array $attributes,
    ?Authenticatable $customer = null,
    bool $validateIntake = true
): Booking;

public function createFromFormSubmission( array $submission, ?Authenticatable $customer = null ): Booking;
public function formSubmissionIsBookable( array $submission ): bool;

public function confirm( Booking $booking, BookingActor $actor = BookingActor::System ): Booking;
public function reschedule( Booking $booking, CarbonInterface $newStart, BookingActor $actor = BookingActor::System ): Booking;
public function reassign( Booking $booking, BookingActor $actor = BookingActor::System ): Booking;
public function cancel( Booking $booking, BookingActor $actor, ?string $reason = null ): Booking;
public function complete( Booking $booking, BookingActor $actor = BookingActor::System ): Booking;
public function markNoShow( Booking $booking, BookingActor $actor = BookingActor::System ): Booking;
```

`create()` accepts `service`, `start_time`, `customer_name`, `customer_email`, and optionally `provider`, `customer_timezone`, `customer_phone`, `intake_data`, and `notes`. It runs behind the provider-day slot lock, validates intake against the service's current schema, and throws `SlotUnavailableException` when nobody can take the slot or `IntakeValidationException` (carrying a `MessageBag`) when intake fails. `cancel()`'s `$actor` is deliberately non-defaulted. See [Creating a Booking](Usage-Creating-Bookings).

## SeriesService

`ArtisanPackUI\Bookings\Services\SeriesService` — recurring arrangements. Each occurrence is written through `BookingService`, so it takes the slot lock and fires the usual lifecycle hooks.

```php
public function create( array $attributes, ?Authenticatable $customer = null ): BookingSeries;

public function edit(
    BookingSeries $series,
    SeriesEditScope $scope,
    array $changes,
    ?Booking $occurrence = null,
    BookingActor $actor = BookingActor::System
): BookingSeries;

public function cancel( BookingSeries $series, BookingActor $actor, ?string $reason = null ): BookingSeries;
public function expand( /* … */ ): array;
public function expandSeries( BookingSeries $series ): array;
```

`create()` takes `rrule`, `dtstart_local`, and `dtstart_timezone` alongside the booking attributes. See [Recurring Bookings](Usage-Recurring-Bookings).

## ManageTokenService

`ArtisanPackUI\Bookings\Services\ManageTokenService` — the one place a customer manage token is minted, hashed, and checked.

```php
public function findBooking( string $token ): ?Booking;   // null when unknown
public function verifyFor( Booking $booking, string $token ): bool;   // hash_equals
public function issueFor( Booking $booking ): string;     // rotates — old link dies
public function reissueAll( /* … */ ): int;               // rotate every token
```

See [Manage Tokens](Usage-Manage-Tokens).

## IcalTokenService

`ArtisanPackUI\Bookings\Services\IcalTokenService` — provider calendar feed tokens, sharing its primitives with `ManageTokenService`.

```php
public function issueFor( ServiceProvider $provider ): string;   // shown once
public function revokeFor( ServiceProvider $provider ): void;
public function feedUrl( string $token ): string;
```

See [iCal Feeds](Usage-Ical-Feeds).

## AvailabilityService

`ArtisanPackUI\Bookings\Services\AvailabilityService` — resolves bookable slots and is bound to the `SlotResolver` contract. Results are cached per service / provider / provider-local date and invalidated on writes. Shape slots through `ap.bookings.availableSlots`, `slotBookable`, `slotDuration`, and `availabilityQuery` — see [Hooks & Filters](Api-Hooks).

## Delivery services

- `NotificationService` — sends booking notifications across the configured channels. See [Notifications](Notifications).
- `WebhookDispatcher` — queues an outbound delivery per subscribed endpoint: `dispatch( string $event, array $payload, int|string|null $siteId = null )`. See [Webhooks](Notifications-Webhooks).
- `CalendarSyncOrchestrator` — fans a confirmed/rescheduled/reassigned booking out to connected calendars. See [Calendar Sync](Integrations-Calendar-Sync).
