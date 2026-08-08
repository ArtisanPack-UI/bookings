# ArtisanPack UI Bookings

Appointment scheduling and booking management for Laravel — services, providers,
availability, bookings, calendar sync, and a public booking widget.

> **Status: pre-release.** This is the package foundation. The service provider,
> configuration, and code-style/test toolchain are in place; the domain layer,
> HTTP surface, and frontend land in the `v1.0.0-alpha.*` series.

## Requirements

- PHP 8.2+ (Laravel 13 itself requires PHP 8.3+)
- Laravel 10, 11, 12, or 13

The two constraints resolve together rather than conflicting: on PHP 8.2 Composer
installs Laravel 12 or below, and Laravel 13 becomes available once the host
application is on PHP 8.3+.

## Installation

```bash
composer require artisanpack-ui/bookings
```

The service provider is auto-discovered. Publish the configuration when you want
to change the defaults:

```bash
php artisan vendor:publish --tag=bookings-config
```

That writes `config/artisanpack/bookings.php`. Laravel's config loader walks
nested directories under `config/` and prefixes the key with the directory name,
so that file loads under `artisanpack.bookings` — the same key the package reads
from. Individual settings are reached with dot notation:

```php
config( 'artisanpack.bookings.slot_interval' );        // 15
config( 'artisanpack.bookings.admin.gate' );           // 'bookings.manage'
config( 'artisanpack.bookings' );                      // the whole array
```

## Configuration

The published file documents every key inline. The ones worth knowing up front:

| Key | Default | What it controls |
| --- | --- | --- |
| `timezone` | `config( 'app.timezone' )` | Zone that unqualified availability is authored in |
| `slot_interval` | `15` | Minutes between candidate slot start times |
| `booking_window` | 60 min – 90 days | How soon and how far ahead a customer may book |
| `cancellation` | allowed, 24h notice | Self-serve cancellation policy |
| `calendar.drivers` | all disabled | Google / Microsoft / Apple sync, opt-in per driver |
| `admin.route_prefix` | `bookings-admin` | Prefix for the staff-facing routes |
| `public.route_prefix` | `bookings` | Prefix for the customer-facing routes |
| `multi_tenant.enabled` | `false` | Scope every query to a resolved site |

Environment variables cover the settings most likely to differ per environment:
`BOOKING_DEFAULT_TIMEZONE`, `BOOKING_SLOT_INTERVAL`, `BOOKING_SMS_DRIVER`,
`BOOKING_GOOGLE_ENABLED`, `BOOKING_MICROSOFT_ENABLED`, `BOOKING_APPLE_ENABLED`,
`BOOKING_PRUNE_DAYS`, and `BOOKING_MULTI_TENANT`.

## Usage

The package entry point resolves through the container, a facade, or a helper —
all three return the same instance:

```php
use ArtisanPackUI\Bookings\Facades\Bookings;

app( 'bookings' );
Bookings::getFacadeRoot();
bookings();
```

The booking, availability, and calendar APIs are added on top of this entry point
in the alpha releases.

## Optional integrations

The package runs standalone in any Laravel application. When these are installed
it uses them, and degrades cleanly when they are not:

- `artisanpack-ui/cms-framework` — admin navigation, permissions, settings
- `artisanpack-ui/livewire-ui-components` — admin and widget rendering
- `artisanpack-ui/forms` — `booking_slot` field type, booking-from-submission
- `artisanpack-ui/media-library` — service and provider images
- `artisanpack-ui/google`, `artisanpack-ui/microsoft`, `artisanpack-ui/apple` — calendar sync drivers
- `artisanpack-ui/accessibility` — accessible admin and widget theming

## Development

```bash
composer install
composer test      # Pest
composer lint      # php-cs-fixer --dry-run + pint --test + phpcs
composer fix       # pint, then php-cs-fixer
```

Both formatters come from `artisanpack-ui/code-style-pint`: `pint.json` is
generated from its `ArtisanPackUIPreset`, and `.php-cs-fixer.dist.php` adds the
WordPress-style spacing its custom fixers provide — `if ( $condition )`,
`myFunction( $arg )`, `[ 'key' => 'value' ]`. Pint alone cannot produce that
spacing, so `composer fix` runs Pint first and lets PHP-CS-Fixer settle the
spacing afterwards. `pint.json` disables the handful of Laravel-preset rules
that fight the house standard (`phpdoc_no_package`, `no_superfluous_phpdoc_tags`,
`declare_parentheses`, `trim_array_spaces`, `no_spaces_around_offset`), so the
two tools agree on a single fixpoint rather than reformatting each other.

## Contributing

As an open source project, this package is open to contributions from anyone. Please [read through the contributing
guidelines](CONTRIBUTING.md) to learn more about how you can contribute to this project.
