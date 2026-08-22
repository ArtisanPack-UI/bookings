---
title: Integrations Overview
---

# Integrations

The package runs standalone in any Laravel application. Each optional package is *detected*, not required — the integration wires itself only when the package it needs is present, and degrades cleanly when it is not.

## In this section

- [Calendar Sync](Integrations-Calendar-Sync) - Google / Microsoft / Apple drivers and the driver contract
- [CMS Framework](Integrations-Cms-Framework) - Admin nav, permissions, and the notification centre
- [Forms](Integrations-Forms) - The `booking_slot` field type and booking-from-submission
- [Media Library](Integrations-Media-Library) - Service and provider images

## The optional packages

| Package | What it adds |
| --- | --- |
| `livewire/livewire` | The public booking widget and the admin screens |
| `artisanpack-ui/cms-framework` | Admin navigation, permissions, settings, notification centre |
| `artisanpack-ui/livewire-ui-components` | Admin screen rendering |
| `artisanpack-ui/forms` | `booking_slot` field type, booking-from-submission |
| `artisanpack-ui/media-library` | Service and provider images |
| `artisanpack-ui/google`, `artisanpack-ui/microsoft`, `artisanpack-ui/apple` | Calendar sync drivers |
| `artisanpack-ui/accessibility` | Accessible admin and widget theming |

## How detection works

Where one of these is subscribed to rather than merely used, the binding goes through `Support\HookSubscriptions`, which is the single place that answers "is that package installed?" — by probing for the class the integration itself needs, so a callback naming absent classes is never entered:

```php
use ArtisanPackUI\Bookings\Support\HookSubscriptions;

HookSubscriptions::whenInstalled( 'forms', function (): void {
    addFilter( 'ap.forms.fieldTypes', /* ... */ );
} );
```

Upstream hooks keep their upstream names — this package does not rename another package's hooks.

| Package | Detected via | Wired by | Config gate |
| --- | --- | --- | --- |
| `cms-framework` | `AdminMenuManager` class | `Support\AdminNav`, `CmsFrameworkChannel` | `admin.auto_register_cms_nav` |
| `forms` | `FieldTypes` class | `Integrations\Forms\FormsIntegration` | `forms.auto_register` |
| `google` | `Google` facade | `Support\Google\OAuthGoogleTokenProvider` + `GoogleCalendarDriver` | `calendar.drivers.google.enabled` |
| `livewire` | Livewire installed | Widget & admin component registration | — |
| `media-library` | package present | Service/provider image fields | — |

## The notification-storage caveat

The `database` notification channel writes through Laravel's own notification storage when not using cms-framework, so the notifiable's table has to be the shape Laravel expects — a UUID key and a JSON `data` column. `artisanpack-ui/cms-framework` ships its own `notifications` table with an incompatible schema, so an application running both has to point its notifiable at storage of its own. A failed write is recorded against the notification log rather than thrown, so the customer's email goes out either way; the admin row is what goes missing. See [CMS Framework](Integrations-Cms-Framework).

## Contracts as seams

Beyond the packaged integrations, six contracts are the seams you bind your own implementation into. See [Contracts](Api-Contracts).
