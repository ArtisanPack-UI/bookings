---
title: Calendar Sync
---

# Calendar Sync

Beyond the subscribable [iCal feeds](Usage-Ical-Feeds), the package supports two-way calendar connections through pluggable drivers. A connection pushes bookings out to an external calendar and (in two-way mode) reads busy blocks back so a provider's outside commitments block bookable slots.

## The drivers

| Driver | Enum case | Ships in | Push or poll |
| --- | --- | --- | --- |
| iCal feed | `Ical` | this package (always on) | outbound only |
| Google | `Google` | this package, gated on `artisanpack-ui/google` | push (watch channels) |
| Microsoft / Outlook | `Microsoft` | `artisanpack-ui/microsoft` | push (watch channels) |
| Apple / CalDAV | `Apple` | `artisanpack-ui/apple` | polled |

The `IcalFeedDriver` and `GoogleCalendarDriver` are implemented in this package. Microsoft and Apple are recognised by the `CalendarDriver` enum but delivered by companion packages — a connection whose driver package is not installed simply has nothing to sync it.

Two-way busy blocks are re-read by `bookings:calendar-refresh`, which routes each due connection to its driver and runs the driver's own incremental read — the bundled Google driver reads its change feed in as busy blocks here, so a dropped push notification still leaves availability correct within a day.

Google and Microsoft support push registrations ("watch channels"). Registering those channels, receiving the calendar's notifications, and renewing the channels all need a callback URL and an inbound route that belong to the driver package, not to this one — so `bookings:calendar-watch-renew` does not renew channels itself. It finds the channels that are due and offers them to the `ap.bookings.calendarSync.renewChannels` filter, which the driver package subscribes to; with no subscriber (the state as this package ships, since it registers no channels of its own) the sweep reports the due channels as unrenewable. Apple has no push, so it is polled by `bookings:calendar-apple-poll`. See [Artisan Commands](Advanced-Artisan-Commands).

## Enabling a driver

Each driver is opt-in per driver:

```php
// config/artisanpack/bookings.php
'calendar' => [
    'drivers' => [
        'google'    => [ 'enabled' => env( 'BOOKING_GOOGLE_ENABLED', false ) ],
        'microsoft' => [ 'enabled' => env( 'BOOKING_MICROSOFT_ENABLED', false ) ],
        'apple'     => [ 'enabled' => env( 'BOOKING_APPLE_ENABLED', false ) ],
    ],
],
```

Enabling Google also requires `artisanpack-ui/google` installed: the driver is only pushed onto the registry when `google.enabled` is true *and* the Google token provider is bound in the container.

## Sync modes & health

- `calendar.default_sync_mode` — `outbound` (push only) or two-way (also read busy blocks).
- `calendar.two_way_lookahead_days` (60) — how far ahead two-way sync reads busy blocks.
- `calendar.two_way_grace_hours` (6) — grace before a failing two-way connection is downgraded.
- `calendar.connection_failure_threshold` (5) — consecutive failures before a connection stops retrying.

A disable or downgrade dispatches the `CalendarConnectionDisabled` event and fires `ap.bookings.calendarSync.connectionDisabled`.

## The driver contract

A driver implements `Contracts\CalendarSyncDriver`:

```php
namespace ArtisanPackUI\Bookings\Contracts;

interface CalendarSyncDriver
{
    public function driver(): CalendarDriver;
    public function createEvent( CalendarConnection $connection, Booking $booking ): string;
    public function updateEvent( CalendarConnection $connection, Booking $booking, string $externalEventId ): string;
    public function deleteEvent( CalendarConnection $connection, string $externalEventId ): void;
    public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array;
}
```

Writes must be idempotent (keyed on the external event id), and failures must **throw** — a swallowed error would make a dead connection look healthy, whereas repeated throws raise the connection's `consecutive_failure_count` and eventually disable it. `busyPeriods()` is called only for two-way connections and returns UTC busy ranges.

## Registering a driver

Drivers are contributed through the `ap.bookings.calendarSync.providers` filter, applied on every registry read so a late-booting provider package still registers. Keyed by each driver's own `driver()->value`:

```php
use ArtisanPackUI\Bookings\Contracts\CalendarSyncDriver;

addFilter( 'ap.bookings.calendarSync.providers', function ( array $drivers ): array {
    $drivers[] = app( MyCalDavDriver::class );

    return $drivers;
} );
```

## Sync hooks

The sync path fires several hooks a subscriber can observe or shape:

| Hook | Type | Fires |
| --- | --- | --- |
| `ap.bookings.calendarSync.pushing` | action | Before a booking is dispatched to a connection |
| `ap.bookings.calendarSync.pushed` | action | After an external event is written/updated |
| `ap.bookings.calendarSync.eventPayload` | filter | Shapes the outbound event payload (must keep start/end) |
| `ap.bookings.calendarSync.pullReceived` | action | On a raw change feed from a two-way pull (**personal data — do not log**) |
| `ap.bookings.calendarSync.connectionDisabled` | action | When a connection is disabled or downgraded |

See [Hooks & Filters](Api-Hooks) for the payloads and handling notes.

## OAuth (Google)

The OAuth grant itself is owned by the companion `artisanpack-ui/google` package; this package only asks it for a valid bearer token. A `CalendarConnection` carries an `oauth_connection_id` into Google's own OAuth table, and `Support\Google\OAuthGoogleTokenProvider` maps that id to a token, refreshing when it has lapsed. The connection UI is an admin screen (`calendar-connections`); the authorize/callback screens live in the Google package.
