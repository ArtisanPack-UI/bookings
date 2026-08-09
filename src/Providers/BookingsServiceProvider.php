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
use ArtisanPackUI\Bookings\Contracts\SlotResolver;
use ArtisanPackUI\Bookings\MeetingTypes\MeetingTypeRegistry;
use ArtisanPackUI\Bookings\Models\AvailabilityOverride;
use ArtisanPackUI\Bookings\Models\AvailabilitySchedule;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarBusyBlock;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceBlackoutDate;
use ArtisanPackUI\Bookings\Models\ServiceProvider as ServiceProviderModel;
use ArtisanPackUI\Bookings\Models\ServiceProviderService;
use ArtisanPackUI\Bookings\Services\AvailabilityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

use function array_key_exists;
use function array_values;

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

        // A singleton because the resolver holds the availability cache stamps,
        // and the model events that bump them resolve it out of the container —
        // a fresh instance per resolution would still work, but every one of them
        // would re-read the same stamps from the store for no reason.
        $this->app->singleton( AvailabilityService::class );

        // Bound against the contract so that everything in the package asks for
        // the seam rather than the shipped implementation, and an application
        // that rebinds SlotResolver actually replaces it.
        $this->app->bind( SlotResolver::class, AvailabilityService::class );
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

        $this->invalidateAvailabilityOnWrites();

        // Routes, views, Livewire components, calendar drivers, and the
        // CMS-framework integration are registered here as each is built.
    }

    /**
     * Moves the availability cache stamps on whenever availability changes.
     *
     * Availability is derived from five tables, and a cached day computed from
     * them has to stop being reachable the moment any of them moves. Hanging
     * this off the models rather than off the services that write them is what
     * makes it hold for writes this package did not make — an admin screen, an
     * importer, a sync job — since all of them go through Eloquent.
     *
     * What it does not cover is a write that bypasses the models entirely, which
     * is what the TTL on each entry is for.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function invalidateAvailabilityOnWrites(): void
    {
        $availability = fn (): AvailabilityService => $this->app->make( AvailabilityService::class );

        $forProvider = static function ( Model $model ) use ( $availability ): void {
            foreach ( self::touchedIds( $model, 'provider_id' ) as $providerId ) {
                $availability()->invalidateProvider( $providerId );
            }
        };

        foreach ( [ AvailabilitySchedule::class, AvailabilityOverride::class ] as $model ) {
            $model::saved( $forProvider );
            $model::deleted( $forProvider );
        }

        // An unassigned booking holds nobody's slot, so there is nothing to
        // invalidate until one is assigned — and that assignment is itself a save.
        Booking::saved( $forProvider );
        Booking::deleted( $forProvider );

        $forBlackout = static function ( ServiceBlackoutDate $blackout ) use ( $availability ): void {
            $touched = self::touchedIds( $blackout, 'service_id' );

            // A site-wide blackout closes every service there is, including the
            // ones whose cached days were computed before it existed — and a row
            // that has *stopped* being site-wide has to reopen all of them, which
            // bumping the one service it now names would not do.
            if ( self::wasEverNull( $blackout, 'service_id' ) ) {
                $availability()->invalidateEverything();
            }

            foreach ( $touched as $serviceId ) {
                $availability()->invalidateService( $serviceId );
            }
        };

        ServiceBlackoutDate::saved( $forBlackout );
        ServiceBlackoutDate::deleted( $forBlackout );

        $forBusyBlock = static function ( CalendarBusyBlock $block ) use ( $availability ): void {
            foreach ( self::touchedIds( $block, 'connection_id' ) as $connectionId ) {
                // Read across sites deliberately: a sync job writing busy blocks
                // has no site in context, and a scoped lookup would find no
                // connection and quietly leave the provider's cache stale.
                $providerId = CalendarConnection::query()
                    ->acrossAllSites()
                    ->whereKey( $connectionId )
                    ->value( 'provider_id' );

                if ( null !== $providerId ) {
                    $availability()->invalidateProvider( (int) $providerId );
                }
            }
        };

        CalendarBusyBlock::saved( $forBusyBlock );
        CalendarBusyBlock::deleted( $forBusyBlock );

        // The rows above are what a day is *subtracted* by. These are what it is
        // *shaped* by — a duration, a buffer, a timezone, whether a calendar is
        // read back at all — and a cached day bakes every one of them in just as
        // firmly. An admin halving a service's duration and watching the widget
        // keep the old grid is the same staleness bug wearing a different hat.
        $forService = static function ( Service $service ) use ( $availability ): void {
            $availability()->invalidateService( (int) $service->getKey() );
        };

        Service::saved( $forService );
        Service::deleted( $forService );

        $forServiceProvider = static function ( ServiceProviderModel $provider ) use ( $availability ): void {
            $availability()->invalidateProvider( (int) $provider->getKey() );
        };

        ServiceProviderModel::saved( $forServiceProvider );
        ServiceProviderModel::deleted( $forServiceProvider );

        CalendarConnection::saved( $forProvider );
        CalendarConnection::deleted( $forProvider );

        // Only fires when the pivot is saved as a model. `attach()` and
        // `updateExistingPivot()` write through the query builder and raise no
        // event on any Laravel version this package supports, so a custom
        // duration set that way is covered by the TTL rather than by this.
        $forAttachment = static function ( ServiceProviderService $attachment ) use ( $availability ): void {
            foreach ( self::touchedIds( $attachment, 'service_id' ) as $serviceId ) {
                $availability()->invalidateService( $serviceId );
            }

            foreach ( self::touchedIds( $attachment, 'provider_id' ) as $providerId ) {
                $availability()->invalidateProvider( $providerId );
            }
        };

        ServiceProviderService::saved( $forAttachment );
        ServiceProviderService::deleted( $forAttachment );
    }

    /**
     * Gets every value a column has held across the write being handled.
     *
     * Both the new value and the one it replaced, because a row that moves
     * between owners leaves two caches wrong and only one of them is named by
     * the row you are holding. Reassigning a booking from one provider to
     * another frees a slot on the first, and reading only the current
     * `provider_id` would leave that slot unbookable until the entry aged out.
     *
     * `saved` fires before Eloquent syncs the original attributes, so the
     * previous value is still readable here — and whether there *is* one is
     * asked of the original array rather than of `wasRecentlyCreated`. That flag
     * stays true for the whole life of the instance that inserted the row, so a
     * model created and then updated in the same request still reports itself as
     * recently created, and reading it would skip the previous value on exactly
     * the write that has one.
     *
     * @since 1.0.0
     *
     * @param  Model  $model  The model being written.
     * @param  string  $column  The foreign key to read.
     *
     * @return array<int, int> The distinct ids touched, without nulls.
     */
    protected static function touchedIds( Model $model, string $column ): array
    {
        $values = [ $model->getAttribute( $column ) ];

        if ( array_key_exists( $column, $model->getRawOriginal() ) ) {
            $values[] = $model->getOriginal( $column );
        }

        $ids = [];

        foreach ( $values as $value ) {
            if ( null !== $value ) {
                $ids[ (int) $value ] = (int) $value;
            }
        }

        return array_values( $ids );
    }

    /**
     * Determines whether a column is null now or was before the write.
     *
     * @since 1.0.0
     *
     * @param  Model  $model  The model being written.
     * @param  string  $column  The column to read.
     *
     * @return bool True when either side of the write held null.
     */
    protected static function wasEverNull( Model $model, string $column ): bool
    {
        if ( null === $model->getAttribute( $column ) ) {
            return true;
        }

        return array_key_exists( $column, $model->getRawOriginal() )
            && null === $model->getOriginal( $column );
    }
}
