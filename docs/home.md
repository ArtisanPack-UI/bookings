---
title: ArtisanPack UI Bookings Documentation Home
---

# ArtisanPack UI Bookings Documentation

Welcome to the documentation for the ArtisanPack UI Bookings package. Bookings is an appointment scheduling and booking management system for Laravel — services, providers, availability, one-off and recurring bookings, calendar sync, outbound webhooks, notifications, GDPR tooling, and a public booking widget that works with or without JavaScript.

The package runs standalone in any Laravel application and uses the rest of the ArtisanPack UI ecosystem when it is installed, degrading cleanly when it is not.

## Table of Contents

- **Getting Started**
  - [Quick Start Guide](Getting-Started)

- **Installation**
  - [Installation Overview](Installation)
  - [Requirements](Installation-Requirements)
  - [Configuration](Installation-Configuration)

- **Usage**
  - [Usage Overview](Usage)
  - [Creating a Booking](Usage-Creating-Bookings)
  - [Recurring Bookings](Usage-Recurring-Bookings)
  - [Public Booking Widget](Usage-Booking-Widget)
  - [Manage Tokens](Usage-Manage-Tokens)
  - [Self-Serve Management Page](Usage-Self-Serve-Page)
  - [iCal Feeds](Usage-Ical-Feeds)
  - [Admin Surface](Usage-Admin-Surface)

- **Notifications & Webhooks**
  - [Notifications Overview](Notifications)
  - [Email Notifications](Notifications-Email)
  - [Reminders](Notifications-Reminders)
  - [Text Messages (SMS)](Notifications-Sms)
  - [Outbound Webhooks](Notifications-Webhooks)

- **Integrations**
  - [Integrations Overview](Integrations)
  - [Calendar Sync](Integrations-Calendar-Sync)
  - [CMS Framework](Integrations-Cms-Framework)
  - [Forms](Integrations-Forms)
  - [Media Library](Integrations-Media-Library)

- **Frontend (React & Vue)**
  - [Frontend Overview](Frontend)
  - [React Components](Frontend-React)
  - [Vue Components](Frontend-Vue)
  - [Headless Client](Frontend-Headless-Client)

- **API Reference**
  - [API Overview](Api)
  - [REST API](Api-Rest-Api)
  - [Services](Api-Services)
  - [Models](Api-Models)
  - [Events](Api-Events)
  - [Hooks & Filters](Api-Hooks)
  - [Contracts](Api-Contracts)

- **Advanced**
  - [Advanced Overview](Advanced)
  - [Multi-Site](Advanced-Multi-Site)
  - [Rate Limiting](Advanced-Rate-Limiting)
  - [Trusted Proxies](Advanced-Trusted-Proxies)
  - [Webhook Security (SSRF)](Advanced-Webhook-Security)
  - [GDPR, Retention & Erasure](Advanced-Gdpr-Data-Retention)
  - [Artisan Commands](Advanced-Artisan-Commands)
  - [Performance](Advanced-Performance)

- **Help**
  - [FAQ](Faq)
  - [Troubleshooting](Troubleshooting)

## Features

- **Services, providers & availability**: Model what you offer, who offers it, and when — with per-provider schedules, overrides, and blackout dates.
- **One-off & recurring bookings**: Book a single appointment or an RFC 5545 recurrence, with per-occurrence and whole-series edits.
- **Race-safe slot allocation**: An advisory lock on the provider's local day plus a partial unique index means two customers never take the same slot.
- **Public booking widget**: A Livewire widget that works with *and* without JavaScript — every step is a real form, deep-linkable through the query string.
- **Manage tokens**: Account-free self-serve management through a hashed, single-credential link.
- **Calendar sync**: Pluggable Google / Microsoft / Apple drivers, plus subscribable iCal feeds that need no connected account.
- **Notifications**: Email, database, SMS, and webhook channels with configurable reminders.
- **Outbound webhooks**: Signed, retried, SSRF-guarded deliveries of the booking lifecycle.
- **GDPR tooling**: Retention pruning and request-driven erasure commands.
- **React & Vue components**: The same flow, prebuilt for apps that own their frontend, on one framework-agnostic core client.
- **Multi-site aware**: Every owned table is site-scoped through `artisanpack-ui/core`.

## Quick Example

```blade
{{-- Drop the public booking widget on any page --}}
<livewire:artisanpack-booking-widget service="discovery-call" />
```

```php
use ArtisanPackUI\Bookings\Services\BookingService;

$booking = app( BookingService::class )->create( [
    'service'        => $service,
    'start_time'     => $start,
    'customer_name'  => 'Sam Rivera',
    'customer_email' => 'sam@example.test',
] );
```

## Support

For support, please open an issue on the [GitHub repository](https://github.com/ArtisanPack-UI/bookings).

## License

This package is open-source software licensed under the [MIT license](https://opensource.org/licenses/MIT).
