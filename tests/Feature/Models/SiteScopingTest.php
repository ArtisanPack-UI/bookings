<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceBlackoutDate;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Models\Webhook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * The models a site owns directly.
 *
 * Everything else in the package hangs off one of these — a busy block belongs
 * to a connection, a schema version to a service — and is reached through an
 * already-scoped parent, which is why it carries no `site_id` of its own.
 */
dataset( 'site owned models', [
    'services'             => [ Service::class ],
    'providers'            => [ ServiceProvider::class ],
    'blackout dates'       => [ ServiceBlackoutDate::class ],
    'bookings'             => [ Booking::class ],
    'series'               => [ BookingSeries::class ],
    'calendar connections' => [ CalendarConnection::class ],
    'webhooks'             => [ Webhook::class ],
] );

it( 'stamps a new row with the site in context', function ( string $model ): void {
    scopeToSite( 7 );

    /** @var Model $row */
    $row = $model::factory()->create();

    expect( $row->site_id )->toBe( 7 );
} )->with( 'site owned models' );

it( 'hides a row belonging to another site', function ( string $model ): void {
    scopeToSite( 1 );
    $mine = $model::factory()->create();

    scopeToSite( 2 );
    $theirs = $model::factory()->create();

    expect( $model::query()->get()->modelKeys() )->toBe( [ $theirs->id ] );

    scopeToSite( 1 );

    expect( $model::query()->get()->modelKeys() )->toBe( [ $mine->id ] );
} )->with( 'site owned models' );

it( 'sees every site at once when asked to', function ( string $model ): void {
    // Retention pruning and the erasure command both have to walk every tenant,
    // so the escape hatch has to exist — and has to be spelled out at the call
    // site rather than being what happens by default.
    scopeToSite( 1 );
    $model::factory()->create();

    scopeToSite( 2 );
    $model::factory()->create();

    expect( $model::query()->count() )->toBe( 1 )
        ->and( $model::acrossAllSites()->count() )->toBe( 2 );
} )->with( 'site owned models' );

it( 'leaves every row visible while site scoping is off', function ( string $model ): void {
    // The default configuration. A single-tenant application installs the
    // package and never thinks about sites again.
    $model::factory()->count( 3 )->create();

    expect( $model::query()->count() )->toBe( 3 )
        ->and( $model::query()->first()->site_id )->toBeNull();
} )->with( 'site owned models' );

it( 'refuses to let request data place a row under another tenant', function ( string $model ): void {
    // An explicitly set site beats the resolved one — which is what makes
    // deliberate cross-site writes possible — so a mass-assignable site_id
    // would let a request body pick its own tenant.
    scopeToSite( 1 );

    /** @var Model $row */
    $row = new $model();
    $row->fill( [ 'site_id' => 99 ] );

    expect( $row->site_id )->toBeNull();
} )->with( 'site owned models' );
