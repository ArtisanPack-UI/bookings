<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\CalendarWatchChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    Carbon::setTestNow( '2026-06-01 12:00:00' );
} );

afterEach( function (): void {
    Carbon::setTestNow();
} );

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
