---
title: Installation Overview
---

# Installation

Everything you need to install and configure ArtisanPack UI Bookings in your Laravel application.

## In this section

- [Requirements](Installation-Requirements) - PHP, Laravel, and optional package requirements
- [Configuration](Installation-Configuration) - Every configuration key and environment variable

## Install via Composer

```bash
composer require artisanpack-ui/bookings
```

The service provider is auto-discovered — there is nothing to register by hand.

## Run the migrations

Migrations are loaded by the package, so `php artisan migrate` creates the booking tables with nothing else to do:

```bash
php artisan migrate
```

## Publishing the configuration

Publish the config when you want to change the defaults:

```bash
php artisan vendor:publish --tag=bookings-config
```

That writes `config/artisanpack/bookings.php`. Laravel's config loader walks nested directories under `config/` and prefixes the key with the directory name, so that file loads under `artisanpack.bookings` — the same key the package reads from. Individual settings are reached with dot notation:

```php
config( 'artisanpack.bookings.slot_interval' );   // 15
config( 'artisanpack.bookings.admin.gate' );      // 'bookings.manage'
config( 'artisanpack.bookings' );                 // the whole array
```

See [Configuration](Installation-Configuration) for every key.

## Publishing the migrations

Publish the migrations only if you need to edit the schema:

```bash
php artisan vendor:publish --tag=bookings-migrations
```

Publishing copies the files into `database/migrations` and leaves the package loading its own. That is not a conflict and does not run anything twice: the migrator keys files by migration name, and the application's path is searched last, so your copy shadows the package's and is the one that runs. Editing a published migration works.

What publishing does not do is freeze the set. A later release that adds a *new* migration has no counterpart in your directory to be shadowed by, so it loads from the package and runs on the next `migrate` alongside your edited copies. Publish again after upgrading if you want the whole set under your own control.

## Publishing the views

The public widget, the self-serve page, and the admin layout are plain Blade with daisyUI class names. Publish them to change the markup:

```bash
php artisan vendor:publish --tag=bookings-views
```

Views are loaded under the `bookings::` namespace, and published copies land under `resources/views/vendor/bookings`.

## Optional: install Livewire

The public booking widget and the admin screens are Livewire components, registered only when `livewire/livewire` is installed. A headless installation using the JSON API and iCal feeds does not need it:

```bash
composer require livewire/livewire
```

## Next steps

- [Requirements](Installation-Requirements) — versions and optional packages
- [Configuration](Installation-Configuration) — every key you can tune
- [Quick Start Guide](Getting-Started) — composer require to a live booking
