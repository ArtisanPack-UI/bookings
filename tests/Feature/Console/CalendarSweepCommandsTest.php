<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Contracts\TwoWayCalendarDriver;
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\CalendarWatchChannel;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    Carbon::setTestNow( '2026-06-01 12:00:00' );
} );

afterEach( function (): void {
    Carbon::setTestNow();

    removeAllFilters( 'ap.bookings.calendarSync.providers' );
    removeAllFilters( 'ap.bookings.calendarSync.renewChannels' );
} );

/**
 * Registers a driver so the registry resolves it, replacing any under its key.
 */
function registerSweepDriver( TwoWayCalendarDriver $driver ): void
{
    addFilter(
        'ap.bookings.calendarSync.providers',
        static fn ( array $drivers ): array => array_merge( $drivers, [ $driver->driver()->value => $driver ] ),
    );
}

/**
 * Builds a two-way driver that records the connections it is asked to read back.
 */
function refreshingCalendarDriver( ?Throwable $failWith = null ): TwoWayCalendarDriver
{
    return new class( $failWith ) implements TwoWayCalendarDriver {
        public array $refreshed = [];

        public function __construct( public ?Throwable $failWith = null )
        {
        }

        public function driver(): CalendarDriver
        {
            return CalendarDriver::Google;
        }

        public function createEvent( CalendarConnection $connection, Booking $booking ): string
        {
            return 'evt-created';
        }

        public function updateEvent( CalendarConnection $connection, Booking $booking, string $externalEventId ): string
        {
            return $externalEventId;
        }

        public function deleteEvent( CalendarConnection $connection, string $externalEventId ): void
        {
        }

        public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array
        {
            return [];
        }

        public function incrementalSync( CalendarConnection $connection ): void
        {
            if ( null !== $this->failWith ) {
                throw $this->failWith;
            }

            $this->refreshed[] = $connection->getKey();
        }

        public function subscribeToChanges( CalendarConnection $connection, string $callbackUrl ): CalendarWatchChannel
        {
            return new CalendarWatchChannel();
        }

        public function renewSubscription( CalendarWatchChannel $channel, string $callbackUrl ): CalendarWatchChannel
        {
            return $channel;
        }
    };
}

describe( 'bookings:calendar-refresh', function (): void {
    it( 'says so when there is nothing two-way to refresh', function (): void {
        CalendarConnection::factory()->create();

        $this->artisan( 'bookings:calendar-refresh' )
            ->expectsOutputToContain( 'No two-way calendar connections to refresh.' )
            ->assertSuccessful();
    } );

    it( 'reports the connections it found and that it cannot sync them', function (): void {
        // Loudly, and on every run. A connection whose calendar has stopped
        // being read still shows availability, so the failure is invisible from
        // the outside — customers keep booking slots the provider is busy for.
        CalendarConnection::factory()->twoWay()->count( 2 )->create();

        $this->artisan( 'bookings:calendar-refresh' )
            ->expectsOutputToContain( '2 two-way calendar connection(s) are due a refresh.' )
            ->expectsOutputToContain( 'No calendar sync driver is installed' )
            ->assertSuccessful();
    } );

    it( 'ignores a disabled connection', function (): void {
        CalendarConnection::factory()->twoWay()->create()->disable( 'Token revoked.' );

        $this->artisan( 'bookings:calendar-refresh' )
            ->expectsOutputToContain( 'No two-way calendar connections to refresh.' )
            ->assertSuccessful();
    } );

    it( 'reads back every due connection through its registered driver', function (): void {
        $driver = refreshingCalendarDriver();
        registerSweepDriver( $driver );

        $connections = CalendarConnection::factory()->twoWay()->count( 2 )->create( [
            'driver' => CalendarDriver::Google,
        ] );

        $this->artisan( 'bookings:calendar-refresh' )
            ->expectsOutputToContain( 'Refreshed 2 of 2 two-way calendar connection(s)' )
            ->assertSuccessful();

        expect( $driver->refreshed )->toEqualCanonicalizing( $connections->pluck( 'id' )->all() );

        foreach ( $connections as $connection ) {
            expect( $connection->fresh()->last_sync_at )->not->toBeNull();
        }
    } );

    it( 'records a failure on the connection and carries on with the rest', function (): void {
        registerSweepDriver( refreshingCalendarDriver( new RuntimeException( 'Google is unreachable.' ) ) );

        $connection = CalendarConnection::factory()->twoWay()->create( [ 'driver' => CalendarDriver::Google ] );

        $this->artisan( 'bookings:calendar-refresh' )
            ->expectsOutputToContain( 'Calendar connection ' . $connection->getKey() . ' could not be refreshed.' )
            ->assertSuccessful();

        expect( $connection->fresh()->last_sync_error )->toBe( 'Google is unreachable.' );
    } );

    it( 'still reports the not-installed warning when no due connection has a driver', function (): void {
        // A read-only iCal driver is not a two-way driver: a Google connection it
        // cannot serve is still unsyncable, and the operator-facing warning must
        // still fire rather than a false "refreshed" line.
        CalendarConnection::factory()->twoWay()->count( 2 )->create( [ 'driver' => CalendarDriver::Google ] );

        $this->artisan( 'bookings:calendar-refresh' )
            ->expectsOutputToContain( '2 two-way calendar connection(s) are due a refresh.' )
            ->expectsOutputToContain( 'No calendar sync driver is installed' )
            ->assertSuccessful();
    } );
} );

describe( 'bookings:calendar-watch-renew', function (): void {
    it( 'says so when no registration is close to lapsing', function (): void {
        CalendarWatchChannel::factory()->create( [ 'expires_at' => Carbon::parse( '+2 days' ) ] );

        $this->artisan( 'bookings:calendar-watch-renew' )
            ->expectsOutputToContain( 'No calendar watch channels are due for renewal.' )
            ->assertSuccessful();
    } );

    it( 'ignores registrations on a connection that has been disabled', function (): void {
        // `disable()` clears the sync token and leaves the watch rows behind, so
        // without the join a revoked-token connection reports its channels as
        // due every hour forever — diluting the one warning this command has.
        $connection = CalendarConnection::factory()->twoWay()->create();

        CalendarWatchChannel::factory()->for( $connection, 'connection' )->create( [
            'expires_at' => Carbon::parse( '-1 day' ),
        ] );

        $connection->disable( 'Token revoked.' );

        $this->artisan( 'bookings:calendar-watch-renew' )
            ->expectsOutputToContain( 'No calendar watch channels are due for renewal.' )
            ->assertSuccessful();
    } );

    it( 'picks up registrations that are lapsing and ones that already have', function (): void {
        // An expired channel is the most urgent row in the table, not one to
        // skip past: the calendar behind it has already gone quiet.
        CalendarWatchChannel::factory()->create( [ 'expires_at' => Carbon::parse( '-1 day' ) ] );
        CalendarWatchChannel::factory()->create( [ 'expires_at' => Carbon::parse( '+30 minutes' ) ] );
        CalendarWatchChannel::factory()->create( [ 'expires_at' => Carbon::parse( '+2 days' ) ] );

        $this->artisan( 'bookings:calendar-watch-renew' )
            ->expectsOutputToContain( '2 calendar watch channel(s) are due for renewal.' )
            ->expectsOutputToContain( 'No calendar sync driver is installed' )
            ->assertSuccessful();
    } );

    it( 'defers the renewal to a subscriber and reports what it renewed', function (): void {
        // The push side of two-way sync ships in the driver package that owns the
        // callback URL. It subscribes to the renewChannels filter, renews the due
        // channels, and returns the count; the sweep reports that rather than the
        // not-installed warning.
        CalendarWatchChannel::factory()->create( [ 'expires_at' => Carbon::parse( '-1 day' ) ] );
        CalendarWatchChannel::factory()->create( [ 'expires_at' => Carbon::parse( '+30 minutes' ) ] );

        addFilter(
            'ap.bookings.calendarSync.renewChannels',
            static fn ( int $renewed, $due ): int => $renewed + $due->count(),
        );

        $this->artisan( 'bookings:calendar-watch-renew' )
            ->expectsOutputToContain( 'Renewed 2 of 2 calendar watch channel(s).' )
            ->assertSuccessful();
    } );
} );

describe( 'bookings:calendar-apple-poll', function (): void {
    it( 'says so when no Apple calendar is connected two-way', function (): void {
        CalendarConnection::factory()->twoWay()->create( [ 'driver' => CalendarDriver::Google ] );

        $this->artisan( 'bookings:calendar-apple-poll' )
            ->expectsOutputToContain( 'No two-way Apple calendar connections to poll.' )
            ->assertSuccessful();
    } );

    it( 'reports only the Apple connections', function (): void {
        CalendarConnection::factory()->twoWay()->create( [ 'driver' => CalendarDriver::Apple ] );
        CalendarConnection::factory()->twoWay()->create( [ 'driver' => CalendarDriver::Microsoft ] );

        $this->artisan( 'bookings:calendar-apple-poll' )
            ->expectsOutputToContain( '1 Apple calendar connection(s) are due a poll.' )
            ->assertSuccessful();
    } );
} );
