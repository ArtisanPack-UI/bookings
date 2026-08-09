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
use ArtisanPackUI\Bookings\Contracts\MeetingTypeRegistry as MeetingTypeRegistryContract;
use ArtisanPackUI\Bookings\MeetingTypes\MeetingTypeRegistry;
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

        // A singleton so that a type registered in PHP — rather than through the
        // `ap.bookings.registeredMeetingTypes` filter — is still there when the
        // next caller resolves the registry. The filter itself runs on every
        // read, so late registration through hooks works either way.
        $this->app->singleton( MeetingTypeRegistryContract::class, function (): MeetingTypeRegistry {
            return new MeetingTypeRegistry();
        } );

        // Without the alias the concrete class stays unbound, and because its
        // constructor takes no arguments the container happily auto-builds a
        // second, empty registry for anyone who type-hints it — losing every
        // PHP-side registration, silently, at the call site most likely to be
        // written by someone reaching for the shipped implementation.
        $this->app->alias( MeetingTypeRegistryContract::class, MeetingTypeRegistry::class );
    }

    /**
     * Bootstraps any application services.
     *
     * The configuration publishes to `config/artisanpack/bookings.php` so that
     * every ArtisanPack UI package an application installs keeps its config in
     * one directory rather than scattering files across `config/`.
     *
     * Laravel's config loader prefixes a key with the nested directory it found
     * the file in (`LoadConfiguration::getNestedDirectory`), so a file published
     * to that path loads under `artisanpack.bookings`. `mergeConfigFrom` takes
     * its key explicitly, and register() passes that same key so the two agree —
     * merging under a bare `bookings` would leave an application editing a
     * published file this package never reads.
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

        // Loaded rather than published by default, so an upgrade that adds a
        // table takes effect on `migrate` without anybody re-publishing. The
        // publish tag exists for applications that need to edit the schema.
        $this->loadMigrationsFrom( __DIR__ . '/../../database/migrations' );

        $this->publishes(
            [
                __DIR__ . '/../../database/migrations' => database_path( 'migrations' ),
            ],
            'bookings-migrations',
        );

        // Models, routes, views, Livewire components, calendar drivers, and the
        // CMS-framework integration are registered here as each is built.
    }
}
