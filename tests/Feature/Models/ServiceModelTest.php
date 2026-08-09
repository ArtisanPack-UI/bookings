<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\ServiceAssignmentStrategy;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceBlackoutDate;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Models\ServiceProviderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'the service factory', function (): void {
    it( 'produces a valid service', function (): void {
        $service = Service::factory()->create();

        expect( $service->exists )->toBeTrue()
            ->and( $service->duration )->toBeGreaterThan( 0 )
            ->and( $service->is_active )->toBeTrue()
            ->and( $service->assignment_strategy )->toBe( ServiceAssignmentStrategy::Any );
    } );

    it( 'gives every service a slug of its own', function (): void {
        // The constraint this has to satisfy is a database index that is global
        // on a single-tenant installation, so a colliding slug would surface as
        // an unrelated test failing on insert.
        $slugs = Service::factory()->count( 25 )->create()->pluck( 'slug' );

        expect( $slugs->unique() )->toHaveCount( 25 );
    } );

    it( 'builds free, inactive, group, and round-robin services', function (): void {
        expect( Service::factory()->free()->create()->is_free )->toBeTrue()
            ->and( Service::factory()->inactive()->create()->is_active )->toBeFalse()
            ->and( Service::factory()->groupBooked( 6 )->create()->max_bookings_per_slot )->toBe( 6 )
            ->and( Service::factory()->roundRobin()->create()->assignment_strategy )
            ->toBe( ServiceAssignmentStrategy::RoundRobin );
    } );
} );

describe( 'the service provider factory', function (): void {
    it( 'produces a valid provider with a real timezone', function (): void {
        $provider = ServiceProvider::factory()->create();

        expect( $provider->exists )->toBeTrue()
            ->and( $provider->timezone )->not->toBeEmpty()
            ->and( timezone_open( $provider->timezone ) )->not->toBeFalse();
    } );

    it( 'gives every provider a slug of its own', function (): void {
        $slugs = ServiceProvider::factory()->count( 25 )->create()->pluck( 'slug' );

        expect( $slugs->unique() )->toHaveCount( 25 );
    } );
} );

describe( 'the blackout date factory', function (): void {
    it( 'produces a blackout that covers its own range', function (): void {
        $blackout = ServiceBlackoutDate::factory()->create();

        expect( $blackout->exists )->toBeTrue()
            ->and( $blackout->covers( $blackout->starts_on ) )->toBeTrue()
            ->and( $blackout->covers( $blackout->ends_on ) )->toBeTrue()
            ->and( $blackout->covers( $blackout->ends_on->copy()->addDay() ) )->toBeFalse();
    } );

    it( 'builds a site-wide closure', function (): void {
        $blackout = ServiceBlackoutDate::factory()->siteWide()->create();

        expect( $blackout->service_id )->toBeNull()
            ->and( $blackout->isSiteWide() )->toBeTrue();
    } );
} );

describe( 'the provider pairing', function (): void {
    it( 'reads the same pairing from either side', function (): void {
        $service  = Service::factory()->create();
        $provider = ServiceProvider::factory()->create();

        $service->providers()->attach( $provider );

        expect( $service->providers()->get()->modelKeys() )->toBe( [ $provider->id ] )
            ->and( $provider->services()->get()->modelKeys() )->toBe( [ $service->id ] );
    } );

    it( 'detaches from either side', function (): void {
        $service  = Service::factory()->withProviders( 2 )->create();
        $provider = $service->providers()->first();

        $provider->services()->detach( $service );

        expect( $service->providers()->count() )->toBe( 1 );
    } );

    it( 'carries the per-pairing price and duration overrides', function (): void {
        $service  = Service::factory()->create( [ 'price' => '100.00', 'duration' => 30 ] );
        $provider = ServiceProvider::factory()->create();

        $service->providers()->attach( $provider, [ 'custom_price' => '175.00', 'custom_duration' => 45 ] );

        $pivot = $service->providers()->first()->pivot;

        expect( $pivot )->toBeInstanceOf( ServiceProviderService::class )
            ->and( $pivot->priceFor( $service ) )->toBe( '175.00' )
            ->and( $pivot->durationFor( $service ) )->toBe( 45 );
    } );

    it( 'falls back to the service when a pairing overrides nothing', function (): void {
        $service  = Service::factory()->create( [ 'price' => '100.00', 'duration' => 30 ] );
        $provider = ServiceProvider::factory()->create();

        $service->providers()->attach( $provider );

        $pivot = $service->providers()->first()->pivot;

        expect( $pivot->priceFor( $service ) )->toBe( '100.00' )
            ->and( $pivot->durationFor( $service ) )->toBe( 30 );
    } );

    it( 'refuses to answer for a service it is not the pairing for', function (): void {
        // The overrides fall back to the service's own values, so being handed
        // the wrong service returns a plausible number belonging to something
        // else — a wrong invoice rather than a failure.
        $service = Service::factory()->create( [ 'price' => '100.00' ] );
        $other   = Service::factory()->create( [ 'price' => '999.00' ] );

        $service->providers()->attach( ServiceProvider::factory()->create() );
        $pivot = $service->providers()->first()->pivot;

        expect( fn () => $pivot->priceFor( $other ) )->toThrow( InvalidArgumentException::class )
            ->and( fn () => $pivot->durationFor( $other ) )->toThrow( InvalidArgumentException::class )
            ->and( $pivot->priceFor( $service ) )->toBe( '100.00' );
    } );

    it( 'refuses to pair the same provider with the same service twice', function (): void {
        $service  = Service::factory()->create();
        $provider = ServiceProvider::factory()->create();

        $service->providers()->attach( $provider );

        expect( fn () => $service->providers()->attach( $provider ) )
            ->toThrow( QueryException::class );
    } );
} );

describe( 'the service casts', function (): void {
    it( 'round-trips the intake schema through JSON', function (): void {
        $schema = [
            'fields' => [
                [ 'name' => 'goal', 'type' => 'textarea', 'required' => true ],
                [ 'name' => 'budget', 'type' => 'number', 'required' => false, 'min' => 0 ],
            ],
        ];

        $service = Service::factory()->create( [ 'intake_schema' => $schema ] );

        expect( $service->fresh()->intake_schema )->toBe( $schema );
    } );

    it( 'round-trips metadata through JSON', function (): void {
        $metadata = [ 'crm' => [ 'pipeline_id' => 42 ], 'tags' => [ 'vip', 'retainer' ] ];

        $service = Service::factory()->create( [ 'metadata' => $metadata ] );

        expect( $service->fresh()->metadata )->toBe( $metadata );
    } );

    it( 'reads the assignment strategy back as an enum', function (): void {
        $service = Service::factory()->create( [ 'assignment_strategy' => 'round_robin' ] );

        expect( $service->fresh()->assignment_strategy )->toBe( ServiceAssignmentStrategy::RoundRobin );
    } );

    it( 'reports no contrast colour without the accessibility package', function (): void {
        // The helper ships with artisanpack-ui/accessibility, which is suggested
        // rather than required. Guessing at a contrast ratio would produce an
        // unreadable label instead of a visible failure, so the accessor reports
        // nothing at all.
        $service = Service::factory()->create( [ 'color' => '#3b82f6' ] );

        $expected = function_exists( 'a11yGetContrastColor' )
            ? a11yGetContrastColor( '#3b82f6' )
            : null;

        expect( $service->contrast_color )->toBe( $expected );
    } );

    it( 'reports no contrast colour when the service has no colour', function (): void {
        $service = Service::factory()->create( [ 'color' => null ] );

        expect( $service->contrast_color )->toBeNull();
    } );
} );

describe( 'soft deletes and erasure', function (): void {
    it( 'hides a soft-deleted service without destroying it', function (): void {
        $service = Service::factory()->create();

        $service->delete();

        expect( Service::query()->count() )->toBe( 0 )
            ->and( Service::withTrashed()->count() )->toBe( 1 )
            ->and( $service->fresh()->deleted_at )->not->toBeNull();
    } );

    it( 'restores a soft-deleted service', function (): void {
        $service = Service::factory()->create();
        $service->delete();

        $service->restore();

        expect( Service::query()->count() )->toBe( 1 );
    } );

    it( 'separates erasure from deletion', function (): void {
        // The two answer different questions. A soft delete hides a row; erasure
        // applies to rows that have to keep existing and overwrites the personal
        // columns in place. A row can be either, both, or neither.
        $erased = Service::factory()->erased()->create();
        $intact = Service::factory()->create();

        expect( $erased->isPiiErased() )->toBeTrue()
            ->and( $erased->deleted_at )->toBeNull()
            ->and( $intact->isPiiErased() )->toBeFalse()
            ->and( Service::piiErased()->get()->modelKeys() )->toBe( [ $erased->id ] )
            ->and( Service::notPiiErased()->get()->modelKeys() )->toBe( [ $intact->id ] );
    } );

    it( 'keeps pii_erased_at out of mass assignment', function (): void {
        // The marker is what distinguishes a redacted row from a real one, so
        // only the erasure routine has any business setting it.
        $service = Service::factory()->create();

        $service->fill( [ 'pii_erased_at' => now() ] );

        expect( $service->pii_erased_at )->toBeNull();
    } );
} );

describe( 'the service relationships', function (): void {
    it( 'reaches its blackout dates', function (): void {
        $service = Service::factory()->create();
        ServiceBlackoutDate::factory()->count( 2 )->for( $service )->create();

        expect( $service->blackoutDates()->count() )->toBe( 2 );
    } );

    it( 'finds the closures covering a date, site-wide ones included', function (): void {
        $service = Service::factory()->create();

        $own = ServiceBlackoutDate::factory()->for( $service )->onDate( '2026-12-24' )->create();
        $all = ServiceBlackoutDate::factory()->siteWide()->onDate( '2026-12-24' )->create();
        ServiceBlackoutDate::factory()->for( $service )->onDate( '2026-12-31' )->create();

        $closing = ServiceBlackoutDate::closing( $service, '2026-12-24' )->get()->modelKeys();

        sort( $closing );

        expect( $closing )->toBe( collect( [ $own->id, $all->id ] )->sort()->values()->all() );
    } );

    it( 'resolves its default provider', function (): void {
        $provider = ServiceProvider::factory()->create();
        $service  = Service::factory()->create( [ 'default_provider_id' => $provider->id ] );

        expect( $service->defaultProvider->is( $provider ) )->toBeTrue()
            ->and( $provider->defaultForServices()->count() )->toBe( 1 );
    } );

    it( 'scopes to active services and providers', function (): void {
        Service::factory()->count( 2 )->create();
        Service::factory()->inactive()->create();
        ServiceProvider::factory()->create();
        ServiceProvider::factory()->inactive()->create();

        expect( Service::active()->count() )->toBe( 2 )
            ->and( ServiceProvider::active()->count() )->toBe( 1 );
    } );

    it( 'orders providers by how long ago they were last assigned', function (): void {
        $never  = ServiceProvider::factory()->create();
        $recent = ServiceProvider::factory()->lastAssigned( '-5 minutes' )->create();
        $stale  = ServiceProvider::factory()->lastAssigned( '-2 days' )->create();

        expect( ServiceProvider::leastRecentlyAssigned()->get()->modelKeys() )
            ->toBe( [ $never->id, $stale->id, $recent->id ] );
    } );
} );
