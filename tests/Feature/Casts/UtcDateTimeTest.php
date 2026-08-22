<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Casts\UtcDateTime;
use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/*
 * `start_time` and `end_time` are instants stored in UTC, and must read back as
 * the instant they were written whatever the host application's zone is. The
 * zone is set on PHP rather than only in config here on purpose: Laravel calls
 * `date_default_timezone_set()` once at boot, so a config write after boot
 * leaves the process default at UTC and the whole bug unreproducible — the
 * plain `'datetime'` cast would agree with UTC and the regression would ship.
 */

beforeEach( function (): void {
    $this->priorTimezone = date_default_timezone_get();
} );

afterEach( function (): void {
    // Pest runs every file in one process, so a zone left set by a test here
    // would follow every later test in the suite. Restore whatever was in force
    // before the test rather than assuming the suite started on UTC.
    date_default_timezone_set( $this->priorTimezone );
} );

it( 'reads a stored instant back as the same instant under a non-UTC app timezone', function (): void {
    date_default_timezone_set( 'America/New_York' );

    $booking = Booking::factory()->create( [
        'start_time' => Carbon::parse( '2026-06-01 14:00:00', 'UTC' ),
        'end_time'   => Carbon::parse( '2026-06-01 15:00:00', 'UTC' ),
    ] );

    $fresh = $booking->fresh();

    expect( $fresh->start_time->toIso8601String() )->toBe( '2026-06-01T14:00:00+00:00' )
        ->and( $fresh->end_time->toIso8601String() )->toBe( '2026-06-01T15:00:00+00:00' )
        ->and( $fresh->start_time->getTimezone()->getName() )->toBe( 'UTC' );
} );

it( 'writes the UTC digits to the column whatever zone the value carries', function (): void {
    date_default_timezone_set( 'Asia/Tokyo' );

    $booking = Booking::factory()->create( [
        // 10:00 EDT is 14:00 UTC; the column must hold the UTC clock face.
        'start_time' => Carbon::parse( '2026-06-01 10:00:00', 'America/New_York' ),
        'end_time'   => Carbon::parse( '2026-06-01 11:00:00', 'America/New_York' ),
    ] );

    expect( $booking->fresh()->getRawOriginal( 'start_time' ) )->toBe( '2026-06-01 14:00:00' )
        ->and( $booking->fresh()->getRawOriginal( 'end_time' ) )->toBe( '2026-06-01 15:00:00' );
} );

it( 'renders the start time in the customer zone from the true instant', function (): void {
    date_default_timezone_set( 'Asia/Tokyo' );

    $booking = Booking::factory()->create( [
        'customer_timezone' => 'America/New_York',
        'start_time'        => Carbon::parse( '2026-06-01 14:00:00', 'UTC' ),
        'end_time'          => Carbon::parse( '2026-06-01 15:00:00', 'UTC' ),
    ] );

    expect( $booking->fresh()->startTimeForCustomer()->toIso8601String() )
        ->toBe( '2026-06-01T10:00:00-04:00' );
} );

it( 'passes null through in both directions', function (): void {
    $cast    = new UtcDateTime();
    $booking = new Booking();

    expect( $cast->get( $booking, 'start_time', null, [] ) )->toBeNull()
        ->and( $cast->set( $booking, 'start_time', null, [] ) )->toBeNull();
} );

it( 'converts a Unix timestamp to a UTC instant', function (): void {
    $cast    = new UtcDateTime();
    $booking = new Booking();

    // 2026-06-01T14:00:00Z.
    $result = $cast->get( $booking, 'start_time', 1780322400, [] );

    expect( $result->toIso8601String() )->toBe( '2026-06-01T14:00:00+00:00' );
} );

it( 'stores a zone-aware string at the instant it names', function (): void {
    // A string that carries its own offset keeps that instant: 10:00-04:00 is
    // 14:00 UTC, and the column must hold the UTC clock face regardless of the
    // process default.
    date_default_timezone_set( 'Asia/Tokyo' );

    $cast    = new UtcDateTime();
    $booking = new Booking();

    expect( $cast->set( $booking, 'start_time', '2026-06-01T10:00:00-04:00', [] ) )
        ->toBe( '2026-06-01 14:00:00' );
} );
