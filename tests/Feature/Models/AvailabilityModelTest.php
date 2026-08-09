<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\AvailabilityOverrideType;
use ArtisanPackUI\Bookings\Models\AvailabilityOverride;
use ArtisanPackUI\Bookings\Models\AvailabilitySchedule;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'the availability schedule factory', function (): void {
    it( 'produces a believable weekday schedule', function (): void {
        $schedule = AvailabilitySchedule::factory()->create();

        expect( $schedule->exists )->toBeTrue()
            ->and( $schedule->start_time_local )->toBe( '09:00:00' )
            ->and( $schedule->end_time_local )->toBe( '17:00:00' )
            ->and( $schedule->is_available )->toBeTrue()
            ->and( $schedule->day_of_week )->toBeGreaterThanOrEqual( 1 )
            ->and( $schedule->day_of_week )->toBeLessThanOrEqual( 5 );
    } );

    it( 'gives a provider a Monday-to-Friday nine-to-five', function (): void {
        $provider = ServiceProvider::factory()->withWeekdaySchedule()->create();

        $schedules = $provider->availabilitySchedules()->orderBy( 'day_of_week' )->get();

        expect( $schedules )->toHaveCount( 5 )
            ->and( $schedules->pluck( 'day_of_week' )->all() )->toBe( [ 1, 2, 3, 4, 5 ] )
            ->and( $schedules->pluck( 'start_time_local' )->unique()->all() )->toBe( [ '09:00:00' ] );
    } );

    it( 'builds a custom window', function (): void {
        $schedule = AvailabilitySchedule::factory()->between( '12:00', '16:30' )->create();

        expect( $schedule->start_time_local )->toBe( '12:00:00' )
            ->and( $schedule->end_time_local )->toBe( '16:30:00' );
    } );
} );

describe( 'wall-clock times', function (): void {
    it( 'normalises whatever it is given to H:i:s', function (): void {
        $schedule = AvailabilitySchedule::factory()->create( [
            'start_time_local' => '9:05',
            'end_time_local'   => '17:30:45',
        ] );

        expect( $schedule->start_time_local )->toBe( '09:05:00' )
            ->and( $schedule->end_time_local )->toBe( '17:30:45' )
            ->and( $schedule->fresh()->start_time_local )->toBe( '09:05:00' );
    } );

    it( 'refuses a value that is not a time', function (): void {
        expect( fn () => AvailabilitySchedule::factory()->make( [ 'start_time_local' => 'half nine' ] ) )
            ->toThrow( InvalidArgumentException::class );
    } );

    it( 'refuses a time that does not exist', function (): void {
        expect( fn () => AvailabilitySchedule::factory()->make( [ 'start_time_local' => '25:00' ] ) )
            ->toThrow( InvalidArgumentException::class );
    } );

    it( 'still loads a row holding a time it cannot read', function (): void {
        // Refusing the write is right; refusing the read is not. A row seeded by
        // an import — or a MySQL TIME holding one of the out-of-range values that
        // column allows — has to stay loadable, or the screen somebody would fix
        // it on goes down with it and there is no way to reach the row at all.
        $schedule = AvailabilitySchedule::factory()->create();

        DB::table( 'availability_schedules' )
            ->where( 'id', $schedule->id )
            ->update( [ 'start_time_local' => '38:00:00' ] );

        expect( $schedule->fresh()->start_time_local )->toBe( '38:00:00' )
            ->and( $schedule->fresh()->end_time_local )->toBe( '17:00:00' );
    } );

    it( 'refuses to compose an instant out of a time it cannot read', function (): void {
        // Where the leniency has to stop. Carbon does not object to 38:00:00 —
        // it rolls into the next day and reports a window on the wrong date,
        // which is worse than either loading or failing.
        $schedule = AvailabilitySchedule::factory()->create();

        DB::table( 'availability_schedules' )
            ->where( 'id', $schedule->id )
            ->update( [ 'start_time_local' => '38:00:00' ] );

        $corrupt = $schedule->fresh();

        expect( fn () => $corrupt->startsAtOn( '2026-09-01' ) )->toThrow( RuntimeException::class )
            ->and( $corrupt->endsAtOn( '2026-09-01' )->format( 'H:i' ) )->toBe( '17:00' );
    } );

    it( 'keeps a nine o clock start at nine o clock across a spring forward', function (): void {
        // The whole reason these columns are wall-clock and not UTC. The US
        // clocks go forward on 8 March 2026: a schedule normalised to 15:00 UTC
        // would quietly become an 08:00 local start the next morning.
        $provider = ServiceProvider::factory()->inTimezone( 'America/Chicago' )->create();
        $schedule = AvailabilitySchedule::factory()->for( $provider, 'provider' )->between( '09:00', '17:00' )->create();

        $before = $schedule->startsAtOn( '2026-03-07' );
        $after  = $schedule->startsAtOn( '2026-03-09' );

        expect( $before->format( 'H:i' ) )->toBe( '09:00' )
            ->and( $after->format( 'H:i' ) )->toBe( '09:00' )
            ->and( $before->utcOffset() )->toBe( -360 )
            ->and( $after->utcOffset() )->toBe( -300 )
            ->and( $before->copy()->utc()->format( 'H:i' ) )->toBe( '15:00' )
            ->and( $after->copy()->utc()->format( 'H:i' ) )->toBe( '14:00' );
    } );

    it( 'closes the window on the same local clock face', function (): void {
        $provider = ServiceProvider::factory()->inTimezone( 'Europe/London' )->create();
        $schedule = AvailabilitySchedule::factory()->for( $provider, 'provider' )->between( '09:00', '17:00' )->create();

        // Britain moves on 29 March 2026.
        expect( $schedule->endsAtOn( '2026-03-28' )->format( 'H:i' ) )->toBe( '17:00' )
            ->and( $schedule->endsAtOn( '2026-03-30' )->format( 'H:i' ) )->toBe( '17:00' )
            ->and( $schedule->endsAtOn( '2026-03-28' )->utcOffset() )->toBe( 0 )
            ->and( $schedule->endsAtOn( '2026-03-30' )->utcOffset() )->toBe( 60 );
    } );

    it( 'refuses a local time the clocks skip over', function (): void {
        // 02:30 does not happen in Chicago on 8 March 2026. Carbon does not say
        // so — it hands back 03:30 — which would move the window an hour on the
        // one day of the year this design exists to get right.
        $provider = ServiceProvider::factory()->inTimezone( 'America/Chicago' )->create();
        $schedule = AvailabilitySchedule::factory()
            ->for( $provider, 'provider' )
            ->between( '02:30', '06:00' )
            ->create();

        expect( fn () => $schedule->startsAtOn( '2026-03-08' ) )->toThrow( RuntimeException::class )
            ->and( $schedule->startsAtOn( '2026-03-07' )->format( 'H:i' ) )->toBe( '02:30' )
            ->and( $schedule->startsAtOn( '2026-03-09' )->format( 'H:i' ) )->toBe( '02:30' );
    } );

    it( 'reads the clock face in a zone the caller names instead', function (): void {
        $provider = ServiceProvider::factory()->inTimezone( 'America/Chicago' )->create();
        $schedule = AvailabilitySchedule::factory()->for( $provider, 'provider' )->create();

        expect( $schedule->startsAtOn( '2026-06-01', 'Europe/London' )->timezoneName )->toBe( 'Europe/London' )
            ->and( $schedule->startsAtOn( '2026-06-01', 'Europe/London' )->format( 'H:i' ) )->toBe( '09:00' );
    } );
} );

describe( 'the effective window', function (): void {
    it( 'returns only the rows in force on the day asked about', function (): void {
        $provider = ServiceProvider::factory()->create();

        $always = AvailabilitySchedule::factory()->for( $provider, 'provider' )->nineToFive( 1 )->create();
        $future = AvailabilitySchedule::factory()
            ->for( $provider, 'provider' )
            ->nineToFive( 1 )
            ->between( '10:00', '14:00' )
            ->effective( '2026-09-01' )
            ->create();
        $past   = AvailabilitySchedule::factory()
            ->for( $provider, 'provider' )
            ->nineToFive( 1 )
            ->between( '08:00', '12:00' )
            ->effective( null, '2026-01-31' )
            ->create();

        expect( AvailabilitySchedule::for( $provider, 1, '2026-03-09' )->get()->modelKeys() )
            ->toBe( [ $always->id ] )
            ->and( AvailabilitySchedule::for( $provider, 1, '2026-09-15' )->get()->modelKeys() )
            ->toBe( [ $always->id, $future->id ] )
            ->and( AvailabilitySchedule::for( $provider, 1, '2026-01-05' )->get()->modelKeys() )
            ->toBe( [ $always->id, $past->id ] );
    } );

    it( 'includes a schedule on the first and last day it applies', function (): void {
        // The boundary days are where a bare date comparison goes wrong: a
        // `date` cast is written through the connection's date format, so the
        // stored value carries a midnight time component the comparison has to
        // account for.
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

    it( 'answers the same question about a single row', function (): void {
        $schedule = AvailabilitySchedule::factory()->effective( '2026-09-01', '2026-09-30' )->create();

        expect( $schedule->isEffectiveOn( '2026-09-01' ) )->toBeTrue()
            ->and( $schedule->isEffectiveOn( '2026-09-30' ) )->toBeTrue()
            ->and( $schedule->isEffectiveOn( '2026-08-31' ) )->toBeFalse()
            ->and( $schedule->isEffectiveOn( '2026-10-01' ) )->toBeFalse();
    } );

    it( 'keeps another provider out of the answer', function (): void {
        $provider = ServiceProvider::factory()->create();
        $other    = ServiceProvider::factory()->create();

        AvailabilitySchedule::factory()->for( $provider, 'provider' )->nineToFive( 2 )->create();
        AvailabilitySchedule::factory()->for( $other, 'provider' )->nineToFive( 2 )->create();

        expect( AvailabilitySchedule::for( $provider, 2, '2026-03-10' )->count() )->toBe( 1 );
    } );

    it( 'keeps another weekday out of the answer', function (): void {
        $provider = ServiceProvider::factory()->create();

        AvailabilitySchedule::factory()->for( $provider, 'provider' )->nineToFive( 2 )->create();

        expect( AvailabilitySchedule::for( $provider, 3, '2026-03-11' )->count() )->toBe( 0 );
    } );

    it( 'separates the rows that grant availability from the ones that withhold it', function (): void {
        $provider = ServiceProvider::factory()->create();

        AvailabilitySchedule::factory()->for( $provider, 'provider' )->nineToFive( 4 )->create();
        AvailabilitySchedule::factory()->for( $provider, 'provider' )->onDayOfWeek( 4 )->unavailable()->create();

        expect( AvailabilitySchedule::for( $provider, 4, '2026-03-12' )->count() )->toBe( 2 )
            ->and( AvailabilitySchedule::for( $provider, 4, '2026-03-12' )->available()->count() )->toBe( 1 );
    } );
} );

describe( 'availability overrides', function (): void {
    it( 'builds a day off with no window to describe', function (): void {
        $override = AvailabilityOverride::factory()->create();

        expect( $override->type )->toBe( AvailabilityOverrideType::Unavailable )
            ->and( $override->isUnavailable() )->toBeTrue()
            ->and( $override->start_time_local )->toBeNull()
            ->and( $override->startsAt() )->toBeNull();
    } );

    it( 'builds a custom twelve-to-four window', function (): void {
        $provider = ServiceProvider::factory()->inTimezone( 'America/Chicago' )->create();
        $override = AvailabilityOverride::factory()
            ->for( $provider, 'provider' )
            ->customHours()
            ->onDate( '2026-07-14' )
            ->create();

        expect( $override->isCustomHours() )->toBeTrue()
            ->and( $override->start_time_local )->toBe( '12:00:00' )
            ->and( $override->end_time_local )->toBe( '16:00:00' )
            ->and( $override->startsAt()?->format( 'Y-m-d H:i' ) )->toBe( '2026-07-14 12:00' )
            ->and( $override->startsAt()?->timezoneName )->toBe( 'America/Chicago' )
            ->and( $override->endsAt()?->format( 'H:i' ) )->toBe( '16:00' );
    } );

    it( 'finds a provider exception on a given day', function (): void {
        $provider = ServiceProvider::factory()->create();

        $wanted = AvailabilityOverride::factory()->for( $provider, 'provider' )->onDate( '2026-07-14' )->create();
        AvailabilityOverride::factory()->for( $provider, 'provider' )->onDate( '2026-07-15' )->create();
        AvailabilityOverride::factory()->onDate( '2026-07-14' )->create();

        expect( AvailabilityOverride::for( $provider, '2026-07-14' )->get()->modelKeys() )->toBe( [ $wanted->id ] )
            ->and( AvailabilityOverride::for( $provider, '2026-07-16' )->count() )->toBe( 0 );
    } );

    it( 'reads the override type back as an enum', function (): void {
        $override = AvailabilityOverride::factory()->create( [ 'type' => 'custom_hours' ] );

        expect( $override->fresh()->type )->toBe( AvailabilityOverrideType::CustomHours );
    } );

    it( 'reaches its provider from either direction', function (): void {
        $provider = ServiceProvider::factory()->create();
        AvailabilityOverride::factory()->count( 2 )->for( $provider, 'provider' )->create();

        expect( $provider->availabilityOverrides()->count() )->toBe( 2 )
            ->and( $provider->availabilityOverrides()->first()->provider->is( $provider ) )->toBeTrue();
    } );
} );
