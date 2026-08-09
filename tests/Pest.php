<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\AvailabilityOverrideType;
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
use ArtisanPackUI\Bookings\Support\TimeRange;
use ArtisanPackUI\Core\Contracts\SiteResolver;
use ArtisanPackUI\Core\MultiTenancy\SiteContext;
use Carbon\CarbonImmutable;
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
