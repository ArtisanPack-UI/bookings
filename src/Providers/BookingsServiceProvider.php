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
use ArtisanPackUI\Bookings\Console\Commands\ReissueDetachedManageTokensCommand;
use ArtisanPackUI\Bookings\Console\Commands\SendBookingRemindersCommand;
use ArtisanPackUI\Bookings\Contracts\MeetingTypeRegistry as MeetingTypeRegistryContract;
use ArtisanPackUI\Bookings\Contracts\NotificationChannel;
use ArtisanPackUI\Bookings\Contracts\RoundRobinStrategy;
use ArtisanPackUI\Bookings\Contracts\SlotResolver;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Listeners\SendBookingNotifications;
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
use ArtisanPackUI\Bookings\Notifications\Channels\CmsFrameworkChannel;
use ArtisanPackUI\Bookings\Notifications\Channels\DatabaseChannel;
use ArtisanPackUI\Bookings\Notifications\Channels\MailChannel;
use ArtisanPackUI\Bookings\Services\AvailabilityService;
use ArtisanPackUI\Bookings\Services\BookingService;
use ArtisanPackUI\Bookings\Services\IntakeFieldValidator;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use ArtisanPackUI\Bookings\Services\NotificationService;
use ArtisanPackUI\Bookings\Services\ProviderSlotLock;
use ArtisanPackUI\Bookings\Services\ReminderScheduler;
use ArtisanPackUI\Bookings\Services\SeriesService;
use ArtisanPackUI\Bookings\Strategies\LeastRecentlyAssignedStrategy;
use ArtisanPackUI\Bookings\Support\HookSubscriptions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

use function __;
use function apRegisterNotification;
use function array_key_exists;
use function array_values;
use function function_exists;

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

        // The default rota. Bound against the contract for the same reason as
        // the resolver above: an application whose idea of "whose turn is it"
        // is not plain rotation rebinds this one interface and every assignment
        // in the package asks it instead.
        $this->app->bind( RoundRobinStrategy::class, LeastRecentlyAssignedStrategy::class );

        // Singletons because both are stateless collaborators that everything
        // touching a booking resolves — there is nothing to gain from building
        // them again per call, and the lock in particular is resolved on the
        // hot path of every write.
        $this->app->singleton( ProviderSlotLock::class );
        $this->app->singleton( IntakeFieldValidator::class );
        $this->app->singleton( BookingService::class );

        // Every manage token in the package is minted, hashed, and checked by
        // this one object — including the token the Booking model mints on
        // create, which resolves it from the container rather than newing it up
        // — so rebinding it replaces the credential scheme everywhere at once.
        $this->app->singleton( ManageTokenService::class );

        // Every occurrence a series materialises is written through
        // BookingService, so the two resolve the same instance and a rebinding
        // of the booking service reaches recurring bookings too.
        $this->app->singleton( SeriesService::class );

        // Bound individually as well as into the service, so that an application
        // replacing just the mail channel — a different transport, a themed
        // message — rebinds one class rather than reassembling the list.
        $this->app->singleton( MailChannel::class );
        $this->app->singleton( DatabaseChannel::class );
        $this->app->singleton( CmsFrameworkChannel::class );

        // Two implementations answer to the `database` key, and which one an
        // installation gets is decided here rather than by configuration.
        // Laravel's database notifications and cms-framework's notification
        // centre both want a table called `notifications`, and their schemas are
        // irreconcilable — a UUID key and a JSON payload against an
        // auto-increment id and `title`/`content` prose. Writing the wrong one
        // fails on every insert, and fails quietly: the send is logged as failed
        // and the admin never hears about the booking.
        //
        // So where cms-framework is installed the notice goes through its
        // notification centre, which is the better outcome anyway — one inbox
        // for everything the CMS raises, and the per-user preferences it already
        // has. Standalone installations keep the Laravel-native channel.
        $this->app->bind( NotificationChannel::class, function ( $app ): NotificationChannel {
            return self::usesCmsNotifications()
                ? $app->make( CmsFrameworkChannel::class )
                : $app->make( DatabaseChannel::class );
        } );

        // The channel list is assembled here rather than read from config inside
        // the service, because config names channels by key and the container is
        // what turns a key into an object an application may have replaced.
        // `webhook` is deliberately absent: it is in the default config ahead of
        // the ticket that implements it, and the service skips a configured
        // channel nothing is registered for.
        $this->app->singleton( NotificationService::class, function ( $app ): NotificationService {
            return new NotificationService( [
                $app->make( MailChannel::class ),
                $app->make( NotificationChannel::class ),
            ] );
        } );

        $this->app->singleton( ReminderScheduler::class );
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

        if ( $this->app->runningInConsole() ) {
            $this->commands( [
                ReissueDetachedManageTokensCommand::class,
                SendBookingRemindersCommand::class,
            ] );
        }

        Event::subscribe( SendBookingNotifications::class );

        $this->registerCmsNotificationTypes();

        $this->scheduleReminders();

        $this->invalidateAvailabilityOnWrites();

        // Routes, views, Livewire components, calendar drivers, and the
        // CMS-framework integration are registered here as each is built.
    }

    /**
     * Determines whether staff notices go through cms-framework's centre.
     *
     * Detection is the default rather than the only answer. An installation may
     * have cms-framework present for its admin shell while wanting booking
     * notices kept in Laravel's own notification table — or the reverse, in a
     * test — and `notifications.database.driver` says so outright:
     *
     * - `auto` (default) — the CMS centre when cms-framework is installed.
     * - `cms` — always the CMS centre.
     * - `laravel` — always Laravel's database notifications.
     *
     * @since 1.0.0
     *
     * @return bool True when the CMS notification centre should be used.
     */
    protected static function usesCmsNotifications(): bool
    {
        $driver = config( 'artisanpack.bookings.notifications.database.driver', 'auto' );

        if ( 'cms' === $driver ) {
            return true;
        }

        if ( 'laravel' === $driver ) {
            return false;
        }

        return HookSubscriptions::isInstalled( 'cms-framework' );
    }

    /**
     * Declares the booking notification types to cms-framework.
     *
     * Registration is what puts them in the preferences UI, so a member of staff
     * can turn off reminder notices without turning off cancellations. Without
     * it the notices still arrive, but as rows of an unregistered key that
     * nothing offers an opt-out for — and the first thing somebody does with a
     * notification they cannot switch off is stop reading the centre entirely.
     *
     * Runs whether or not a notification is ever sent, which is why it lives
     * here rather than in the channel. Guarded on the helper existing rather
     * than on the gate alone, because the gate probes for a class and the
     * helpers come from a file the package autoloads separately.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCmsNotificationTypes(): void
    {
        if ( ! self::usesCmsNotifications() || ! function_exists( 'apRegisterNotification' ) ) {
            return;
        }

        $types = [
            NotificationType::Confirmation->value => __( 'Booking confirmed' ),
            NotificationType::Reminder->value     => __( 'Booking reminder' ),
            NotificationType::Cancellation->value => __( 'Booking cancelled' ),
            NotificationType::Reschedule->value   => __( 'Booking rescheduled' ),
            NotificationType::NoShow->value       => __( 'Booking marked as a no-show' ),
        ];

        foreach ( $types as $value => $title ) {
            apRegisterNotification(
                CmsFrameworkChannel::KEY_PREFIX . $value,
                $title,
                __( 'A booking changed. Open it for the details.' ),
            );
        }
    }

    /**
     * Puts the reminder sweep on the application's schedule.
     *
     * Every fifteen minutes, and `withoutOverlapping()` on top of that, even
     * though the notification log already makes a doubled run harmless. The lock
     * saves the work rather than the correctness: two runs would walk the same
     * bookings and lose every claim, which is a lot of database round trips to
     * send nothing.
     *
     * Registered through a deferred callback because the scheduler is resolved
     * from the container, and resolving it during boot would force it into
     * existence for every request that never runs a console command.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function scheduleReminders(): void
    {
        if ( ! (bool) config( 'artisanpack.bookings.notifications.reminder.enabled', true ) ) {
            return;
        }

        $this->app->booted( function (): void {
            $this->app->make( Schedule::class )
                ->command( 'bookings:send-reminders' )
                ->everyFifteenMinutes()
                ->withoutOverlapping();
        } );
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
