---
title: Hooks & Filters
---

# Hooks & Filters

Actions and filters are registered through `artisanpack-ui/hooks` (`addAction` / `addFilter`, `doAction` / `applyFilters`). This page is the full `ap.bookings.*` registry.

## The naming convention

Names take an `ap.` prefix, `.`-separated segments, and camelCase within each segment — so both `ap.bookings.registeredMeetingTypes` and grouped names like `ap.bookings.calendarSync.providers` are well formed. Never snake_case. Follow the same convention when you name your own hooks in adjacent packages.

**Actions fire; filters transform a value and must return one.** An action is how a plugin *observes* the package; a filter is how it *changes* the package's behaviour.

## The registry

| Hook | Type | Payload |
| --- | --- | --- |
| `ap.bookings.creating` | action | `(array $attributes, ?Authenticatable $customer)` |
| `ap.bookings.created` | action | `(Booking $booking)` |
| `ap.bookings.confirmed` | action | `(Booking $booking)` |
| `ap.bookings.rescheduling` | action | `(Booking $booking, CarbonImmutable $newStart)` |
| `ap.bookings.rescheduled` | action | `(Booking $booking, CarbonImmutable $oldStart)` |
| `ap.bookings.cancelling` | action | `(Booking $booking, string $reason)` |
| `ap.bookings.cancelled` | action | `(Booking $booking)` |
| `ap.bookings.completed` | action | `(Booking $booking)` |
| `ap.bookings.noShow` | action | `(Booking $booking)` |
| `ap.bookings.reassigned` | action | `(Booking $booking, ?int $previousProviderId)` |
| `ap.bookings.series.editApplying` | action | `(BookingSeries $series, string $scope, array $changes)` |
| `ap.bookings.manageTokenReissued` | action | `(Booking $booking, string $plainToken)` |
| `ap.bookings.manageTokensReissued` | action | `(int $count)` |
| `ap.bookings.icalTokenIssued` | action | `(ServiceProvider $provider, string $plainToken)` |
| `ap.bookings.icalTokenRevoked` | action | `(ServiceProvider $provider)` |
| `ap.bookings.calendarSync.providers` | filter | `(array $drivers)` |
| `ap.bookings.calendarSync.pushing` | action | `(Booking $booking, string $providerSlug)` |
| `ap.bookings.calendarSync.pushed` | action | `(Booking $booking, string $providerSlug, string $externalEventId)` |
| `ap.bookings.calendarSync.pullReceived` | action | `(array $payload, string $providerSlug)` |
| `ap.bookings.calendarSync.eventPayload` | filter | `(array $payload, Booking $booking, string $providerSlug)` |
| `ap.bookings.calendarSync.connectionDisabled` | action | `(CalendarConnection $connection, string $reason)` |
| `ap.bookings.availableProviders` | filter | `(array $providers, Service $service, CarbonImmutable $start)` |
| `ap.bookings.roundRobin.selectProvider` | filter | `(?ServiceProvider $selected, array $candidates, Booking $draft)` |
| `ap.bookings.intakeSchema` | filter | `(array $schema, Service $service, int $version)` |
| `ap.bookings.availabilityQuery` | filter | `(Builder $query, array $criteria)` |
| `ap.bookings.availableSlots` | filter | `(array $slots, ServiceProvider $provider, CarbonPeriod $window)` |
| `ap.bookings.slotBookable` | filter | `(bool $bookable, Slot $slot, ?Authenticatable $customer)` |
| `ap.bookings.slotDuration` | filter | `(int $minutes, Service $service, ServiceProvider $provider)` |
| `ap.bookings.registeredMeetingTypes` | filter | `(array $types)` |
| `ap.bookings.notification.sending` | filter | `(BookingNotification $notification, Booking $booking)` |
| `ap.bookings.notification.channels` | filter | `(array $channels, string $event, Booking $booking)` |
| `ap.bookings.notification.subject` | filter | `(string $subject, BookingNotification $notification, Booking $booking)` |
| `ap.bookings.reminderScheduling` | filter | `(array $hoursBefore, Booking $booking)` |

## Fire-count guarantees

Lifecycle actions fire **once per transition** — each guards the transition first, so only the caller that actually moved the row fires the hook and its event. Two exceptions:

- **`ap.bookings.creating`** fires inside the slot lock and can fire **more than once** for a single `create()` call — once per provider tried, when a lost race falls through to the next candidate. **`ap.bookings.created`** fires exactly once. Neither can cancel a booking: subscribe to the `BookingRequested` event and cancel it there, which is a real cancellation with a freed slot rather than an abort inside a held lock.
- **`ap.bookings.slotBookable`** fires once per slot, in a loop; **`ap.bookings.availableProviders`** and **`roundRobin.selectProvider`** fire once per booking operation (so a bulk reassign over N bookings fires each N times).

`ap.bookings.manageTokenReissued` fires once per booking rotated; `manageTokensReissued` fires once at the end.

## Rules worth stating outright

**`ap.bookings.roundRobin.selectProvider`** returning `null` means "no opinion" and leaves the default rota's answer standing. Returning somebody who is not in `$candidates` throws — they were not free for the slot. It does not fire when the customer named their provider by hand.

**`ap.bookings.series.editApplying`** fires once per scoped series edit, before any of it lands, so a subscriber reading the series back still sees the rule it is about to replace. `$scope` is `this`, `this_and_following`, or `all`. The occurrences a series edit writes and discards fire `ap.bookings.created` and `ap.bookings.cancelled` once each, per occurrence.

**`ap.bookings.manageTokenReissued`** and **`ap.bookings.icalTokenIssued`** carry a live secret — the plain token, at the only moment it is readable. They exist so an emergency rotation can be followed by new links reaching customers (or a new subscription URL reaching a provider). Do not log the token, and do not put it anywhere the hash was kept out of. `icalTokenIssued` fires on a rotation as well as a first issue, and by then the provider's previous token is already dead.

**`ap.bookings.calendarSync.pullReceived`** carries the external calendar's raw change feed — personal data that is not this package's: event titles and descriptions, organiser and attendee email addresses, the shape of a private diary. Do not log it, and treat anything drawn from it as third-party personal data.

**`ap.bookings.intakeSchema`** runs against the version a booking was captured with rather than the service's current form, and its output is never written back — a subscriber describes how a form should be *read*, not edits the record of what was asked.

**The four notification filters** all run *before* the log row is claimed, which keeps them inside the idempotency guarantee. `ap.bookings.notification.sending` returns the notification, a replacement, or `null` to suppress the send; returning anything else throws. It runs once per channel, so suppressing the customer's email still leaves the admin's database copy. `ap.bookings.reminderScheduling` filters the cadence in whole hours before the start; duplicate windows are collapsed, and a window longer than anything in config also needs `notifications.reminder.max_lookahead_hours` raised.

## Registering a meeting type

Meeting types are contributed through a filter rather than being hard-coded:

```php
use ArtisanPackUI\Bookings\MeetingTypes\RegisteredMeetingType;

addFilter( 'ap.bookings.registeredMeetingTypes', function ( array $types ): array {
    $types[] = new RegisteredMeetingType(
        'webinar',
        'Webinar',
        'Broadcast to many attendees at once.',
        allowsMultipleAttendees: true,
    );

    return $types;
} );
```

Pass the label and description untranslated — they are used as translation keys and run through `__()` when read. The filter runs on every read, so registering from a later-booting service provider still works. Entries are keyed by the type's own `key()`, so registering under an existing key (`one_to_one`, `group`, `recurring`, `round_robin`) replaces the built-in.

## The machine-readable registry

`Support\HookRegistry` is the machine-readable version of the table above — including hooks whose surfaces (calendar sync, notifications) may not be built yet, so a subscriber can be written against a name before the code that fires it exists:

```php
use ArtisanPackUI\Bookings\Support\HookRegistry;

HookRegistry::all();      // every hook, with its type and the issue that fires it
HookRegistry::shipped();  // the ones firing today
HookRegistry::pending();  // declared, not yet fired
```

Nothing inside the package reads it; hook names stay as literals at their call sites. It exists for the test that holds the two lists together — every shipped name is fired somewhere in `src/`, and every `ap.bookings.*` literal in `src/` is declared here — so a surface cannot ship without its hook, and a hook cannot ship undocumented.

## Subscribing to another package's hooks

When you bind bookings behaviour to an optional package, gate it through `Support\HookSubscriptions` so a callback naming absent classes is never entered:

```php
use ArtisanPackUI\Bookings\Support\HookSubscriptions;

HookSubscriptions::whenInstalled( 'forms', function (): void {
    addFilter( 'ap.forms.fieldTypes', /* ... */ );
} );
```

Upstream hooks keep their upstream names — this package does not rename another package's hooks. See [Integrations](Integrations).
