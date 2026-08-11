<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\AvailabilityOverrideType;
use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Enums\CalendarSyncMode;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Enums\ServiceAssignmentStrategy;
use ArtisanPackUI\Bookings\Enums\WebhookDeliveryStatus;
use ArtisanPackUI\Bookings\Models\AvailabilityOverride;
use ArtisanPackUI\Bookings\Models\AvailabilitySchedule;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceBlackoutDate;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Models\Webhook;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use ArtisanPackUI\Bookings\Providers\BookingsServiceProvider;
use ArtisanPackUI\Bookings\Services\AvailabilityService;
use ArtisanPackUI\Bookings\Services\BookingService;
use ArtisanPackUI\Bookings\Services\IcalFeedService;
use ArtisanPackUI\Bookings\Services\ProviderSlotLock;
use ArtisanPackUI\Bookings\Services\SeriesService;
use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\TimeRange;
use ArtisanPackUI\Core\Contracts\SiteResolver;
use ArtisanPackUI\Core\MultiTenancy\SiteContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FixedSiteResolver;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend( Tests\TestCase::class )
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in( 'Feature' );

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend( 'toBeOne', function () {
    return $this->toBe( 1 );
} );

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Enables site scoping and puts the given site in context.
 *
 * Rebuilds core's shared site context around a fixed resolver rather than
 * stubbing the context itself, so the tests still run through the real
 * `SiteContext` — including its `enabled` check, which is the thing that
 * decides whether a resolver is consulted at all.
 *
 * @param  int|string|null  $siteId  The site to resolve, or null for none.
 *
 * @return void
 */
function scopeToSite( int|string|null $siteId ): void
{
    config()->set( 'artisanpack.core.multi_tenant.enabled', true );

    app()->instance( SiteResolver::class, new FixedSiteResolver( $siteId ) );
    app()->instance( SiteContext::class, new SiteContext(
        app( SiteResolver::class ),
        app( 'config' ),
    ) );
}

/**
 * Registers the migration suite against whichever engine the calling file uses.
 *
 * The initial migration set has to produce the same schema and enforce the same
 * rules on SQLite, MySQL, and Postgres, and one of those rules — the partial
 * unique index guarding a provider's slot — is implemented two different ways
 * because MySQL has no partial indexes. Asserting that once per engine from a
 * shared definition is the point: a divergence surfaces as one engine's file
 * failing, rather than as a case somebody only ever wrote for SQLite.
 *
 * The calling file supplies the engine by using the matching TestsWith* concern
 * before calling this; MySQL and Postgres skip when no server is reachable,
 * except in CI, where BOOKINGS_REQUIRE_EXTERNAL_DB turns that skip into a
 * failure.
 *
 * Each expected-failure insert runs inside its own transaction so that the
 * violation is contained. Postgres aborts a transaction on any failed
 * statement, and the surrounding test is already inside one — without the
 * savepoint a caught exception would poison every query that followed it.
 *
 * @return void
 */
function defineBookingMigrationTests(): void
{
    it( 'creates every table in the initial migration set', function (): void {
        $this->assertEveryTableExists();
    } );

    it( 'gives every site-owned table a nullable indexed site_id', function (): void {
        $this->assertSiteScopingColumnsExist();
    } );

    it( 'makes the personal-data tables soft deletable and erasable', function (): void {
        $this->assertErasureColumnsExist();
    } );

    it( 'keeps a provider feed token unique and lets providers have none', function (): void {
        // Nullable and unique together: a provider has no feed until one is
        // minted, so the column has to admit many nulls while admitting no two
        // providers sharing a token. MySQL, Postgres, and sqlite all treat NULLs
        // as distinct in a unique index, which is the behaviour being relied on.
        $hash = hash( 'sha256', 'a-feed-token' );

        $this->insertProvider( [ 'ical_token_hash' => null ] );
        $this->insertProvider( [ 'ical_token_hash' => null ] );
        $this->insertProvider( [ 'ical_token_hash' => $hash ] );

        expect( fn () => DB::transaction( fn () => $this->insertProvider( [ 'ical_token_hash' => $hash ] ) ) )
            ->toThrow( QueryException::class );
    } );

    it( 'refuses a second active booking in the same provider slot', function (): void {
        // The race the round-robin assigner has to survive: two requests taking
        // the same slot at the same instant. An advisory lock is the first line
        // of defence, but it is only as good as the process holding it, so the
        // index has to hold on its own.
        $service  = $this->insertService();
        $provider = $this->insertProvider();

        $this->insertBooking( [ 'service_id' => $service, 'provider_id' => $provider ] );

        expect( fn () => DB::transaction( fn () => $this->insertBooking( [
            'service_id'  => $service,
            'provider_id' => $provider,
            'status'      => 'requested',
        ] ) ) )->toThrow( QueryException::class );
    } );

    it( 'frees the slot once the booking holding it is cancelled', function (): void {
        // A plain unique index would block this too, which is why the guard has
        // to be partial: rebooking a slot you cancelled is not a race.
        $service  = $this->insertService();
        $provider = $this->insertProvider();

        $this->insertBooking( [
            'service_id'  => $service,
            'provider_id' => $provider,
            'status'      => 'cancelled',
        ] );

        expect( $this->insertBooking( [ 'service_id' => $service, 'provider_id' => $provider ] ) )
            ->toBeGreaterThan( 0 );
    } );

    it( 'frees the slot once the booking holding it is soft deleted', function (): void {
        // A soft-deleted booking is invisible to every domain query, so leaving
        // it in the index would block a slot availability reports as free.
        $service  = $this->insertService();
        $provider = $this->insertProvider();

        $first = $this->insertBooking( [ 'service_id' => $service, 'provider_id' => $provider ] );
        DB::table( 'bookings' )->where( 'id', $first )->update( [ 'deleted_at' => now() ] );

        expect( $this->insertBooking( [ 'service_id' => $service, 'provider_id' => $provider ] ) )
            ->toBeGreaterThan( 0 );
    } );

    it( 'lets two providers hold the same start time', function (): void {
        $service = $this->insertService();

        $first  = $this->insertBooking( [ 'service_id' => $service, 'provider_id' => $this->insertProvider() ] );
        $second = $this->insertBooking( [ 'service_id' => $service, 'provider_id' => $this->insertProvider() ] );

        expect( $first )->not->toBe( $second );
    } );

    it( 'lets unassigned bookings share a start time', function (): void {
        // Nobody's slot is taken until a provider is assigned, so the guard has
        // to ignore these rather than serialising every unassigned request.
        $service = $this->insertService();

        $first  = $this->insertBooking( [ 'service_id' => $service ] );
        $second = $this->insertBooking( [ 'service_id' => $service ] );

        expect( $first )->not->toBe( $second );
    } );

    it( 'refuses a duplicate reminder for the same booking and schedule', function (): void {
        // This is what makes the reminder cron idempotent: a double run loses
        // the insert rather than emailing the customer twice.
        $booking = $this->insertBooking( [ 'service_id' => $this->insertService() ] );

        $this->insertNotificationLog( [ 'booking_id' => $booking ] );

        expect( fn () => DB::transaction( fn () => $this->insertNotificationLog( [
            'booking_id' => $booking,
        ] ) ) )->toThrow( QueryException::class );
    } );

    it( 'still records unscheduled notifications more than once', function (): void {
        // A second reschedule genuinely warrants a second email. Only scheduled
        // sends are deduplicated, which falls out of NULLs being distinct in a
        // unique index on every engine the package supports.
        $booking = $this->insertBooking( [ 'service_id' => $this->insertService() ] );

        $this->insertNotificationLog( [
            'booking_id'    => $booking,
            'type'          => 'reschedule',
            'scheduled_for' => null,
        ] );
        $this->insertNotificationLog( [
            'booking_id'    => $booking,
            'type'          => 'reschedule',
            'scheduled_for' => null,
        ] );

        expect( DB::table( 'booking_notification_log' )->where( 'booking_id', $booking )->count() )
            ->toBe( 2 );
    } );

    it( 'refuses a duplicate slug on a single-tenant installation', function (): void {
        // The case UNIQUE(site_id, slug) silently drops: nulls are distinct, so
        // on the default single-tenant setup — where every row's site_id is
        // null — that index enforces nothing on its own.
        $this->insertService( [ 'slug' => 'consultation' ] );
        $this->insertProvider( [ 'slug' => 'dana' ] );

        expect( fn () => DB::transaction( fn () => $this->insertService( [ 'slug' => 'consultation' ] ) ) )
            ->toThrow( QueryException::class );
        expect( fn () => DB::transaction( fn () => $this->insertProvider( [ 'slug' => 'dana' ] ) ) )
            ->toThrow( QueryException::class );
    } );

    it( 'lets two sites use the same slug', function (): void {
        // Which is the whole reason the slug is scoped rather than global: two
        // tenants both wanting "consultation" is the normal case, not a clash.
        $this->insertService( [ 'slug' => 'consultation', 'site_id' => 1 ] );
        $this->insertProvider( [ 'slug' => 'dana', 'site_id' => 1 ] );

        expect( $this->insertService( [ 'slug' => 'consultation', 'site_id' => 2 ] ) )->toBeGreaterThan( 0 );
        expect( $this->insertProvider( [ 'slug' => 'dana', 'site_id' => 2 ] ) )->toBeGreaterThan( 0 );
    } );

    it( 'refuses to hard delete a service that still has bookings', function (): void {
        // Services are soft deletable, so a hard delete is an explicit
        // destructive act — and it must not silently take every booking ever
        // made against the service with it. Retention pruning clears the
        // bookings first; until then the delete fails loudly.
        $service = $this->insertService();
        $this->insertBooking( [ 'service_id' => $service, 'provider_id' => $this->insertProvider() ] );

        expect( fn () => DB::transaction( fn () => DB::table( 'services' )->where( 'id', $service )->delete() ) )
            ->toThrow( QueryException::class );
    } );

    it( 'detaches occurrences when the series behind them is deleted', function (): void {
        // The one relationship that is not restricted: losing the rule that
        // generated a set of occurrences should not lose the occurrences.
        $series = DB::table( 'booking_series' )->insertGetId( [
            'service_id'            => $this->insertService(),
            'customer_name'         => 'Sam',
            'customer_email'        => 'sam@example.test',
            'rrule'                 => 'FREQ=WEEKLY;COUNT=4',
            'dtstart_local'         => '2026-03-02 15:00:00',
            'dtstart_timezone'      => 'America/Chicago',
            'intake_schema_version' => 1,
            'created_at'            => now(),
            'updated_at'            => now(),
        ] );

        $booking = $this->insertBooking( [
            'service_id'   => $this->insertService(),
            'series_id'    => $series,
            'series_index' => 1,
        ] );

        DB::table( 'booking_series' )->where( 'id', $series )->delete();

        expect( DB::table( 'bookings' )->where( 'id', $booking )->value( 'series_id' ) )->toBeNull();
    } );

    it( 'indexes the columns the availability and admin queries filter on', function (): void {
        expect( $this->indexColumns( 'bookings', 'bookings_provider_id_start_time_index' ) )
            ->toBe( [ 'provider_id', 'start_time' ] );
        expect( $this->indexColumns( 'bookings', 'bookings_site_id_status_start_time_index' ) )
            ->toBe( [ 'site_id', 'status', 'start_time' ] );
        expect( $this->indexColumns( 'bookings', 'bookings_series_id_series_index_index' ) )
            ->toBe( [ 'series_id', 'series_index' ] );
    } );

    it( 'rolls every table back', function (): void {
        $this->assertRollbackDropsEveryTable();
    } );

    it( 'leaves a usable schema behind the rollback test', function (): void {
        // Deliberately defined after the rollback test, and it has to stay
        // there. The rollback test drops all sixteen tables, and on MySQL that
        // DDL commits implicitly — the transaction RefreshDatabase opens cannot
        // put them back. Testbench restores the schema between tests today, so
        // this passes either way; it is here so that if that ever stops being
        // true, one test says so instead of every later test failing at once.
        expect( $this->insertBooking( [ 'service_id' => $this->insertService() ] ) )->toBeGreaterThan( 0 );
    } );
}

/**
 * Registers the engine-sensitive model assertions against the calling engine.
 *
 * Most of what the models do is engine-agnostic and is covered once, in
 * tests/Feature/Models, against sqlite. These are the exceptions: the handful of
 * places where the answer depends on how a particular server stores or compares
 * a value, and where a green sqlite suite would therefore prove nothing about
 * the other two.
 *
 * Three things drive the list. Eloquent writes a `date` cast through the
 * connection's date format, so a stored bound carries a midnight time component
 * that a native DATE column does not — which is why every date-range scope is
 * asserted on its own boundary days here. JSON columns are a real type on MySQL
 * and Postgres and text on sqlite. And the notification log's idempotency rests
 * on a savepoint, which only means anything on a server that aborts a
 * transaction after a failed statement — Postgres does, sqlite does not, so the
 * sqlite run of this file proves the code path and the Postgres run proves the
 * reason it exists.
 *
 * The calling file supplies the engine by using the matching TestsWith*
 * concern before calling this.
 *
 * @return void
 */
function defineEngineSensitiveModelTests(): void
{
    it( 'applies a schedule on the first and last day it is in force', function (): void {
        $provider = ServiceProvider::factory()->create();

        AvailabilitySchedule::factory()
            ->for( $provider, 'provider' )
            ->nineToFive( 3 )
            ->effective( '2026-09-01', '2026-09-30' )
            ->create();

        expect( AvailabilitySchedule::for( $provider, 3, '2026-09-01' )->count() )->toBe( 1 )
            ->and( AvailabilitySchedule::for( $provider, 3, '2026-09-30' )->count() )->toBe( 1 )
            ->and( AvailabilitySchedule::for( $provider, 3, '2026-08-31' )->count() )->toBe( 0 )
            ->and( AvailabilitySchedule::for( $provider, 3, '2026-10-01' )->count() )->toBe( 0 );
    } );

    it( 'closes a blackout on the first and last day of its range', function (): void {
        $service = Service::factory()->create();

        ServiceBlackoutDate::factory()->for( $service )->create( [
            'starts_on' => '2026-12-24',
            'ends_on'   => '2026-12-26',
        ] );

        expect( ServiceBlackoutDate::closing( $service, '2026-12-24' )->count() )->toBe( 1 )
            ->and( ServiceBlackoutDate::closing( $service, '2026-12-26' )->count() )->toBe( 1 )
            ->and( ServiceBlackoutDate::closing( $service, '2026-12-23' )->count() )->toBe( 0 )
            ->and( ServiceBlackoutDate::closing( $service, '2026-12-27' )->count() )->toBe( 0 );
    } );

    it( 'finds an availability override on its own date', function (): void {
        $provider = ServiceProvider::factory()->create();

        AvailabilityOverride::factory()->for( $provider, 'provider' )->onDate( '2026-07-14' )->create();

        expect( AvailabilityOverride::for( $provider, '2026-07-14' )->count() )->toBe( 1 )
            ->and( AvailabilityOverride::for( $provider, '2026-07-13' )->count() )->toBe( 0 )
            ->and( AvailabilityOverride::for( $provider, '2026-07-15' )->count() )->toBe( 0 );
    } );

    it( 'round-trips a wall-clock time through the engine\'s own time type', function (): void {
        $schedule = AvailabilitySchedule::factory()->between( '09:05', '17:30' )->create();

        expect( $schedule->fresh()->start_time_local )->toBe( '09:05:00' )
            ->and( $schedule->fresh()->end_time_local )->toBe( '17:30:00' );
    } );

    it( 'round-trips nested JSON through the engine\'s own JSON type', function (): void {
        // Content survives everywhere; the order of an object's keys does not.
        // MySQL's native JSON type stores objects sorted by key and hands them
        // back that way, so `{"event": …, "data": …}` returns as `{"data": …,
        // "event": …}` there and unchanged on sqlite. Hence the canonicalizing
        // comparison — an exact one would pass on two engines and fail on the
        // third for a difference nothing in the package cares about.
        $schema  = [
            'fields' => [
                [ 'name' => 'goal', 'type' => 'textarea', 'required' => true ],
                [ 'name' => 'referrer', 'type' => 'text', 'required' => false ],
            ],
        ];
        $service = Service::factory()->create( [ 'intake_schema' => $schema ] );

        $payload  = [ 'event' => 'booking.created', 'data' => [ 'id' => 7, 'tags' => [ 'vip', 'retainer' ] ] ];
        $delivery = WebhookDelivery::factory()->create( [ 'payload' => $payload ] );

        expect( $service->fresh()->intake_schema )->toEqualCanonicalizing( $schema )
            ->and( $delivery->fresh()->payload )->toEqualCanonicalizing( $payload );

        // What the package does depend on: the order of a JSON *list*. Every
        // engine preserves that, which is why an intake form is a list of
        // fields rather than an object keyed by field name — the latter would
        // render its questions in a different order on MySQL.
        expect( array_column( $service->fresh()->intake_schema['fields'], 'name' ) )
            ->toBe( [ 'goal', 'referrer' ] )
            ->and( $delivery->fresh()->payload['data']['tags'] )->toBe( [ 'vip', 'retainer' ] )
            ->and( Webhook::factory()->subscribedTo( [ 'booking.created', 'booking.cancelled' ] )->create()->fresh()->events )
            ->toBe( [ 'booking.created', 'booking.cancelled' ] );
    } );

    it( 'round-trips every enum against the engine\'s own column type', function (): void {
        // MySQL and Postgres both constrain these columns to a fixed set of
        // strings, so a PHP enum whose values drifted from the migration would
        // fail on write here while passing happily against sqlite's text.
        $booking = Booking::factory()->create( [ 'status' => BookingStatus::NoShow ] );

        expect( $booking->fresh()->status )->toBe( BookingStatus::NoShow )
            ->and( Service::factory()->roundRobin()->create()->fresh()->assignment_strategy )
            ->toBe( ServiceAssignmentStrategy::RoundRobin )
            ->and( CalendarConnection::factory()->twoWay()->create()->fresh()->sync_mode )
            ->toBe( CalendarSyncMode::TwoWay )
            ->and( AvailabilityOverride::factory()->customHours()->create()->fresh()->type )
            ->toBe( AvailabilityOverrideType::CustomHours )
            ->and( WebhookDelivery::factory()->dead()->create()->fresh()->status )
            ->toBe( WebhookDeliveryStatus::Dead );
    } );

    it( 'loses a duplicate notification claim without poisoning the transaction', function (): void {
        // The reason logSend() wraps its insert in a nested transaction. Postgres
        // aborts an entire transaction on any failed statement, so without the
        // savepoint the caught duplicate would take every query after it down
        // with it — and the caller would find that out several statements later,
        // somewhere unrelated.
        $booking = Booking::factory()->create();

        DB::transaction( function () use ( $booking ): void {
            $first  = NotificationLog::logSend( $booking, NotificationType::Reminder, 'mail', 'sam@example.test', '2026-05-01 15:00:00' );
            $second = NotificationLog::logSend( $booking, NotificationType::Reminder, 'mail', 'sam@example.test', '2026-05-01 15:00:00' );

            expect( $first )->not->toBeNull()
                ->and( $second )->toBeNull();

            // The query that proves the point: still usable, same transaction.
            expect( NotificationLog::query()->count() )->toBe( 1 );
        } );

        expect( NotificationLog::query()->count() )->toBe( 1 );
    } );
}

/**
 * Registers the availability assertions that only a real server can settle.
 *
 * Availability is computed almost entirely in PHP, so one engine proves the
 * arithmetic and there is no reason to pay for three. What does not carry over
 * is the SQL the computation issues on its way: sqlite takes identifiers every
 * other engine reserves, so a query it accepts can still be a syntax error on
 * MySQL — `before` is one such word, and an aggregate aliased to it fails only
 * there. These cases exist to run the real queries against a real server.
 *
 * The calling file supplies the engine by using the matching TestsWith* concern
 * before calling this.
 *
 * @return void
 */
function defineEngineSensitiveAvailabilityTests(): void
{
    it( 'resolves a day against the engine\'s own SQL', function (): void {
        config()->set( 'artisanpack.bookings.slot_interval', 60 );

        $service  = Service::factory()->create( [
            'duration'      => 60,
            'buffer_before' => 0,
            'buffer_after'  => 15,
        ] );
        $provider = bookingsSchedule(
            $service,
            ServiceProvider::factory()->inTimezone( 'America/Chicago' )->create(),
        );

        $slots = availability()->resolve(
            $service,
            $provider,
            localDayWindow( '2026-06-01', 'America/Chicago' ),
        );

        expect( localStarts( $slots, 'America/Chicago' ) )
            ->toBe( [ '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00' ] );
    } );

    it( 'takes a booked slot out against the engine\'s own SQL', function (): void {
        // Reaches the aggregate that bounds the booking fetch, the buffer
        // comparison, and the UTC bounds on the bookings query — the three
        // places this computation actually talks to the server.
        config()->set( 'artisanpack.bookings.slot_interval', 60 );

        $service  = Service::factory()->create( [
            'duration'      => 60,
            'buffer_before' => 0,
            'buffer_after'  => 15,
        ] );
        $provider = bookingsSchedule(
            $service,
            ServiceProvider::factory()->inTimezone( 'America/Chicago' )->create(),
        );

        Booking::factory()
            ->for( $service )
            ->for( $provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( '2026-06-01 11:00', 'America/Chicago' )->utc(), 60 )
            ->create();

        $starts = localStarts(
            availability()->resolve( $service, $provider, localDayWindow( '2026-06-01', 'America/Chicago' ) ),
            'America/Chicago',
        );

        expect( $starts )->not->toContain( '11:00' )
            ->and( $starts )->not->toContain( '12:00' )
            ->and( $starts )->toContain( '13:00' );
    } );
}

/**
 * Defines the iCal stamp cases every engine has to agree on.
 *
 * The feed's entity tag comes from one aggregate — a max, a count, and a
 * conditional sum with a bound parameter in the select list. Two things about it
 * only exist on a real server: whether the engine accepts `sum(case when …)` at
 * all, and whether the binding in the select list lands ahead of the bindings in
 * the where clause. Get the second wrong and the query does not fail — it
 * compares the wrong column to the wrong value and quietly returns a stamp that
 * never moves, which is a feed that never updates.
 *
 * The calling file supplies the engine by using the matching TestsWith* concern
 * before calling this.
 *
 * @since 1.0.0
 *
 * @return void
 */
function defineEngineSensitiveIcalStampTests(): void
{
    it( 'stamps a provider feed against the engine\'s own SQL', function (): void {
        // The diary every booking helper works around; the window is measured
        // from now, so a clock left on the real date puts the whole of it in the
        // past and the aggregate would be counting nothing.
        $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );

        [ $service ] = bookableService();

        $provider = ServiceProvider::query()->firstOrFail();
        $feeds    = app( IcalFeedService::class );

        $empty = $feeds->providerStamp( $provider, $feeds->window() );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'provider'   => $provider,
            'start_time' => bookingStart(),
        ] ) );

        $booked = $feeds->providerStamp( $provider, $feeds->window() );

        bookingService()->cancel( $booking, BookingActor::Customer );

        $cancelled = $feeds->providerStamp( $provider, $feeds->window() );

        // Three distinct answers. The third is the one the conditional sum is
        // there for: the row is still in the window and its timestamp has not
        // gone backwards, so only the count of what is still published can tell
        // the engine that the feed's contents shrank.
        expect( $booked )->not->toBe( $empty )
            ->and( $cancelled )->not->toBe( $booked )
            ->and( $cancelled )->not->toBe( $empty );
    } );

    it( 'stamps only the window it was asked about', function (): void {
        $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );

        [ $service ] = bookableService();

        $provider = ServiceProvider::query()->firstOrFail();
        $feeds    = app( IcalFeedService::class );

        bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'provider'   => $provider,
            'start_time' => bookingStart(),
        ] ) );

        // The booking is months out, so a window ending tomorrow must not see it
        // — which is the range comparison, on whatever the engine stores
        // start_time as.
        $narrow = new TimeRange(
            CarbonImmutable::now()->utc()->subDay(),
            CarbonImmutable::now()->utc()->addDay(),
        );

        expect( $feeds->providerStamp( $provider, $narrow ) )
            ->not->toBe( $feeds->providerStamp( $provider, $feeds->window() ) );
    } );
}

/**
 * Gets the booking service out of the container.
 *
 * @since 1.0.0
 *
 * @return BookingService The service under test.
 */
function bookingService(): BookingService
{
    return app( BookingService::class );
}

/**
 * Gets the series service out of the container.
 *
 * @since 1.0.0
 *
 * @return SeriesService The service under test.
 */
function seriesService(): SeriesService
{
    return app( SeriesService::class );
}

/**
 * Builds a service and however many providers who can all take the same slot.
 *
 * Every provider works the same hours in the same zone, which is the shape the
 * assignment cases want: with availability identical across the rota, the only
 * thing that can decide who gets a booking is the rotation itself.
 *
 * Monday 1 June 2026 is the day the helpers below name. It is chosen for being
 * an ordinary Monday nowhere near a daylight-saving boundary — the clock-change
 * cases are the availability suite's business, and a rotation test tripping over
 * one would be reporting somebody else's bug.
 *
 * @since 1.0.0
 *
 * @param  int  $providerCount  How many providers to attach.
 * @param  string  $timezone  The zone they all work in.
 *
 * @return array{0: Service, 1: array<int, ServiceProvider>} The service and its providers.
 */
function bookableService( int $providerCount = 1, string $timezone = 'America/Chicago' ): array
{
    config()->set( 'artisanpack.bookings.slot_interval', 60 );

    /** @var Service $service */
    $service = Service::factory()->roundRobin()->create( [
        'duration'      => 60,
        'buffer_before' => 0,
        'buffer_after'  => 0,
    ] );

    $providers = [];

    for ( $index = 0; $index < $providerCount; $index++ ) {
        $providers[] = bookingsSchedule(
            $service,
            ServiceProvider::factory()->inTimezone( $timezone )->create(),
        );
    }

    return [ $service, $providers ];
}

/**
 * Gets an instant inside the window {@see bookableService()} opens.
 *
 * @since 1.0.0
 *
 * @param  string  $time  The provider-local clock face, as `H:i`.
 * @param  string  $timezone  The zone the clock face belongs to.
 *
 * @return CarbonImmutable The instant, in UTC.
 */
function bookingStart( string $time = '10:00', string $timezone = 'America/Chicago' ): CarbonImmutable
{
    return CarbonImmutable::parse( '2026-06-01 ' . $time, $timezone )->utc();
}

/**
 * Builds the attributes a booking needs beyond the service and the time.
 *
 * @since 1.0.0
 *
 * @param  array<string, mixed>  $overrides  Anything to change.
 *
 * @return array<string, mixed> The customer's details.
 */
function bookingCustomer( array $overrides = [] ): array
{
    return $overrides + [
        'customer_name'     => 'Sam Rivera',
        'customer_email'    => 'sam@example.test',
        'customer_timezone' => 'America/Chicago',
    ];
}

/**
 * Builds the attributes a recurring series needs beyond the service.
 *
 * Defaults to three consecutive Mondays at the local hour {@see bookingStart()}
 * names, inside the window {@see bookableService()} opens — so the interesting
 * part of a series case is the rule and the edit scope rather than the diary.
 *
 * `start` is a convenience the service itself does not take: it sets the local
 * clock face on `dtstart_local`, so a second series in the same test can be put
 * an hour along without restating the date.
 *
 * @since 1.0.0
 *
 * @param  array<string, mixed>  $overrides  Anything to change.
 *
 * @return array<string, mixed> The series attributes.
 */
function recurringCustomer( array $overrides = [] ): array
{
    $start = $overrides['start'] ?? '10:00';

    unset( $overrides['start'] );

    return $overrides + [
        'rrule'            => 'FREQ=WEEKLY;COUNT=3',
        'dtstart_local'    => '2026-06-01 ' . $start . ':00',
        'dtstart_timezone' => 'America/Chicago',
        'customer_name'    => 'Sam Rivera',
        'customer_email'   => 'sam@example.test',
        'intake_data'      => [],
    ];
}

/**
 * Registers the slot-lock assertions that only a real server can settle.
 *
 * The lock is the first of the three layers that stop two customers taking the
 * same slot, and it is the only one sqlite cannot express: there is no advisory
 * lock there, so the sqlite suite exercises a cache lock standing in for one.
 * That proves the code path and nothing about the primitive. These cases run the
 * real thing against a second connection — which is what a second PHP worker
 * actually is — and check that it excludes, releases, and does not over-block.
 *
 * The calling file supplies the engine by using the matching TestsWith* concern
 * before calling this.
 *
 * @return void
 */
function defineSlotLockTests(): void
{
    it( 'holds a provider\'s slot against a second connection', function (): void {
        $start = CarbonImmutable::parse( '2026-06-01 15:00', 'UTC' );
        $held  = null;

        app( ProviderSlotLock::class )->withSlotLock( 7, $start, 'UTC', function () use ( $start, &$held ): void {
            $held = contenderHoldsSlot( 7, $start );
        } );

        expect( $held )->toBeFalse();
    } );

    it( 'lets the next caller take the slot once the first is done', function (): void {
        $start = CarbonImmutable::parse( '2026-06-01 16:00', 'UTC' );

        app( ProviderSlotLock::class )->withSlotLock( 7, $start, 'UTC', static fn (): bool => true );

        expect( contenderHoldsSlot( 7, $start ) )->toBeTrue();
    } );

    it( 'releases the slot even when the booking inside it fails', function (): void {
        // The failure that matters: a lost race throws, and a lock still held
        // afterwards would stall every later attempt on that slot for the life
        // of the connection.
        $start = CarbonImmutable::parse( '2026-06-01 17:00', 'UTC' );

        try {
            app( ProviderSlotLock::class )->withSlotLock( 7, $start, 'UTC', static function (): void {
                throw new RuntimeException( 'Lost the race.' );
            } );
        } catch ( RuntimeException ) {
            // The point of the test is what happens next.
        }

        expect( contenderHoldsSlot( 7, $start ) )->toBeTrue();
    } );

    it( 'leaves other providers and other days free while it holds one', function (): void {
        // Serialising more than the one contended provider-day would turn a busy
        // diary into a queue, which is the failure mode a table lock would have.
        //
        // A different *hour* is deliberately not asserted here: the lock buckets
        // by the day precisely so that two overlapping slots cannot take
        // different locks, so 18:00 and 19:00 sharing one is the guard working
        // rather than the guard being too coarse.
        $start = CarbonImmutable::parse( '2026-06-01 18:00', 'UTC' );
        $other = [];

        app( ProviderSlotLock::class )->withSlotLock( 7, $start, 'UTC', function () use ( $start, &$other ): void {
            $other['provider']  = contenderHoldsSlot( 8, $start );
            $other['day']       = contenderHoldsSlot( 7, $start->addDay() );
            $other['same_hour'] = contenderHoldsSlot( 7, $start->addHour() );
        } );

        expect( $other['provider'] )->toBeTrue()
            ->and( $other['day'] )->toBeTrue()
            ->and( $other['same_hour'] )->toBeFalse();
    } );
}

/**
 * Asks a second database connection whether it can take a slot's lock.
 *
 * Non-blocking on both engines, because "would have waited" is the answer being
 * asserted on and a test that actually waited would just be slow.
 *
 * @param  int  $providerId  The provider whose slot to try for.
 * @param  CarbonImmutable  $start  The instant the slot begins.
 *
 * @return bool True when the second connection got the lock.
 */
function contenderHoldsSlot( int $providerId, CarbonImmutable $start ): bool
{
    $lock   = app( ProviderSlotLock::class );
    $driver = DB::connection()->getDriverName();
    $name   = 'pgsql' === $driver ? 'pgsql_contender' : 'mysql_contender';

    config()->set( 'database.connections.' . $name, config( 'database.connections.' . $driver ) );

    $contender = DB::connection( $name );

    try {
        if ( 'pgsql' === $driver ) {
            $key = $lock->lockKey( $providerId, $start, 'UTC' );

            // Cast in SQL: pdo_pgsql has returned booleans as both real bools
            // and the strings 't'/'f' depending on version, and 'f' is truthy.
            $acquired = 1 === (int) $contender
                ->selectOne( 'select pg_try_advisory_lock(?)::int as acquired', [ $key ] )
                ->acquired;

            if ( $acquired ) {
                $contender->selectOne( 'select pg_advisory_unlock(?) as released', [ $key ] );
            }

            return $acquired;
        }

        $lockName = $lock->lockName( $providerId, $start, 'UTC' );
        $acquired = 1 === (int) $contender
            ->selectOne( 'select get_lock(?, 0) as acquired', [ $lockName ] )
            ->acquired;

        if ( $acquired ) {
            $contender->selectOne( 'select release_lock(?) as released', [ $lockName ] );
        }

        return $acquired;
    } finally {
        $contender->disconnect();
    }
}

/**
 * Re-runs the provider's schedule registration and hands back what it produced.
 *
 * The provider registers on `booted`, which has already fired by the time a test
 * body runs, so the callback is invoked again against a schedule of its own. What
 * is under test is the registration logic — which commands, at what cadence,
 * under what configuration — rather than the framework's ability to hold a list.
 *
 * @since 1.0.0
 *
 * @return array<int, ScheduledEvent> The events the provider registered.
 */
function bookingScheduleEvents(): array
{
    app()->forgetInstance( Schedule::class );
    app()->instance( Schedule::class, new Schedule() );

    $provider = new BookingsServiceProvider( app() );
    $register = ( new ReflectionClass( $provider ) )->getMethod( 'scheduleCommands' );

    $register->setAccessible( true );
    $register->invoke( $provider );

    return app( Schedule::class )->events();
}

/**
 * Gets the cron expression the provider scheduled each command at.
 *
 * @since 1.0.0
 *
 * @return array<string, string> The expression, keyed by artisan command name.
 */
function bookingSchedule(): array
{
    $registered = [];

    foreach ( bookingScheduleEvents() as $event ) {
        // The event's command is a whole shell invocation — the PHP binary, the
        // artisan script, the command, and its arguments — so the name is the
        // one part of it that looks like a command this package owns.
        preg_match( '/bookings:[a-z-]+/', (string) $event->command, $matches );

        $registered[ $matches[0] ?? (string) $event->command ] = $event->expression;
    }

    return $registered;
}

/**
 * Backdates a row's timestamps, so a retention window can be tested against it.
 *
 * Written straight to the table rather than through the model, because
 * `created_at` is what a prune reads and Eloquent overwrites it on save.
 *
 * @since 1.0.0
 *
 * @param  Model  $model  The row to age.
 * @param  string  $age  A relative time to stamp it with.
 *
 * @return void
 */
function bookingRowAgedTo( Model $model, string $age ): void
{
    $model->newQuery()->whereKey( $model->getKey() )->update( [
        'created_at' => CarbonImmutable::parse( $age ),
        'updated_at' => CarbonImmutable::parse( $age ),
    ] );
}

/**
 * Builds a UTC time range from two `H:i` clock faces on one fixed day.
 *
 * Lives here rather than in a test file because Pest loads every test file into
 * a single process, so a helper declared in one is a redeclaration fatal
 * waiting for the second file to want the same name.
 *
 * @since 1.0.0
 *
 * @param  string  $start  The start clock face, as `H:i`.
 * @param  string  $end  The end clock face, as `H:i`.
 *
 * @return TimeRange The range, on 2026-04-06 in UTC.
 */
function utcRange( string $start, string $end ): TimeRange
{
    return new TimeRange(
        CarbonImmutable::parse( '2026-04-06 ' . $start, 'UTC' ),
        CarbonImmutable::parse( '2026-04-06 ' . $end, 'UTC' ),
    );
}

/**
 * Builds a window a whole working day wide.
 *
 * @since 1.0.0
 *
 * @return TimeRange 09:00 to 17:00 UTC on 2026-04-06.
 */
function contractWindow(): TimeRange
{
    return utcRange( '09:00', '17:00' );
}

/**
 * Gets the availability service out of the container.
 *
 * @since 1.0.0
 *
 * @return AvailabilityService The resolver under test.
 */
function availability(): AvailabilityService
{
    return app( AvailabilityService::class );
}

/**
 * Builds a window covering one whole local day, as instants.
 *
 * The window a widget asks with is a span of real time, not a date, so the
 * bounds are the moments the local day opens and closes — which is 23 or 25
 * hours wide on the two days a year the offset moves.
 *
 * @since 1.0.0
 *
 * @param  string  $date  The local date, as `Y-m-d`.
 * @param  string  $timezone  The zone the date belongs to.
 *
 * @return TimeRange The window covering that day.
 */
function localDayWindow( string $date, string $timezone ): TimeRange
{
    $start = CarbonImmutable::parse( $date, $timezone )->startOfDay();

    return new TimeRange( $start, $start->addDay() );
}

/**
 * Builds a window between two clock faces on one local date.
 *
 * @since 1.0.0
 *
 * @param  string  $date  The local date, as `Y-m-d`.
 * @param  string  $start  The opening clock face, as `H:i`.
 * @param  string  $end  The closing clock face, as `H:i`.
 * @param  string  $timezone  The zone the clock faces belong to.
 *
 * @return TimeRange The window.
 */
function localWindow( string $date, string $start, string $end, string $timezone ): TimeRange
{
    return new TimeRange(
        CarbonImmutable::parse( $date . ' ' . $start, $timezone ),
        CarbonImmutable::parse( $date . ' ' . $end, $timezone ),
    );
}

/**
 * Renders each slot's start as a local clock face.
 *
 * @since 1.0.0
 *
 * @param  array<int, Slot>  $slots  The slots to render.
 * @param  string  $timezone  The zone to read them in.
 *
 * @return array<int, string> The starts, as `H:i`.
 */
function localStarts( array $slots, string $timezone ): array
{
    return array_map(
        static fn ( Slot $slot ): string => $slot->period->start->setTimezone( $timezone )->format( 'H:i' ),
        $slots,
    );
}

/**
 * Renders each slot's start as a UTC instant.
 *
 * @since 1.0.0
 *
 * @param  array<int, Slot>  $slots  The slots to render.
 *
 * @return array<int, string> The starts, as `Y-m-d H:i` in UTC.
 */
function utcStarts( array $slots ): array
{
    return array_map(
        static fn ( Slot $slot ): string => $slot->period->start->utc()->format( 'Y-m-d H:i' ),
        $slots,
    );
}

/**
 * Attaches a provider to a service and gives them a weekly window.
 *
 * @since 1.0.0
 *
 * @param  Service  $service  The service to attach to.
 * @param  ServiceProvider  $provider  The provider being attached.
 * @param  array<int, int>  $daysOfWeek  The Sunday-indexed weekdays to work.
 * @param  string  $start  The opening clock face, as `H:i`.
 * @param  string  $end  The closing clock face, as `H:i`.
 *
 * @return ServiceProvider The provider, for chaining.
 */
function bookingsSchedule(
    Service $service,
    ServiceProvider $provider,
    array $daysOfWeek = [ 1 ],
    string $start = '09:00',
    string $end = '17:00',
): ServiceProvider {
    $service->providers()->syncWithoutDetaching( [ $provider->getKey() => [] ] );

    foreach ( $daysOfWeek as $dayOfWeek ) {
        AvailabilitySchedule::factory()
            ->for( $provider, 'provider' )
            ->onDayOfWeek( $dayOfWeek )
            ->between( $start, $end )
            ->create();
    }

    return $provider;
}

/**
 * Builds a service and a provider who works one window every day of the week.
 *
 * The shape the timezone cases want: no weekday to reason about, so the only
 * thing separating one assertion from another is the date and the zone.
 *
 * @since 1.0.0
 *
 * @param  string  $timezone  The provider's zone.
 * @param  string  $start  The opening clock face, as `H:i`.
 * @param  string  $end  The closing clock face, as `H:i`.
 *
 * @return array{0: Service, 1: ServiceProvider} The service and its provider.
 */
function serviceWorkedEveryDayIn( string $timezone, string $start = '09:00', string $end = '17:00' ): array
{
    /** @var Service $service */
    $service = Service::factory()->create( [
        'duration'      => 60,
        'buffer_before' => 0,
        'buffer_after'  => 0,
    ] );

    $provider = bookingsSchedule(
        $service,
        ServiceProvider::factory()->inTimezone( $timezone )->create(),
        [ 0, 1, 2, 3, 4, 5, 6 ],
        $start,
        $end,
    );

    return [ $service, $provider ];
}
