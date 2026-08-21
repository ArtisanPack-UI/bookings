---
title: Contracts
---

# Contracts

Six seams are interfaces under `ArtisanPackUI\Bookings\Contracts`. Bind your own implementation in a service provider and the package uses it instead of the default:

| Contract | Replaces | Default |
| --- | --- | --- |
| `SlotResolver` | how availability rules become bookable slots | `AvailabilityService` |
| `RoundRobinStrategy` | which provider is assigned to a slot | `LeastRecentlyAssignedStrategy` |
| `CalendarSyncDriver` | how one external calendar system is talked to | `IcalFeedDriver`, `GoogleCalendarDriver` |
| `NotificationChannel` | how one lifecycle message is delivered | CMS or Laravel channel |
| `SmsDriver` | which gateway a text message is handed to | `null` (logs only) |
| `MeetingTypeRegistry` | which shapes a service can be booked in | `MeetingTypeRegistry` |

## Binding a contract

```php
use ArtisanPackUI\Bookings\Contracts\RoundRobinStrategy;

public function register(): void
{
    $this->app->bind( RoundRobinStrategy::class, MyFairnessStrategy::class );
}
```

## `MeetingTypeRegistry` is the odd one out

Bind it only to replace the registry *itself*. To add a meeting type — the common case — implement `Contracts\MeetingType` (or construct a `RegisteredMeetingType`) and contribute it through the `ap.bookings.registeredMeetingTypes` filter. No binding required. See [Hooks & Filters](Api-Hooks).

## `CalendarSyncDriver`

The calendar driver contract is a seam and a plugin point at once: bind or register a driver to talk to a calendar system the package does not ship. Its full method set and rules are covered under [Calendar Sync](Integrations-Calendar-Sync).

## `SmsDriver`

One method — `send( string $phone, string $message ): void` — handed a number and a string, knowing nothing about bookings. Name your class in `notifications.sms_driver` or bind the contract. See [Text Messages](Notifications-Sms).

## Site resolution is not on this list

Site resolution is deliberately not a bookings contract. It is `ArtisanPackUI\Core\Contracts\SiteResolver`, bound once for the whole ecosystem — see [Multi-Site](Advanced-Multi-Site).
