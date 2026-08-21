---
title: CMS Framework
---

# CMS Framework

When `artisanpack-ui/cms-framework` is installed, the package folds its admin surface and its staff notifications into the CMS shell. It is detected by probing for cms-framework's `AdminMenuManager` class, so nothing in this integration runs without it.

## Admin navigation

The admin screens register themselves in cms-framework's admin navigation through the `ap.cmsFramework.admin.menu` filter — a single **Bookings** section with every screen beneath it, each gated by the `bookings.manage` ability.

This is gated twice: on `admin.auto_register_cms_nav` (config, default `true`) and on cms-framework being installed. Turn the config off when you would rather place the screens in the shell's menu yourself:

```php
// config/artisanpack/bookings.php
'admin' => [
    'auto_register_cms_nav' => false,
],
```

Where a menu entry already exists under an overlapping key, the existing entry wins over the bookings entry. See [Admin Surface](Usage-Admin-Surface).

## Admin layout

Each admin screen renders inside `cms::admin.layouts.app` when cms-framework is installed, and the package's own `bookings::admin.layouts.app` otherwise. No configuration needed — the layout is chosen for you per request.

## Staff notifications

The `database` notification channel delivers staff-facing booking notices into cms-framework's notification centre through `Notifications\Channels\CmsFrameworkChannel`. Which implementation the channel uses is chosen by `notifications.database.driver`:

- `auto` (default) — cms-framework's centre when installed, Laravel's own storage otherwise.
- `cms` — force the cms-framework centre; set `notifications.database.role` to the receiving role.
- `laravel` — force Laravel-native database notifications.

```php
// config/artisanpack/bookings.php
'notifications' => [
    'database' => [
        'driver' => 'cms',
        'role'   => 'booking-admin',
    ],
],
```

### The `notifications` table collision

`artisanpack-ui/cms-framework` ships its own `notifications` table with a schema incompatible with Laravel's own database-notification storage (Laravel expects a UUID key and a JSON `data` column). If you force the `laravel` driver in an application that also runs cms-framework, point `notifications.database.notifiable` at storage of your own rather than the colliding table. A failed staff-notification write is recorded against the notification log rather than thrown, so the customer's email still goes out — the admin row is what goes missing. See [Notifications Overview](Notifications).

## Site resolution

`artisanpack-ui/core` applies cms-framework's `ap.cmsFramework.currentSite.resolve` on the package's behalf, so bookings and the CMS agree on the current site. See [Multi-Site](Advanced-Multi-Site).
