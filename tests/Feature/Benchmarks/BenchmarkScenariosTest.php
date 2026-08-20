<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Benchmarks\AvailabilityScenario;
use ArtisanPackUI\Bookings\Benchmarks\CalendarSyncScenario;
use ArtisanPackUI\Bookings\Benchmarks\FakeGoogleCalendarDriver;
use ArtisanPackUI\Bookings\Contracts\CalendarDriverRegistry;
use ArtisanPackUI\Bookings\Models\CalendarEvent;
use ArtisanPackUI\Bookings\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

it( 'measures availability over cold and warm caches', function (): void {
    $scenario = new AvailabilityScenario( app( AvailabilityService::class ) );
    $service  = $scenario->seed( 2 );

    $result = $scenario->measure( $service, 3, 2, 3 );

    expect( $result['providers'] )->toBe( 2 )
        ->and( $result['days'] )->toBe( 3 )
        ->and( $result['slots'] )->toBeGreaterThan( 0 )
        ->and( $result['cold']['count'] )->toBe( 2 )
        ->and( $result['warm']['count'] )->toBe( 3 )
        ->and( $result['cold']['min'] )->toBeGreaterThan( 0.0 )
        ->and( $result['warm']['min'] )->toBeGreaterThan( 0.0 );

    // The whole point of the cache: a warm resolve costs less than a cold one.
    expect( $result['warm']['mean'] )->toBeLessThan( $result['cold']['mean'] );
} );

it( 'measures calendar-sync throughput against a faked google', function (): void {
    $scenario = new CalendarSyncScenario( app( CalendarDriverRegistry::class ) );
    $driver   = new FakeGoogleCalendarDriver();
    $scenario->useDriver( $driver );

    $pairs  = $scenario->seed( 2, 3 );
    $result = $scenario->measure( $pairs );

    expect( $pairs )->toHaveCount( 6 )
        ->and( $result['pushes'] )->toBe( 6 )
        ->and( $result['throughput'] )->toBeGreaterThan( 0.0 )
        ->and( $result['latency']['count'] )->toBe( 6 )
        ->and( $result['driver']['create'] )->toBe( 6 )
        ->and( $result['events'] )->toBe( 6 );

    // Every push wrote a ledger row, which is what a real sync leaves behind.
    expect( CalendarEvent::query()->count() )->toBe( 6 );
} );
