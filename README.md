# ArtisanPack UI Bookings

Appointment scheduling and booking management for Laravel — services, providers,
availability, bookings, calendar sync, and a public booking widget.

> **Status: pre-release.** This is the package foundation. The service provider,
> configuration, database schema, and code-style/test toolchain are in place;
> the domain layer, HTTP surface, and frontend land in the `v1.0.0-alpha.*`
> series.

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

Migrations are loaded by the package, so `php artisan migrate` creates the
sixteen booking tables with nothing else to do. Publish them only if you need to
edit the schema:

```bash
php artisan vendor:publish --tag=bookings-migrations
```

Publishing copies the files into `database/migrations` and leaves the package
loading its own. That is not a conflict and does not run anything twice: the
migrator keys files by migration name, and the application's path is searched
last, so your copy shadows the package's and is the one that runs. Editing a
published migration works.

What publishing does not do is freeze the set. A later release that adds a
*new* migration has no counterpart in your directory to be shadowed by, so it
loads from the package and runs on the next `migrate` alongside your edited
copies. Publish again after upgrading if you want the whole set under your own
control.

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

Environment variables cover the settings most likely to differ per environment:
`BOOKING_DEFAULT_TIMEZONE`, `BOOKING_SLOT_INTERVAL`, `BOOKING_SMS_DRIVER`,
`BOOKING_GOOGLE_ENABLED`, `BOOKING_MICROSOFT_ENABLED`, `BOOKING_APPLE_ENABLED`,
and `BOOKING_PRUNE_DAYS`.

## Multi-site

Site scoping is configured once for the whole ecosystem, in
`artisanpack.core.multi_tenant` — not in this package's config. Set
`ARTISANPACK_MULTI_TENANT_ENABLED=true` (or the `enabled` key) to switch it on,
and list resolvers under `artisanpack.core.multi_tenant.resolvers`. Every owned
table carries a nullable `site_id`, and models using
`Models\Concerns\BelongsToSite` filter on whatever
`ArtisanPackUI\Core\MultiTenancy\SiteContext` reports — so a request cannot be
site 2 for one ArtisanPack package while being site 1 for this one.

Work that has to target or span a specific site pins one explicitly, which is
what a console command looping over sites needs:

```php
use ArtisanPackUI\Core\Facades\ArtisanPackSite;

ArtisanPackSite::forSite( $siteId, fn () => /* every bookings query answers for $siteId */ );
ArtisanPackSite::withoutSite( fn () => /* unscoped, for maintenance work */ );
```

Enabling scoping on an installation that already holds bookings needs `site_id`
backfilled first: rows written while it was off carry a null `site_id`, and the
scope matches on equality, so they leave every site-scoped query the moment a
site resolves. `acrossAllSites()` still sees them.

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

## Extending

### Contracts

Five seams are interfaces under `ArtisanPackUI\Bookings\Contracts`. Bind your own
implementation and the package uses it instead of the default:

| Contract | Replaces |
| --- | --- |
| `SlotResolver` | how availability rules become bookable slots |
| `RoundRobinStrategy` | which provider is assigned to a slot |
| `CalendarSyncDriver` | how one external calendar system is talked to |
| `NotificationChannel` | how one lifecycle message is delivered |
| `MeetingTypeRegistry` | which shapes a service can be booked in |

`MeetingTypeRegistry` is the odd one out: bind it only to replace the registry
itself. To add a meeting type — the common case — implement
`ArtisanPackUI\Bookings\Contracts\MeetingType` (or construct a
`RegisteredMeetingType`) and contribute it through the filter described under
[Hooks](#hooks). No binding required.

Site resolution is deliberately not on this list. It is
`ArtisanPackUI\Core\Contracts\SiteResolver`, bound once for the whole ecosystem —
see [Multi-site](#multi-site).

### Events

Every lifecycle change dispatches a typed event under
`ArtisanPackUI\Bookings\Events`. Payloads are serializable, so a listener may be
queued:

`BookingRequested`, `BookingConfirmed`, `BookingRescheduled`, `BookingCancelled`,
`BookingCompleted`, `BookingNoShow`, `SeriesCreated`, `SeriesCancelled`,
`SeriesEdited`, `CalendarSynced`, `CalendarSyncFailed`,
`CalendarConnectionDisabled`, and `WebhookDisabled`.

`BookingCancelled` carries a `BookingActor` because "the customer cancelled" and
"we cancelled on the customer" are the same status change and completely
different events downstream. `SeriesEdited` carries a `SeriesEditScope` for the
same reason.

These names are public API. They are what `artisanpack-ui/crm` will subscribe to,
and they will not be renamed without a deprecation cycle.

### Hooks

Actions and filters are registered through `artisanpack-ui/hooks`. Names take an
`ap.` prefix, `.`-separated segments, and camelCase within each segment — so both
`ap.bookings.registeredMeetingTypes` and grouped names like
`ap.bookings.calendarSync.providers` are well formed. Never snake_case.

Meeting types are contributed through a filter rather than being hard-coded:

```php
use ArtisanPackUI\Bookings\MeetingTypes\RegisteredMeetingType;

addFilter( 'ap.bookings.registeredMeetingTypes', function ( array $types ): array {
    $types[] = new RegisteredMeetingType(
        'webinar',
        'Webinar',
        'Broadcast to many attendees at once.',
        allowsMultipleAttendees: true,
    );

    return $types;
} );
```

Pass the label and description untranslated — they are used as translation keys
and run through `__()` when read, so they follow the current locale rather than
freezing at whichever one was active when the registry was first resolved.

The filter runs on every read, so registering from a service provider that boots
after this one still works. Entries are keyed by the type's own `key()`, so
appending and assigning behave identically. The four built-ins — `one_to_one`,
`group`, `recurring`, and `round_robin` — are ordinary entries: register a type
under an existing key to replace it.

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

### Testing

Tests run on Pest 3 and Orchestra Testbench. `Tests\TestCase` registers the
core, hooks, and bookings providers and points the application at an in-memory
SQLite database, so `composer test` needs nothing installed beyond Composer
dependencies. Model factories resolve to
`ArtisanPackUI\Bookings\Database\Factories\<Model>Factory`.

A few tests cannot run on SQLite. Booking creation has to be race-safe — two
customers must not both take the last slot — and the guard is a named advisory
lock: MySQL's `GET_LOCK`, Postgres' `pg_advisory_xact_lock`. Those tests use
`Tests\Concerns\TestsWithMysql` or `TestsWithPostgres` and carry a matching
group:

```bash
composer test:sqlite     # everything that runs in memory
composer test:mysql      # the mysql group, against a real MySQL server
composer test:postgres   # the postgres group, against a real Postgres server

DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=bookings_test \
DB_USERNAME=root DB_PASSWORD=secret composer test:mysql
```

When the server is unreachable those tests skip, so a plain `composer test` is
green without one running. **CI must not accept that skip** — a skipped lock
test reads as "race-safety verified" while verifying nothing — so the
`test-mysql` and `test-postgres` jobs in `.github/workflows/ci.yml` set
`BOOKINGS_REQUIRE_EXTERNAL_DB=1`, which turns the skip into a failure.

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
