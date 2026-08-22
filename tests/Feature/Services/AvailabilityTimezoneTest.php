<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/*
 * Availability is authored as wall-clock time in a provider's own zone, and the
 * whole design exists for the handful of days a year that reading stops being a
 * formality. Each case below is one of them.
 */

beforeEach( function (): void {
    config()->set( 'artisanpack.bookings.slot_interval', 60 );

    // Deliberately not UTC. A zone-handling bug that happens to agree with the
    // application timezone is a bug that ships.
    config()->set( 'app.timezone', 'America/New_York' );
} );

it( 'keeps the clock face when the clocks go forward', function (): void {
    // America/Chicago moves to CDT at 02:00 on 2026-03-08. Nine o'clock stays
    // nine o'clock; it is the UTC instant behind it that moves.
    [ $service, $provider ] = serviceWorkedEveryDayIn( 'America/Chicago' );

    $before = availability()->resolve( $service, $provider, localDayWindow( '2026-03-07', 'America/Chicago' ) );
    $after  = availability()->resolve( $service, $provider, localDayWindow( '2026-03-09', 'America/Chicago' ) );

    expect( localStarts( $before, 'America/Chicago' )[ 0 ] )->toBe( '09:00' )
        ->and( localStarts( $after, 'America/Chicago' )[ 0 ] )->toBe( '09:00' )
        ->and( utcStarts( $before )[ 0 ] )->toBe( '2026-03-07 15:00' )
        ->and( utcStarts( $after )[ 0 ] )->toBe( '2026-03-09 14:00' )
        ->and( $before )->toHaveCount( 8 )
        ->and( $after )->toHaveCount( 8 );
} );

it( 'offers no slot at a local time the clocks skipped', function (): void {
    // 02:00–02:59 does not exist in Chicago on 2026-03-08. Carbon will happily
    // hand back 03:00 for it, which would put a slot an hour from where the
    // schedule says it is — so the hour is dropped instead.
    [ $service, $provider ] = serviceWorkedEveryDayIn( 'America/Chicago', '01:00', '05:00' );

    $slots = availability()->resolve( $service, $provider, localDayWindow( '2026-03-08', 'America/Chicago' ) );

    expect( localStarts( $slots, 'America/Chicago' ) )->toBe( [ '01:00', '03:00', '04:00' ] )
        ->and( utcStarts( $slots ) )->toBe( [
            '2026-03-08 07:00',
            '2026-03-08 08:00',
            '2026-03-08 09:00',
        ] );
} );

it( 'clamps a window that opens inside the spring-forward gap rather than failing the day', function (): void {
    // A schedule authored to open at 02:30 names a local time that does not exist
    // on 2026-03-08 — the clocks jump 02:00 to 03:00. Resolving the day used to
    // throw and 500 the whole public availability path once a year; now the
    // window clamps to the instant the clock jumped to and the day still resolves.
    [ $service, $provider ] = serviceWorkedEveryDayIn( 'America/Chicago', '02:30', '06:00' );

    $slots = availability()->resolve( $service, $provider, localDayWindow( '2026-03-08', 'America/Chicago' ) );

    expect( $slots )->not->toBeEmpty();

    foreach ( localStarts( $slots, 'America/Chicago' ) as $start ) {
        expect( $start >= '03:00' )->toBeTrue();
    }
} );

it( 'still fails the day on a genuinely unreadable schedule time', function (): void {
    // The clamp is for the one local hour that does not exist, not for a corrupt
    // value: a stored time that is not a time of day must still surface rather
    // than be silently swallowed into an empty day.
    [ $service, $provider ] = serviceWorkedEveryDayIn( 'America/Chicago', '09:00', '17:00' );

    DB::table( 'availability_schedules' )
        ->where( 'provider_id', $provider->getKey() )
        ->update( [ 'start_time_local' => '38:00:00' ] );

    expect( fn (): array => availability()->resolve(
        $service,
        $provider,
        localDayWindow( '2026-09-01', 'America/Chicago' ),
    ) )->toThrow( RuntimeException::class );
} );

it( 'offers both occurrences of a repeated local hour when the clocks go back', function (): void {
    // 01:00–01:59 happens twice in Chicago on 2026-11-01, and both occurrences
    // are real, elapsed, bookable time. Walking clock faces would generate only
    // the first and leave the second — a genuine hour of availability —
    // unbookable once a year. Two 01:00 slots come back, at the two distinct
    // instants the hour is, one an hour of real time after the other.
    [ $service, $provider ] = serviceWorkedEveryDayIn( 'America/Chicago', '01:00', '03:00' );

    $slots = availability()->resolve( $service, $provider, localDayWindow( '2026-11-01', 'America/Chicago' ) );

    expect( localStarts( $slots, 'America/Chicago' ) )->toBe( [ '01:00', '01:00', '02:00' ] )
        ->and( utcStarts( $slots ) )->toBe( [
            '2026-11-01 06:00',
            '2026-11-01 07:00',
            '2026-11-01 08:00',
        ] );
} );

it( 'counts the fall-back day as twenty-five hours of candidates', function (): void {
    // The reproduction from the report: a provider open 00:00–06:00 on the US
    // fall-back day. Walking clock faces returned six slots because the repeated
    // 01:00 hour was generated once; walking elapsed time returns seven, with the
    // repeated hour offered at both of its distinct instants.
    [ $service, $provider ] = serviceWorkedEveryDayIn( 'America/Chicago', '00:00', '06:00' );

    $slots = availability()->resolve( $service, $provider, localDayWindow( '2026-11-01', 'America/Chicago' ) );

    expect( $slots )->toHaveCount( 7 )
        ->and( localStarts( $slots, 'America/Chicago' ) )->toBe( [
            '00:00',
            '01:00',
            '01:00',
            '02:00',
            '03:00',
            '04:00',
            '05:00',
        ] )
        ->and( utcStarts( $slots ) )->toBe( [
            '2026-11-01 05:00',
            '2026-11-01 06:00',
            '2026-11-01 07:00',
            '2026-11-01 08:00',
            '2026-11-01 09:00',
            '2026-11-01 10:00',
            '2026-11-01 11:00',
        ] );
} );

it( 'moves the other way in the southern hemisphere', function (): void {
    // Sydney does the same two changeovers in the opposite order: April takes an
    // hour off the offset where March adds one in Chicago. A resolver that had
    // hard-coded a direction passes the northern cases and fails here.
    [ $service, $provider ] = serviceWorkedEveryDayIn( 'Australia/Sydney' );

    $daylight = availability()->resolve( $service, $provider, localDayWindow( '2026-04-03', 'Australia/Sydney' ) );
    $standard = availability()->resolve( $service, $provider, localDayWindow( '2026-04-06', 'Australia/Sydney' ) );

    expect( localStarts( $daylight, 'Australia/Sydney' )[ 0 ] )->toBe( '09:00' )
        ->and( localStarts( $standard, 'Australia/Sydney' )[ 0 ] )->toBe( '09:00' )
        ->and( utcStarts( $daylight )[ 0 ] )->toBe( '2026-04-02 22:00' )
        ->and( utcStarts( $standard )[ 0 ] )->toBe( '2026-04-05 23:00' );
} );

it( 'leaves a zone that does not observe daylight saving alone', function (): void {
    // Phoenix sits out the changeover its neighbours make. The same two dates
    // that move Chicago's instants must leave these untouched.
    [ $service, $provider ] = serviceWorkedEveryDayIn( 'America/Phoenix' );

    $before = availability()->resolve( $service, $provider, localDayWindow( '2026-03-07', 'America/Phoenix' ) );
    $after  = availability()->resolve( $service, $provider, localDayWindow( '2026-03-09', 'America/Phoenix' ) );

    expect( utcStarts( $before )[ 0 ] )->toBe( '2026-03-07 16:00' )
        ->and( utcStarts( $after )[ 0 ] )->toBe( '2026-03-09 16:00' )
        ->and( $before )->toHaveCount( 8 )
        ->and( $after )->toHaveCount( 8 );
} );

it( 'survives an offset that is not a whole number of hours', function (): void {
    // Chatham is UTC+12:45, and +13:45 in its summer. Anything that rounded an
    // offset to hours, or normalised through a date rather than an instant,
    // loses the quarter hour here and nowhere else.
    [ $service, $provider ] = serviceWorkedEveryDayIn( 'Pacific/Chatham' );

    $summer = availability()->resolve( $service, $provider, localDayWindow( '2026-12-07', 'Pacific/Chatham' ) );
    $winter = availability()->resolve( $service, $provider, localDayWindow( '2026-06-01', 'Pacific/Chatham' ) );

    expect( localStarts( $summer, 'Pacific/Chatham' )[ 0 ] )->toBe( '09:00' )
        ->and( localStarts( $winter, 'Pacific/Chatham' )[ 0 ] )->toBe( '09:00' )
        ->and( utcStarts( $summer )[ 0 ] )->toBe( '2026-12-06 19:15' )
        ->and( utcStarts( $winter )[ 0 ] )->toBe( '2026-05-31 20:15' );
} );

it( 'resolves each provider against their own zone', function (): void {
    // Two providers, one service, one window. The slots are the same instants
    // seen from different clock faces, which is the case a resolver that reached
    // for the application timezone would get wrong for at least one of them.
    $service = Service::factory()->create( [
        'duration'      => 60,
        'buffer_before' => 0,
        'buffer_after'  => 0,
    ] );

    $chicago = bookingsSchedule(
        $service,
        ServiceProvider::factory()->inTimezone( 'America/Chicago' )->create(),
        [ 1 ],
        '09:00',
        '11:00',
    );
    $london = bookingsSchedule(
        $service,
        ServiceProvider::factory()->inTimezone( 'Europe/London' )->create(),
        [ 1 ],
        '09:00',
        '11:00',
    );

    $byProvider = availability()->resolveByProvider(
        $service,
        localDayWindow( '2026-06-01', 'Europe/London' ),
    );

    expect( utcStarts( $byProvider[ $london->id ] ) )->toBe( [ '2026-06-01 08:00', '2026-06-01 09:00' ] )
        ->and( utcStarts( $byProvider[ $chicago->id ] ) )->toBe( [ '2026-06-01 14:00', '2026-06-01 15:00' ] );
} );
