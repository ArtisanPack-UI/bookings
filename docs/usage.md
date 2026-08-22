---
title: Usage Overview
---

# Usage

Learn how to model, take, and manage bookings in your Laravel application. There are three ways to work with them:

1. **Programmatic API** — `BookingService` and `SeriesService` for custom flows
2. **Public widget** — a drop-in Livewire booking flow for customers
3. **Admin surface** — staff-facing screens for everything behind the scenes

## In this section

- [Creating a Booking](Usage-Creating-Bookings) - The full booking lifecycle through `BookingService`
- [Recurring Bookings](Usage-Recurring-Bookings) - RFC 5545 series, occurrences, and scoped edits
- [Public Booking Widget](Usage-Booking-Widget) - The Livewire widget that works with and without JavaScript
- [Manage Tokens](Usage-Manage-Tokens) - Account-free self-serve management links
- [Self-Serve Management Page](Usage-Self-Serve-Page) - The customer-facing manage component
- [iCal Feeds](Usage-Ical-Feeds) - Subscribable provider and customer calendars
- [Admin Surface](Usage-Admin-Surface) - The staff-facing screens

## The entry points

The package's behaviour lives in its domain services, which you resolve from the container:

```php
use ArtisanPackUI\Bookings\Services\BookingService;
use ArtisanPackUI\Bookings\Services\SeriesService;

$bookings = app( BookingService::class );   // create, confirm, reschedule, cancel, complete, no-show
$series   = app( SeriesService::class );    // recurring bookings and scoped edits
```

The `Bookings` facade and the `bookings()` helper exist for ecosystem consistency and resolve the package's container binding, but the work is done through the services above.

## The domain model

A booking is built from three things you model first:

- **Service** (`Models\Service`) — what can be booked: a name, a slug, a duration, buffers, a price, an intake schema, and an assignment strategy.
- **ServiceProvider** (`Models\ServiceProvider`) — who delivers a service: a name, a slug, a timezone, and (optionally) a linked user account. Providers are attached to services many-to-many.
- **Availability** — when a provider is bookable, expressed as weekly `AvailabilitySchedule` rows, one-off `AvailabilityOverride` rows, and `ServiceBlackoutDate` exclusions.

```php
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;

$service = Service::create( [
    'name'      => 'Discovery Call',
    'slug'      => 'discovery-call',
    'duration'  => 30,
    'is_active' => true,
] );

$provider = ServiceProvider::create( [
    'name' => 'Alex Kim',
    'slug' => 'alex-kim',
] );

$provider->services()->attach( $service );
```

Most teams create and manage these through the [Admin Surface](Usage-Admin-Surface) rather than by hand. See [Models](Api-Models) for the full field and relationship reference.

## Availability & slots

Availability is computed on demand: given a service, a provider, and a date range, `AvailabilityService` (bound to the `SlotResolver` contract) resolves the bookable slots — honouring the provider's schedule, existing bookings, calendar busy blocks, blackout dates, buffers, and the `slot_interval`. Results are cached per service / provider / provider-local date and invalidated automatically when anything that affects them is written.

Subscribers can shape slots through filters — `ap.bookings.availableSlots`, `ap.bookings.slotBookable`, `ap.bookings.slotDuration`, and `ap.bookings.availabilityQuery`. See [Hooks & Filters](Api-Hooks).

## Quick reference

### Create a booking

```php
use ArtisanPackUI\Bookings\Services\BookingService;

$booking = app( BookingService::class )->create( [
    'service'        => $service,
    'start_time'     => $start,
    'customer_name'  => 'Sam Rivera',
    'customer_email' => 'sam@example.test',
] );
```

### Drop the widget on a page

```blade
<livewire:artisanpack-booking-widget service="discovery-call" />
```

### Access the admin surface

Navigate to `/bookings-admin` (or your configured prefix) to manage bookings, services, providers, schedules, series, calendar connections, webhooks, and settings. See [Admin Surface](Usage-Admin-Surface).
