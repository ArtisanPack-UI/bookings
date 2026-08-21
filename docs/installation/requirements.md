---
title: Requirements
---

# Requirements

## Core requirements

- **PHP 8.2+** (Laravel 13 itself requires PHP 8.3+)
- **Laravel 11, 12, or 13**

The two constraints resolve together rather than conflicting: on PHP 8.2 Composer installs Laravel 12 or below, and Laravel 13 becomes available once the host application is on PHP 8.3+.

The package depends on `artisanpack-ui/core` (multi-site, site context) and `artisanpack-ui/hooks` (actions and filters). Both install automatically with Composer.

## Optional packages

The package runs standalone in any Laravel application. When these are installed it uses them, and degrades cleanly when they are not:

| Package | What it adds |
| --- | --- |
| `livewire/livewire` | The public booking widget and the admin screens |
| `artisanpack-ui/cms-framework` | Admin navigation, permissions, settings, notification centre |
| `artisanpack-ui/livewire-ui-components` | Admin screen rendering (the public widget is plain HTML and does not use it) |
| `artisanpack-ui/forms` | `booking_slot` field type, booking-from-submission |
| `artisanpack-ui/media-library` | Service and provider images |
| `artisanpack-ui/google`, `artisanpack-ui/microsoft`, `artisanpack-ui/apple` | Calendar sync drivers |
| `artisanpack-ui/accessibility` | Accessible admin and widget theming |

See [Integrations Overview](Integrations) for how each is wired.

## Database

Any database Laravel supports works. One caveat concerns race-safety: booking creation is guarded by a **named advisory lock** so two customers cannot both take the last slot.

- **MySQL** (`GET_LOCK`) and **PostgreSQL** (`pg_advisory_xact_lock`) provide server-side advisory locks that hold across every process talking to the database.
- **SQLite** and other engines have no such primitive, so the cache store's lock stands in — exclusive within one application server, and only as wide as the cache store behind it. Point `artisanpack.bookings.lock.store` at a shared store (e.g. Redis) if you run more than one application server.

See [Configuration](Installation-Configuration) for the lock settings and [Troubleshooting](Troubleshooting) for the double-booking guarantees.

## Curl (webhooks)

Outbound webhook delivery pins the vetted address into the connection to close the DNS-rebinding window. That pinning is enforced by `CURLOPT_RESOLVE`, which needs the curl HTTP handler (`ext-curl`) and libcurl 7.59 or newer. Without curl, deliveries still get the delivery-time address check — they just cannot pin the connection. See [Webhook Security](Advanced-Webhook-Security).

## Next steps

- [Installation](Installation)
- [Configuration](Installation-Configuration)
