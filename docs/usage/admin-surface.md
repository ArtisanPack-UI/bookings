---
title: Admin Surface
---

# Admin Surface

The staff-facing screens are mounted as routes under `admin.route_prefix` (`bookings-admin/…` by default), one per screen — the bookings list and calendar, services and their intake schemas, providers and their schedules, blackout dates, recurring series, calendar connections, webhooks, the notification log, and settings.

They are named `artisanpack.bookings.admin.*`, so link to them with `route()` rather than a hard-coded path:

```php
route( 'artisanpack.bookings.admin.bookings' );   // the list
route( 'artisanpack.bookings.admin.settings' );   // general config
```

## The screens

| Route name (suffix) | Path | Screen |
| --- | --- | --- |
| `bookings` | `bookings` | Bookings list |
| `bookings.create` | `bookings/create` | Create a booking |
| `bookings.show` | `bookings/{booking}` | Booking detail |
| `calendar` | `calendar` | Bookings calendar |
| `services` | `services` | Services |
| `services.intake-schema` | `services/{service}/intake-schema` | Intake schema editor |
| `providers` | `providers` | Providers & schedules |
| `blackout-dates` | `blackout-dates` | Blackout dates |
| `series` | `series` | Recurring series |
| `calendar-connections` | `calendar-connections` | Calendar connections |
| `webhooks` | `webhooks` | Webhook endpoints |
| `notifications` | `notifications` | Notification log |
| `settings` | `settings` | General config |

## Authorization

Every screen sits behind the `bookings.admin` gate, which authorizes against the ability named by `admin.gate` (`bookings.manage`). The package defines no default ability on purpose: `Gate::authorize()` against an undefined ability denies, so mounting the admin without wiring the gate is a locked door, not an open one. Define it against whatever "staff" means to your application:

```php
Gate::define( 'bookings.manage', fn ( User $user ) => $user->isStaff() );
```

The gate is also applied to Livewire update requests through a persistent middleware, so a screen cannot be driven past its guard once it has loaded.

### One gate covers every admin action

`bookings.admin` is a single all-or-nothing gate: anyone who holds it can do everything the admin exposes — edit services and providers, manage webhooks, and **erase customer PII**. There is no finer-grained permission in v1.0; a "staff" ability is the whole model. That is a deliberate fit for a single-role installation, but if your application needs to separate, say, day-to-day booking management from PII erasure or webhook administration, gate the sensitive actions in your own application layer for now. Per-surface abilities (`bookings.erase-pii`, `bookings.manage-webhooks`, …) defaulting to the main gate are a candidate for a future minor release — track it if it matters to you.

## Layout

Each screen renders inside a layout chosen for you: `cms::admin.layouts.app` when `artisanpack-ui/cms-framework` is installed, and the package's own `bookings::admin.layouts.app` when it is not. Publish the standalone layout with `php artisan vendor:publish --tag=bookings-views` and edit the copy under `resources/views/vendor/bookings/admin/layouts/app.blade.php` to wrap the screens in your own chrome.

With cms-framework installed, the screens also register themselves in its admin navigation through the `ap.cmsFramework.admin.menu` filter — a single **Bookings** section with every screen beneath it, each gated by the same `bookings.manage` ability. Turn that off with `admin.auto_register_cms_nav` when you would rather place the screens in the shell's menu yourself. See [CMS Framework](Integrations-Cms-Framework).
