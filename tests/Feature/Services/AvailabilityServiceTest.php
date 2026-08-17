<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\AvailabilityOverrideType;
use ArtisanPackUI\Bookings\Enums\CalendarSyncMode;
use ArtisanPackUI\Bookings\Models\AvailabilityOverride;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarBusyBlock;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceBlackoutDate;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

// A Monday, comfortably away from every daylight-saving changeover the
// timezone file has an opinion about — those get their own file.
const AVAILABILITY_MONDAY = '2026-06-01';

beforeEach( function (): void {
    config()->set( 'artisanpack.bookings.slot_interval', 60 );

    $this->timezone = 'America/Chicago';
    $this->service  = Service::factory()->create( [
        'duration'              => 60,
        'buffer_before'         => 0,
        'buffer_after'          => 0,
        'max_bookings_per_slot' => 1,
    ] );
    $this->provider = bookingsSchedule(
        $this->service,
        ServiceProvider::factory()->inTimezone( $this->timezone )->create(),
    );
    $this->window = localDayWindow( AVAILABILITY_MONDAY, $this->timezone );
} );

describe( 'slot generation', function (): void {
    it( 'fills a weekly window at the configured interval', function (): void {
        $slots = availability()->resolve( $this->service, $this->provider, $this->window );

        expect( localStarts( $slots, $this->timezone ) )
            ->toBe( [ '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00' ] )
            ->and( $slots[ 0 ] )->toBeInstanceOf( Slot::class )
            ->and( $slots[ 0 ]->providerId )->toBe( $this->provider->id )
            ->and( $slots[ 0 ]->period->minutes() )->toBe( 60 );
    } );

    it( 'starts slots on the interval grid rather than on the window edge', function (): void {
        // The grid is anchored to local midnight, so a window opening at 09:20
        // offers 09:30 rather than a run of slots at twenty past the hour.
        config()->set( 'artisanpack.bookings.slot_interval', 30 );
        bookingsSchedule( $this->service, $this->provider, [ 2 ], '09:20', '11:00' );

        $slots = availability()->resolve(
            $this->service,
            $this->provider,
            localDayWindow( '2026-06-02', $this->timezone ),
        );

        expect( localStarts( $slots, $this->timezone ) )->toBe( [ '09:30', '10:00' ] );
    } );

    it( 'never offers a slot that would run past the window', function (): void {
        config()->set( 'artisanpack.bookings.slot_interval', 30 );
        $this->service->update( [ 'duration' => 90 ] );

        $slots = availability()->resolve( $this->service, $this->provider, $this->window );

        expect( localStarts( $slots, $this->timezone ) )->toContain( '15:30' )
            ->and( localStarts( $slots, $this->timezone ) )->not->toContain( '16:00' );
    } );

    it( 'clips the answer to the window it was asked about', function (): void {
        $slots = availability()->resolve(
            $this->service,
            $this->provider,
            localWindow( AVAILABILITY_MONDAY, '11:00', '14:00', $this->timezone ),
        );

        expect( localStarts( $slots, $this->timezone ) )->toBe( [ '11:00', '12:00', '13:00' ] );
    } );

    it( 'offers nothing on a weekday the provider does not work', function (): void {
        $slots = availability()->resolve(
            $this->service,
            $this->provider,
            localDayWindow( '2026-06-06', $this->timezone ),
        );

        expect( $slots )->toBe( [] );
    } );

    it( 'ignores a schedule that is not yet in force', function (): void {
        $this->provider->availabilitySchedules()->delete();
        $this->provider->availabilitySchedules()->create( [
            'day_of_week'      => 1,
            'start_time_local' => '09:00',
            'end_time_local'   => '17:00',
            'effective_from'   => '2026-07-01',
            'is_available'     => true,
        ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );
    } );

    it( 'spans more than one day when the window does', function (): void {
        bookingsSchedule( $this->service, $this->provider, [ 2 ], '09:00', '11:00' );

        $window = new TimeRange(
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 15:00', $this->timezone )->utc(),
            CarbonImmutable::parse( '2026-06-02 11:00', $this->timezone ),
        );

        expect( utcStarts( availability()->resolve( $this->service, $this->provider, $window ) ) )->toBe( [
            '2026-06-01 20:00',
            '2026-06-01 21:00',
            '2026-06-02 14:00',
            '2026-06-02 15:00',
        ] );
    } );
} );

describe( 'overrides and blackouts', function (): void {
    it( 'closes the day on an unavailable override', function (): void {
        AvailabilityOverride::factory()
            ->for( $this->provider, 'provider' )
            ->unavailable()
            ->onDate( AVAILABILITY_MONDAY )
            ->create();

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );
    } );

    it( 'replaces the weekly hours with a custom-hours override', function (): void {
        AvailabilityOverride::factory()
            ->for( $this->provider, 'provider' )
            ->customHours( '13:00', '15:00' )
            ->onDate( AVAILABILITY_MONDAY )
            ->create();

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->toBe( [ '13:00', '14:00' ] );
    } );

    it( 'closes the day on a blackout for the service', function (): void {
        ServiceBlackoutDate::factory()->for( $this->service )->create( [
            'starts_on' => AVAILABILITY_MONDAY,
            'ends_on'   => AVAILABILITY_MONDAY,
        ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );
    } );

    it( 'closes the day on a site-wide blackout', function (): void {
        ServiceBlackoutDate::factory()->siteWide()->create( [
            'starts_on' => '2026-05-30',
            'ends_on'   => '2026-06-02',
        ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );
    } );

    it( 'leaves the days either side of a blackout alone', function (): void {
        bookingsSchedule( $this->service, $this->provider, [ 2 ] );

        ServiceBlackoutDate::factory()->for( $this->service )->create( [
            'starts_on' => AVAILABILITY_MONDAY,
            'ends_on'   => AVAILABILITY_MONDAY,
        ] );

        expect( availability()->resolve(
            $this->service,
            $this->provider,
            localDayWindow( '2026-06-02', $this->timezone ),
        ) )->toHaveCount( 8 );
    } );
} );

describe( 'existing bookings', function (): void {
    it( 'removes the slot an active booking holds', function (): void {
        Booking::factory()
            ->for( $this->service )
            ->for( $this->provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->not->toContain( '11:00' )
            ->and( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 7 );
    } );

    it( 'gives the slot back once the booking holding it is cancelled', function (): void {
        Booking::factory()
            ->for( $this->service )
            ->for( $this->provider, 'provider' )
            ->cancelled()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->toContain( '11:00' );
    } );

    it( 'ignores a booking assigned to somebody else', function (): void {
        Booking::factory()
            ->for( $this->service )
            ->for( ServiceProvider::factory()->inTimezone( $this->timezone )->create(), 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->toContain( '11:00' );
    } );

    it( 'clears the buffer a service asks for around a booking', function (): void {
        config()->set( 'artisanpack.bookings.slot_interval', 30 );
        $this->service->update( [ 'duration' => 30, 'buffer_before' => 30, 'buffer_after' => 30 ] );

        Booking::factory()
            ->for( $this->service )
            ->for( $this->provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 12:00', $this->timezone )->utc(), 30 )
            ->create();

        $starts = localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone );

        // The booking runs 12:00–12:30, so the half hour either side of it goes
        // with it — and the slot the buffer clears is free again at 13:00.
        expect( $starts )->not->toContain( '11:30' )
            ->and( $starts )->not->toContain( '12:00' )
            ->and( $starts )->not->toContain( '12:30' )
            ->and( $starts )->toContain( '11:00' )
            ->and( $starts )->toContain( '13:00' );
    } );

    it( 'clears the gap the booking already there asked for', function (): void {
        // The asymmetric case: nothing before, fifteen minutes after. The
        // candidate's own buffer says nothing about the time in front of it, so
        // only the existing booking's claim keeps 12:00 from being offered the
        // instant its appointment ends.
        config()->set( 'artisanpack.bookings.slot_interval', 15 );
        $this->service->update( [ 'duration' => 60, 'buffer_before' => 0, 'buffer_after' => 15 ] );

        Booking::factory()
            ->for( $this->service )
            ->for( $this->provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        $starts = localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone );

        expect( $starts )->toContain( '09:45' )
            ->and( $starts )->not->toContain( '10:00' )
            ->and( $starts )->not->toContain( '11:45' )
            ->and( $starts )->not->toContain( '12:00' )
            ->and( $starts )->toContain( '12:15' );
    } );

    it( 'asks for the larger of the two gaps, not their sum', function (): void {
        // Both sides want fifteen minutes. Adding the claims together would cost
        // half an hour and lose 12:15 for nothing.
        config()->set( 'artisanpack.bookings.slot_interval', 15 );
        $this->service->update( [ 'duration' => 60, 'buffer_before' => 15, 'buffer_after' => 15 ] );

        Booking::factory()
            ->for( $this->service )
            ->for( $this->provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        $starts = localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone );

        expect( $starts )->not->toContain( '12:00' )
            ->and( $starts )->toContain( '12:15' )
            ->and( $starts )->toContain( '09:45' )
            ->and( $starts )->not->toContain( '10:00' );
    } );

    it( 'reads the buffer off the booking\'s own service, not the one being booked', function (): void {
        // A provider's other appointment is a different service with different
        // rules, and it is that service's gap the provider owes.
        config()->set( 'artisanpack.bookings.slot_interval', 15 );
        $this->service->update( [ 'duration' => 60, 'buffer_before' => 0, 'buffer_after' => 0 ] );

        $other = Service::factory()->create( [
            'duration'      => 60,
            'buffer_before' => 0,
            'buffer_after'  => 60,
        ] );

        Booking::factory()
            ->for( $other )
            ->for( $this->provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        $starts = localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone );

        expect( $starts )->not->toContain( '12:00' )
            ->and( $starts )->not->toContain( '12:45' )
            ->and( $starts )->toContain( '13:00' );
    } );

    it( 'still blocks a slot on a service that claims a capacity', function (): void {
        // The database admits one active booking per provider and start time, so
        // a capacity above one cannot be seated by a second booking. Reporting
        // the slot as free would offer something the insert then refuses.
        $this->service->update( [ 'max_bookings_per_slot' => 4 ] );

        Booking::factory()
            ->for( $this->service )
            ->for( $this->provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->not->toContain( '11:00' );
    } );

    it( 'blocks a slot a different service already holds the provider for', function (): void {
        Booking::factory()
            ->for( Service::factory()->create( [ 'duration' => 60 ] ) )
            ->for( $this->provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->not->toContain( '11:00' );
    } );
} );

describe( 'external busy blocks', function (): void {
    it( 'removes the slots a two-way calendar reports as busy', function (): void {
        $connection = CalendarConnection::factory()
            ->for( $this->provider, 'provider' )
            ->twoWay()
            ->create();

        CalendarBusyBlock::factory()->for( $connection, 'connection' )->spanning(
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 13:00', $this->timezone )->utc(),
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 15:00', $this->timezone )->utc(),
        )->create();

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->toBe( [ '09:00', '10:00', '11:00', '12:00', '15:00', '16:00' ] );
    } );

    it( 'ignores busy blocks behind a connection that only pushes', function (): void {
        // Reading busy time back is a power the operator grants per connection.
        // Rows left over from before a mode change must not keep exercising it.
        $connection = CalendarConnection::factory()
            ->for( $this->provider, 'provider' )
            ->create();

        CalendarBusyBlock::factory()->for( $connection, 'connection' )->spanning(
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 13:00', $this->timezone )->utc(),
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 15:00', $this->timezone )->utc(),
        )->create();

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 8 );
    } );

    it( 'leaves a slot that merely abuts a busy block', function (): void {
        // Half-open spans, both sides: a block ending at 13:00 does not take the
        // slot starting there.
        $connection = CalendarConnection::factory()
            ->for( $this->provider, 'provider' )
            ->twoWay()
            ->create();

        CalendarBusyBlock::factory()->for( $connection, 'connection' )->spanning(
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 12:00', $this->timezone )->utc(),
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 13:00', $this->timezone )->utc(),
        )->create();

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->toContain( '13:00' )
            ->and( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->not->toContain( '12:00' );
    } );
} );

describe( 'resolving across providers', function (): void {
    it( 'collapses every provider to the distinct periods somebody is free in', function (): void {
        bookingsSchedule( $this->service, ServiceProvider::factory()->inTimezone( $this->timezone )->create(), [ 1 ], '15:00', '18:00' );

        $slots = availability()->resolve( $this->service, null, $this->window );

        expect( localStarts( $slots, $this->timezone ) )
            ->toBe( [ '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00' ] )
            ->and( $slots[ 0 ]->providerId )->toBeNull();
    } );

    it( 'keeps the assignment when asked for it', function (): void {
        $second = bookingsSchedule(
            $this->service,
            ServiceProvider::factory()->inTimezone( $this->timezone )->create(),
            [ 1 ],
            '15:00',
            '18:00',
        );

        $byProvider = availability()->resolveByProvider( $this->service, $this->window );

        expect( $byProvider )->toHaveKeys( [ $this->provider->id, $second->id ] )
            ->and( localStarts( $byProvider[ $second->id ], $this->timezone ) )
            ->toBe( [ '15:00', '16:00', '17:00' ] );
    } );

    it( 'leaves an inactive provider out of the pool', function (): void {
        bookingsSchedule(
            $this->service,
            ServiceProvider::factory()->inTimezone( $this->timezone )->inactive()->create(),
            [ 1 ],
            '18:00',
            '20:00',
        );

        expect( localStarts( availability()->resolve( $this->service, null, $this->window ), $this->timezone ) )
            ->not->toContain( '18:00' );
    } );

    it( 'falls back to the default provider when nobody is attached', function (): void {
        $service  = Service::factory()->create( [ 'duration' => 60, 'buffer_before' => 0, 'buffer_after' => 0 ] );
        $provider = ServiceProvider::factory()->inTimezone( $this->timezone )->create();
        bookingsSchedule( $service, $provider );
        $service->providers()->detach();
        $service->update( [ 'default_provider_id' => $provider->id ] );

        expect( availability()->resolve( $service, null, $this->window ) )->toHaveCount( 8 );
    } );

    it( 'honours a duration set on the attachment', function (): void {
        config()->set( 'artisanpack.bookings.slot_interval', 30 );
        $this->service->providers()->updateExistingPivot( $this->provider->id, [ 'custom_duration' => 120 ] );

        $slots = availability()->resolve( $this->service, $this->provider, $this->window );

        expect( $slots[ 0 ]->period->minutes() )->toBe( 120 )
            ->and( localStarts( $slots, $this->timezone ) )->toContain( '15:00' )
            ->and( localStarts( $slots, $this->timezone ) )->not->toContain( '15:30' );
    } );
} );

describe( 'caching', function (): void {
    it( 'answers a repeated question without recomputing it', function (): void {
        availability()->resolve( $this->service, $this->provider, $this->window );

        // Written past the models on purpose: the stamps cannot see this, so a
        // second answer that changed would mean nothing was cached at all.
        AvailabilityOverride::query()->insert( [
            'provider_id' => $this->provider->id,
            'date'        => AVAILABILITY_MONDAY . ' 00:00:00',
            'type'        => AvailabilityOverrideType::Unavailable->value,
            'created_at'  => now(),
            'updated_at'  => now(),
        ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 8 );
    } );

    it( 'recomputes once a schedule is written through the model', function (): void {
        availability()->resolve( $this->service, $this->provider, $this->window );

        AvailabilityOverride::factory()
            ->for( $this->provider, 'provider' )
            ->unavailable()
            ->onDate( AVAILABILITY_MONDAY )
            ->create();

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );
    } );

    it( 'recomputes once a booking takes a slot', function (): void {
        availability()->resolve( $this->service, $this->provider, $this->window );

        Booking::factory()
            ->for( $this->service )
            ->for( $this->provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 7 );
    } );

    it( 'recomputes once a blackout closes the service', function (): void {
        availability()->resolve( $this->service, $this->provider, $this->window );

        ServiceBlackoutDate::factory()->for( $this->service )->create( [
            'starts_on' => AVAILABILITY_MONDAY,
            'ends_on'   => AVAILABILITY_MONDAY,
        ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );
    } );

    it( 'recomputes once a calendar reports the provider busy', function (): void {
        $connection = CalendarConnection::factory()
            ->for( $this->provider, 'provider' )
            ->twoWay()
            ->create();

        availability()->resolve( $this->service, $this->provider, $this->window );

        CalendarBusyBlock::factory()->for( $connection, 'connection' )->spanning(
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 13:00', $this->timezone )->utc(),
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 15:00', $this->timezone )->utc(),
        )->create();

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 6 );
    } );

    it( 'computes every time when the cache is switched off', function (): void {
        config()->set( 'artisanpack.bookings.availability_cache.enabled', false );

        availability()->resolve( $this->service, $this->provider, $this->window );

        AvailabilityOverride::query()->insert( [
            'provider_id' => $this->provider->id,
            'date'        => AVAILABILITY_MONDAY . ' 00:00:00',
            'type'        => AvailabilityOverrideType::Unavailable->value,
            'created_at'  => now(),
            'updated_at'  => now(),
        ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );
    } );

    it( 'keeps one site\'s answer out of another site\'s', function (): void {
        // A blackout belongs to the site that wrote it, so the same service and
        // provider are genuinely open on one site and closed on the other. The
        // cache has to be able to hold both answers at once.
        scopeToSite( 1 );

        $service  = Service::factory()->create( [ 'duration' => 60, 'buffer_before' => 0, 'buffer_after' => 0 ] );
        $provider = ServiceProvider::factory()->inTimezone( $this->timezone )->create();
        bookingsSchedule( $service, $provider );

        expect( availability()->resolve( $service, $provider, $this->window ) )->toHaveCount( 8 );

        scopeToSite( 2 );

        ServiceBlackoutDate::factory()->for( $service )->create( [
            'starts_on' => AVAILABILITY_MONDAY,
            'ends_on'   => AVAILABILITY_MONDAY,
        ] );

        expect( availability()->resolve( $service, $provider, $this->window ) )->toBe( [] );

        scopeToSite( 1 );

        expect( availability()->resolve( $service, $provider, $this->window ) )->toHaveCount( 8 );
    } );
} );

describe( 'tenant isolation', function (): void {
    it( 'keeps sites apart whatever shape their identifiers are', function (): void {
        // Site identifiers are only typed int|string, so a tenant may well be
        // keyed by a slug rather than a number. Two of them that differ only in
        // punctuation still have to get their own answer.
        $service  = Service::factory()->create( [ 'duration' => 60, 'buffer_before' => 0, 'buffer_after' => 0 ] );
        $provider = ServiceProvider::factory()->inTimezone( $this->timezone )->create();
        bookingsSchedule( $service, $provider );

        scopeToSite( '1.' . $service->id );

        expect( availability()->resolve( $service, $provider, $this->window ) )->toHaveCount( 8 );

        ServiceBlackoutDate::factory()->for( $service )->create( [
            'starts_on' => AVAILABILITY_MONDAY,
            'ends_on'   => AVAILABILITY_MONDAY,
        ] );

        expect( availability()->resolve( $service, $provider, $this->window ) )->toBe( [] );

        // A different site whose identifier only differs in where the dots fall.
        // It has no blackout of its own, so it must still see a full day.
        scopeToSite( '1' );

        expect( availability()->resolve( $service, $provider, $this->window ) )->toHaveCount( 8 );
    } );
} );

describe( 'malformed rows', function (): void {
    it( 'does not fall back to the weekly hours behind a broken override', function (): void {
        // A custom-hours override missing a bound says the day is not the usual
        // one. Working the provider nine-to-five because the replacement could
        // not be read is the one answer that is certainly wrong.
        AvailabilityOverride::factory()
            ->for( $this->provider, 'provider' )
            ->customHours()
            ->onDate( AVAILABILITY_MONDAY )
            ->create( [ 'end_time_local' => null ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );
    } );

    it( 'ignores a schedule that does not end after it starts', function (): void {
        $this->provider->availabilitySchedules()->delete();
        $this->provider->availabilitySchedules()->create( [
            'day_of_week'      => 1,
            'start_time_local' => '17:00',
            'end_time_local'   => '09:00',
            'is_available'     => true,
        ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );
    } );
} );

describe( 'cache invalidation on rows that move', function (): void {
    it( 'frees the slot on the provider a booking was moved away from', function (): void {
        $second = bookingsSchedule(
            $this->service,
            ServiceProvider::factory()->inTimezone( $this->timezone )->create(),
        );

        $booking = Booking::factory()
            ->for( $this->service )
            ->for( $this->provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 7 )
            ->and( availability()->resolve( $this->service, $second, $this->window ) )->toHaveCount( 8 );

        $booking->update( [ 'provider_id' => $second->id ] );

        // The provider it left is the one nothing on the row names any more, and
        // the one whose slot has just come back.
        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 8 )
            ->and( availability()->resolve( $this->service, $second, $this->window ) )->toHaveCount( 7 );
    } );

    it( 'reopens the service a blackout was moved away from', function (): void {
        $other = Service::factory()->create( [ 'duration' => 60, 'buffer_before' => 0, 'buffer_after' => 0 ] );
        bookingsSchedule( $other, $this->provider );

        $blackout = ServiceBlackoutDate::factory()->for( $this->service )->create( [
            'starts_on' => AVAILABILITY_MONDAY,
            'ends_on'   => AVAILABILITY_MONDAY,
        ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );

        $blackout->update( [ 'service_id' => $other->id ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 8 )
            ->and( availability()->resolve( $other, $this->provider, $this->window ) )->toBe( [] );
    } );

    it( 'reopens every service when a site-wide blackout stops being site-wide', function (): void {
        $blackout = ServiceBlackoutDate::factory()->siteWide()->create( [
            'starts_on' => AVAILABILITY_MONDAY,
            'ends_on'   => AVAILABILITY_MONDAY,
        ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );

        // Narrowed to a service that is not this one, so this one reopens — and
        // nothing on the row names it any more.
        $blackout->update( [
            'service_id' => Service::factory()->create()->id,
        ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 8 );
    } );

    it( 'recomputes when the service itself is reshaped', function (): void {
        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 8 );

        $this->service->update( [ 'duration' => 120 ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 7 );
    } );

    it( 'recomputes when the provider moves timezone', function (): void {
        expect( utcStarts( availability()->resolve( $this->service, $this->provider, $this->window ) )[ 0 ] )
            ->toBe( '2026-06-01 14:00' );

        $this->provider->update( [ 'timezone' => 'America/New_York' ] );

        // Nine o'clock still, in a zone that is an hour further east.
        expect( utcStarts( availability()->resolve(
            $this->service,
            $this->provider,
            localDayWindow( AVAILABILITY_MONDAY, 'America/New_York' ),
        ) )[ 0 ] )->toBe( '2026-06-01 13:00' );
    } );

    it( 'recomputes when a calendar starts reading busy time back', function (): void {
        $connection = CalendarConnection::factory()
            ->for( $this->provider, 'provider' )
            ->create();

        CalendarBusyBlock::factory()->for( $connection, 'connection' )->spanning(
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 13:00', $this->timezone )->utc(),
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 15:00', $this->timezone )->utc(),
        )->create();

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 8 );

        $connection->update( [ 'sync_mode' => CalendarSyncMode::TwoWay ] );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 6 );
    } );
} );

describe( 'clashes reaching in from outside the day', function (): void {
    it( 'clears a gap a booking on the next day reaches back for', function (): void {
        // The candidate service asks for nothing, so nothing widens the window
        // this day is computed against — and the appointment that wants the gap
        // is on the other side of midnight.
        config()->set( 'artisanpack.bookings.slot_interval', 60 );
        $this->service->update( [ 'duration' => 60, 'buffer_before' => 0, 'buffer_after' => 0 ] );
        $this->provider->availabilitySchedules()->delete();
        bookingsSchedule( $this->service, $this->provider, [ 1 ], '20:00', '23:00' );

        $needsRunUp = Service::factory()->create( [
            'duration'      => 60,
            'buffer_before' => 120,
            'buffer_after'  => 0,
        ] );

        Booking::factory()
            ->for( $needsRunUp )
            ->for( $this->provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( '2026-06-02 00:30', $this->timezone )->utc(), 60 )
            ->create();

        // 00:30 the next day, wanting two hours in front of it, reaches back to
        // 22:30 — so the 22:00 and 23:00 slots both go.
        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->toBe( [ '20:00', '21:00' ] );
    } );
} );

describe( 'the default provider fallback', function (): void {
    it( 'does not stand in for providers who have been deactivated', function (): void {
        // Switching off the last active provider closes the service. Falling
        // back here would quietly start offering somebody who does not even
        // offer it, as a side effect of an administrator deactivating somebody.
        $fallback = ServiceProvider::factory()->inTimezone( $this->timezone )->create();
        bookingsSchedule( Service::factory()->create(), $fallback );

        $this->service->update( [ 'default_provider_id' => $fallback->id ] );
        $this->provider->update( [ 'is_active' => false ] );

        expect( availability()->resolve( $this->service, null, $this->window ) )->toBe( [] );
    } );
} );

describe( 'the extent of a window', function (): void {
    it( 'does not compute the day a whole-day window merely ends on', function (): void {
        // A window covering Monday closes at midnight on Tuesday, and the range
        // is half-open — so Tuesday is not in it. Computing it anyway is a whole
        // day of work whose every slot is then thrown away for falling outside
        // the window, and it caches an answer nobody asked for.
        bookingsSchedule( $this->service, $this->provider, [ 2 ] );

        availability()->resolve( $this->service, $this->provider, $this->window );

        // Written past the models so no stamp moves. If Tuesday had been cached
        // by the call above, this would be invisible and Tuesday would still
        // report a full day.
        AvailabilityOverride::query()->insert( [
            'provider_id' => $this->provider->id,
            'date'        => '2026-06-02 00:00:00',
            'type'        => AvailabilityOverrideType::Unavailable->value,
            'created_at'  => now(),
            'updated_at'  => now(),
        ] );

        expect( availability()->resolve(
            $this->service,
            $this->provider,
            localDayWindow( '2026-06-02', $this->timezone ),
        ) )->toBe( [] );
    } );
} );

describe( 'conflict detection under a non-UTC application timezone', function (): void {
    // The zone is set on PHP rather than only in config: Laravel calls
    // `date_default_timezone_set()` once at boot, so a config write after boot
    // leaves booking times hydrating in UTC and the bug unreproducible. The
    // existing timezone file sets the zone through config alone and cannot reach
    // this.
    beforeEach( function (): void {
        $this->priorTimezone = date_default_timezone_get();
    } );

    afterEach( function (): void {
        // Restore whatever was in force before the test rather than assuming
        // the suite started on UTC, so a leaked zone cannot follow later tests.
        date_default_timezone_set( $this->priorTimezone );
    } );

    it( 'suppresses the slot a booking holds when the app zone is not UTC', function (): void {
        // Hydrated through the plain `'datetime'` cast this booking's 16:00 UTC
        // start comes back reinterpreted in Tokyo — nine hours off — so the
        // clash range is built at the wrong instant and the 11:00 slot the
        // booking actually holds is offered as free.
        date_default_timezone_set( 'Asia/Tokyo' );

        Booking::factory()
            ->for( $this->service )
            ->for( $this->provider, 'provider' )
            ->confirmed()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        $slots = availability()->resolve( $this->service, $this->provider, $this->window );

        expect( localStarts( $slots, $this->timezone ) )->not->toContain( '11:00' )
            ->and( $slots )->toHaveCount( 7 );
    } );

    it( 'suppresses the slot a busy block holds when the app zone is not UTC', function (): void {
        // `busyBlocksFor()` filters rows with the SQL scope (correct) but builds
        // the clash range from the hydrated `starts_at_utc` / `ends_at_utc`.
        // Through the plain `'datetime'` cast those come back reinterpreted in
        // Tokyo — nine hours off — so the busy block suppresses the wrong hour
        // and the time the calendar is actually busy is offered as free.
        date_default_timezone_set( 'Asia/Tokyo' );

        $connection = CalendarConnection::factory()
            ->for( $this->provider, 'provider' )
            ->twoWay()
            ->create();

        CalendarBusyBlock::factory()->for( $connection, 'connection' )->spanning(
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 11:00', $this->timezone )->utc(),
            CarbonImmutable::parse( AVAILABILITY_MONDAY . ' 12:00', $this->timezone )->utc(),
        )->create();

        $slots = availability()->resolve( $this->service, $this->provider, $this->window );

        expect( localStarts( $slots, $this->timezone ) )->not->toContain( '11:00' )
            ->and( $slots )->toHaveCount( 7 );
    } );
} );
