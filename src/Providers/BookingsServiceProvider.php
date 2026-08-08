<?php

/**
 * Bookings service provider.
 *
 * Bootstraps the Bookings package. The scaffold merges and publishes the
 * package configuration and binds the package entry point; models, migrations,
 * routes, Livewire components, and the calendar drivers are registered here as
 * each is built.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Providers;

use ArtisanPackUI\Bookings\Bookings;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Bookings package.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingsServiceProvider extends ServiceProvider
{
    /**
     * Registers any application services.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/artisanpack/bookings.php',
            'artisanpack.bookings',
        );

        $this->app->singleton( 'bookings', function (): Bookings {
            return new Bookings();
        } );
    }

    /**
     * Bootstraps any application services.
     *
     * The configuration publishes to `config/artisanpack/bookings.php` so that
     * every ArtisanPack UI package an application installs keeps its config in
     * one directory rather than scattering files across `config/`. Laravel
     * derives the config key from that path, which is why the defaults are
     * merged under `artisanpack.bookings` rather than a bare `bookings` — a
     * published file has to land on the same key the package reads from.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes(
            [
                __DIR__ . '/../../config/artisanpack/bookings.php' => config_path( 'artisanpack/bookings.php' ),
            ],
            'bookings-config',
        );

        // Migrations, models, routes, views, Livewire components, calendar
        // drivers, and the CMS-framework integration are registered here as
        // each is built.
    }
}
