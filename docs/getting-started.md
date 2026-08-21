---
title: Quick Start Guide
---

# Quick Start Guide

Get from `composer require` to a live booking in under ten minutes.

## 1. Install

```bash
composer require artisanpack-ui/bookings
```

The service provider is auto-discovered. Run the migrations to create the booking tables:

```bash
php artisan migrate
```

That is the whole install. Publish the config only when you want to change a default:

```bash
php artisan vendor:publish --tag=bookings-config
```

## 2. Create a service and a provider

A **service** is what can be booked; a **provider** is who delivers it. Create one of each and link them:

```php
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;

$service = Service::create( [
    'name'     => 'Discovery Call',
    'slug'     => 'discovery-call',
    'duration' => 30,        // minutes
    'is_active' => true,
] );

$provider = ServiceProvider::create( [
    'name' => 'Alex Kim',
    'slug' => 'alex-kim',
] );

$provider->services()->attach( $service );
```

Give the provider a weekly availability schedule so slots can be computed. See [Usage Overview](Usage) for the availability model.

## 3. Take a booking

Book programmatically through `BookingService`. Naming a provider is optional — leave it out and the service's assignment strategy picks:

```php
use ArtisanPackUI\Bookings\Services\BookingService;

$booking = app( BookingService::class )->create( [
    'service'        => $service,
    'start_time'     => now()->addDay()->setTime( 15, 0 ),
    'customer_name'  => 'Sam Rivera',
    'customer_email' => 'sam@example.test',
] );
```

## 4. Or drop the widget on a page

The public widget walks a customer through service → provider → date/time → details and confirms in place. It works with and without JavaScript:

```blade
<livewire:artisanpack-booking-widget service="discovery-call" />
```

The widget is registered only when `livewire/livewire` is installed:

```bash
composer require livewire/livewire
```

## 5. Let customers manage their own booking

Every booking mints a **manage token** — a hashed, single-credential link that needs no account. Set the URL template of your manage page so it lands in confirmation emails:

```dotenv
ARTISANPACK_BOOKINGS_MANAGE_URL="https://example.test/bookings/manage/{token}"
```

See [Manage Tokens](Usage-Manage-Tokens) and the [Self-Serve Management Page](Usage-Self-Serve-Page).

## Next steps

- [Configuration](Installation-Configuration) — every key you can tune
- [Creating a Booking](Usage-Creating-Bookings) — the full lifecycle
- [Rate Limiting](Advanced-Rate-Limiting) and [Trusted Proxies](Advanced-Trusted-Proxies) — **read before putting the public routes in front of real traffic**
- [Outbound Webhooks](Notifications-Webhooks) — fan the lifecycle out to your own systems
