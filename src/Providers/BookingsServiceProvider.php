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
use ArtisanPackUI\Bookings\Console\Commands\CompletePastBookingsCommand;
use ArtisanPackUI\Bookings\Console\Commands\IcalTokenCommand;
use ArtisanPackUI\Bookings\Console\Commands\PollAppleCalendarsCommand;
use ArtisanPackUI\Bookings\Console\Commands\PruneCalendarEventsCommand;
use ArtisanPackUI\Bookings\Console\Commands\PruneNotificationLogCommand;
use ArtisanPackUI\Bookings\Console\Commands\PruneWebhookDeliveriesCommand;
use ArtisanPackUI\Bookings\Console\Commands\RefreshCalendarsCommand;
use ArtisanPackUI\Bookings\Console\Commands\ReissueDetachedManageTokensCommand;
use ArtisanPackUI\Bookings\Console\Commands\RenewCalendarWatchChannelsCommand;
use ArtisanPackUI\Bookings\Console\Commands\SendBookingRemindersCommand;
use ArtisanPackUI\Bookings\Contracts\MeetingTypeRegistry as MeetingTypeRegistryContract;
use ArtisanPackUI\Bookings\Contracts\NotificationChannel;
use ArtisanPackUI\Bookings\Contracts\RoundRobinStrategy;
use ArtisanPackUI\Bookings\Contracts\SlotResolver;
use ArtisanPackUI\Bookings\Contracts\SmsDriver;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Http\Middleware\RateLimitBookings;
use ArtisanPackUI\Bookings\Http\Middleware\ResolveManageToken;
use ArtisanPackUI\Bookings\Listeners\DispatchBookingWebhooks;
use ArtisanPackUI\Bookings\Listeners\SendBookingNotifications;
use ArtisanPackUI\Bookings\Livewire\Public\BookingWidget;
use ArtisanPackUI\Bookings\Livewire\Public\ManageBooking;
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
use ArtisanPackUI\Bookings\Notifications\Channels\SmsChannel;
use ArtisanPackUI\Bookings\Notifications\Sms\NullSmsDriver;
use ArtisanPackUI\Bookings\Services\AvailabilityService;
use ArtisanPackUI\Bookings\Services\BookingService;
use ArtisanPackUI\Bookings\Services\IcalFeedService;
use ArtisanPackUI\Bookings\Services\IcalTokenService;
use ArtisanPackUI\Bookings\Services\IntakeFieldValidator;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use ArtisanPackUI\Bookings\Services\NotificationService;
use ArtisanPackUI\Bookings\Services\ProviderSlotLock;
use ArtisanPackUI\Bookings\Services\ReminderScheduler;
use ArtisanPackUI\Bookings\Services\SeriesService;
use ArtisanPackUI\Bookings\Services\WebhookDispatcher;
use ArtisanPackUI\Bookings\Strategies\LeastRecentlyAssignedStrategy;
use ArtisanPackUI\Bookings\Support\HookSubscriptions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Livewire\Livewire;

use function __;
use function apRegisterNotification;
use function array_key_exists;
use function array_values;
use function class_exists;
use function config;
use function function_exists;
use function is_a;
use function is_string;
use function sprintf;
use function trim;

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
     * How long an overlap lock is held for a quarter-hourly command, in minutes.
     *
     * Every `withoutOverlapping()` here is given an expiry rather than taking
     * the framework's twenty-four hour default. The lock is released when a run
     * finishes, so the expiry only matters when a run never finishes — a
     * SIGKILL, an OOM kill, a container replaced mid-sweep — and a day-long
     * default means one of those silences the command until tomorrow. For the
     * Apple poll that is precisely the failure polling exists to prevent, since
     * CalDAV has no push to fall back on.
     *
     * Each is comfortably longer than its command's interval, so a slow run is
     * still protected from a second one starting on top of it.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const LOCK_QUARTER_HOURLY = 30;

    /**
     * How long an overlap lock is held for an hourly command, in minutes.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const LOCK_HOURLY = 120;

    /**
     * How long an overlap lock is held for a daily command, in minutes.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const LOCK_DAILY = 360;

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

        // Bound so an installation can change what its calendars say — a
        // different summary, an extra property, a different window — by
        // rebinding one class rather than by replacing the two controller
        // actions that call it.
        $this->app->singleton( IcalFeedService::class );

        // Every provider feed token is minted, hashed, and checked by this one
        // object, for the reason ManageTokenService is a singleton: rebinding it
        // replaces the credential scheme behind the calendar feeds everywhere at
        // once rather than in each of the places that happens to mint one.
        $this->app->singleton( IcalTokenService::class );

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
        // Constructed explicitly rather than autowired: the driver argument is
        // nullable so the channel can resolve it when it first sends, and the
        // container would helpfully fill it in at construction instead — which
        // would resolve the gateway for every application that merely boots the
        // package, and read the setting long before anything wanted to text
        // anybody.
        $this->app->singleton( SmsChannel::class, static function (): SmsChannel {
            return new SmsChannel();
        } );

        // Text messages cost money and reach a real phone, so the shipped
        // driver sends nothing and says so in the log. Real gateways land in
        // v1.1; until then an application names its own class in
        // `notifications.sms_driver`, or binds this interface directly — which
        // is also how a gateway the package will never ship gets used after
        // v1.1. Resolved through a closure rather than a plain alias so the
        // setting is read the first time a text is actually sent, rather than
        // by every application that merely loads this provider.
        $this->app->singleton( SmsDriver::class, function ( $app ): SmsDriver {
            return $app->make( self::smsDriverClass() );
        } );

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
        //
        // `sms` is registered but not in the default channel list. Registering
        // it is what makes it available; opting in — through configuration or
        // through `ap.bookings.notification.channels` — is what makes it send,
        // and that is the installation's decision to make rather than a default
        // that texts customers because nobody changed a setting.
        $this->app->singleton( NotificationService::class, function ( $app ): NotificationService {
            return new NotificationService( [
                $app->make( MailChannel::class ),
                $app->make( NotificationChannel::class ),
                $app->make( SmsChannel::class ),
            ] );
        } );

        $this->app->singleton( ReminderScheduler::class );

        // A singleton because it holds nothing per call, and bound at all so
        // that an application signing its webhooks differently — a different
        // header scheme, a secret held outside the row — replaces one class and
        // every delivery in the package goes out its way. The delivery job
        // resolves it from the container rather than constructing it, which is
        // what makes that rebinding reach the retries too.
        $this->app->singleton( WebhookDispatcher::class );
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
                CompletePastBookingsCommand::class,
                IcalTokenCommand::class,
                PollAppleCalendarsCommand::class,
                PruneCalendarEventsCommand::class,
                PruneNotificationLogCommand::class,
                PruneWebhookDeliveriesCommand::class,
                RefreshCalendarsCommand::class,
                ReissueDetachedManageTokensCommand::class,
                RenewCalendarWatchChannelsCommand::class,
                SendBookingRemindersCommand::class,
            ] );
        }

        Event::subscribe( SendBookingNotifications::class );
        Event::subscribe( DispatchBookingWebhooks::class );

        $this->registerCmsNotificationTypes();

        $this->scheduleCommands();

        $this->invalidateAvailabilityOnWrites();

        $this->registerViews();

        $this->registerPublicRoutes();

        $this->registerLivewireComponents();

        // Calendar drivers and the CMS-framework integration are registered here
        // as each is built.
    }

    /**
     * Makes the package's Blade views resolvable, and publishable.
     *
     * Loaded under the `bookings::` namespace rather than published by default,
     * so an upgrade that fixes the widget's markup reaches an installation that
     * never wanted to fork it. The publish tag is for the ones that do — and a
     * published copy wins, which is the whole point and also the thing to
     * remember when a template edit appears to do nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerViews(): void
    {
        $this->loadViewsFrom( __DIR__ . '/../../resources/views', 'bookings' );

        $this->publishes(
            [
                __DIR__ . '/../../resources/views' => resource_path( 'views/vendor/bookings' ),
            ],
            'bookings-views',
        );
    }

    /**
     * Registers the package's Livewire components, where Livewire is installed.
     *
     * Guarded rather than assumed. Livewire is a suggestion, not a requirement:
     * the JSON API, the iCal feeds, and the no-JavaScript booking form are the
     * whole surface a headless installation needs, and requiring Livewire for
     * them would put a front-end framework in a queue worker's dependency tree.
     *
     * The aliases are `artisanpack-booking-widget` and
     * `artisanpack-manage-booking` rather than namespaced names, because they are
     * written by hand into somebody else's page —
     * `<livewire:artisanpack-booking-widget />` — and the prefix is what keeps
     * them from colliding with a component the host application already has.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerLivewireComponents(): void
    {
        if ( ! class_exists( Livewire::class ) ) {
            return;
        }

        Livewire::component( 'artisanpack-booking-widget', BookingWidget::class );
        Livewire::component( 'artisanpack-manage-booking', ManageBooking::class );
    }

    /**
     * Mounts the customer-facing API.
     *
     * Loaded rather than published, so an upgrade that adds an endpoint takes
     * effect without an application re-copying a route file — and skipped
     * entirely when the application has cached its routes, since a cached route
     * file already contains these and loading them again would register every
     * one of them twice.
     *
     * The `api` group is what makes these stateless: no session, no CSRF token,
     * and a JSON answer to a failed validation rather than a redirect back to a
     * page the caller was never on. A widget on a static marketing page has none
     * of those things to offer.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerPublicRoutes(): void
    {
        Route::aliasMiddleware( 'bookings.rate-limit', RateLimitBookings::class );
        Route::aliasMiddleware( 'bookings.manage-token', ResolveManageToken::class );

        if ( $this->app->routesAreCached() ) {
            return;
        }

        Route::middleware( 'api' )->group( __DIR__ . '/../../routes/public.php' );

        // The widget's plain-HTML form target, which needs the session and the
        // CSRF token the `api` group deliberately does without.
        Route::middleware( 'web' )->group( __DIR__ . '/../../routes/widget.php' );
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
     * Gets the class the configured SMS driver names.
     *
     * `null` — the default, and what an installation that has never thought
     * about SMS has — is the driver that logs and sends nothing. Anything else
     * is taken as a class name, so an application can point the setting at its
     * own gateway from `.env` without touching the container. That is the whole
     * reason the setting holds a name rather than a key out of a driver map: the
     * package ships one driver, and a map of one entry is a list of the gateways
     * an application is *not* allowed to use.
     *
     * A name that does not resolve throws rather than falling back to the null
     * driver. An installation that configured Twilio and is silently getting log
     * lines instead has the worst version of this failure — every customer
     * unreachable, nothing obviously wrong — and a typo in a class name is
     * exactly how that happens.
     *
     * @since 1.0.0
     *
     * @throws InvalidArgumentException When the setting names no usable driver.
     *
     * @return class-string<SmsDriver> The driver class to resolve.
     */
    protected static function smsDriverClass(): string
    {
        $driver = config( 'artisanpack.bookings.notifications.sms_driver', 'null' );

        if ( ! is_string( $driver ) || '' === trim( $driver ) || 'null' === $driver ) {
            return NullSmsDriver::class;
        }

        $driver = trim( $driver );

        if ( ! class_exists( $driver ) || ! is_a( $driver, SmsDriver::class, true ) ) {
            throw new InvalidArgumentException( sprintf(
                'artisanpack.bookings.notifications.sms_driver must name a class implementing %s, got "%s".',
                SmsDriver::class,
                $driver,
            ) );
        }

        /** @var class-string<SmsDriver> $driver */
        return $driver;
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
     * Puts the package's recurring commands on the application's schedule.
     *
     * Every one of them is registered `withoutOverlapping()`. None of them
     * *needs* it for correctness — the reminder sweep claims each send in the
     * notification log, the completion sweep re-reads the status it transitions,
     * and a prune deleting rows another prune already deleted is a no-op — but a
     * doubled run is a lot of database round trips to do nothing, and on an
     * installation big enough for a sweep to outlive its interval, it is a lot
     * of them repeatedly.
     *
     * Registered from the console only. `booted()` fires on every HTTP request
     * too, so without the guard each one resolves the scheduler to describe
     * commands it will never run — which is the cost the deferred callback was
     * meant to avoid and, on its own, did not.
     *
     * `bookings:reissue-detached-manage-tokens` is deliberately absent. It
     * invalidates every manage link the package has ever sent, which is
     * something an operator does in response to a leak and nothing a clock
     * should ever decide to do.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function scheduleCommands(): void
    {
        if ( ! $this->app->runningInConsole() ) {
            return;
        }

        $this->app->booted( function (): void {
            $schedule = $this->app->make( Schedule::class );

            // Fifteen minutes is the resolution a reminder is delivered at: a
            // cadence is configured in whole hours, so a sweep this frequent
            // sends one within a quarter of an hour of the moment it came due.
            if ( (bool) config( 'artisanpack.bookings.notifications.reminder.enabled', true ) ) {
                $schedule->command( 'bookings:send-reminders' )
                    ->everyFifteenMinutes()
                    ->withoutOverlapping( self::LOCK_QUARTER_HOURLY );
            }

            // Hourly rather than more often because nothing downstream of a
            // completion is time-critical, and less often would leave a
            // provider's diary showing yesterday's work as still upcoming.
            $schedule->command( 'bookings:complete-past' )
                ->hourly()
                ->withoutOverlapping( self::LOCK_HOURLY );

            $this->scheduleCalendarCommands( $schedule );

            // Overnight, because a prune is the one thing here that holds locks
            // on tables the request path writes to.
            $schedule->command( 'bookings:prune-notification-log' )
                ->dailyAt( '03:10' )
                ->withoutOverlapping( self::LOCK_DAILY );

            $schedule->command( 'bookings:prune-webhook-deliveries' )
                ->dailyAt( '03:20' )
                ->withoutOverlapping( self::LOCK_DAILY );

            $schedule->command( 'bookings:prune-calendar-events' )
                ->dailyAt( '03:30' )
                ->withoutOverlapping( self::LOCK_DAILY );
        } );
    }

    /**
     * Puts the calendar sweeps on the schedule, where any are wanted.
     *
     * Each is gated on a driver being switched on, because none of them can do
     * anything without one and an installation that has never connected a
     * calendar should not have three commands waking up to say so. Apple is
     * gated on its own driver rather than on any driver: it is polled every
     * fifteen minutes precisely because CalDAV cannot push, and running that
     * frequency for an installation that only uses Google would be paying
     * Apple's cost for nobody's benefit.
     *
     * @since 1.0.0
     *
     * @param  Schedule  $schedule  The application's schedule.
     *
     * @return void
     */
    protected function scheduleCalendarCommands( Schedule $schedule ): void
    {
        $drivers = (array) config( 'artisanpack.bookings.calendar.drivers', [] );
        $enabled = static fn ( string $driver ): bool => (bool) ( $drivers[ $driver ]['enabled'] ?? false );

        if ( $enabled( 'google' ) || $enabled( 'microsoft' ) || $enabled( 'apple' ) ) {
            // Daily: this is the backstop under push sync, not the mechanism.
            $schedule->command( 'bookings:calendar-refresh' )
                ->daily()
                ->withoutOverlapping( self::LOCK_DAILY );
        }

        // Hourly, against registrations that are renewed an hour before they
        // lapse — so a renewal that fails has a whole run to be retried in
        // before the channel behind it goes quiet.
        if ( $enabled( 'google' ) || $enabled( 'microsoft' ) ) {
            $schedule->command( 'bookings:calendar-watch-renew' )
                ->hourly()
                ->withoutOverlapping( self::LOCK_HOURLY );
        }

        if ( $enabled( 'apple' ) ) {
            $schedule->command( 'bookings:calendar-apple-poll' )
                ->everyFifteenMinutes()
                ->withoutOverlapping( self::LOCK_QUARTER_HOURLY );
        }
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
