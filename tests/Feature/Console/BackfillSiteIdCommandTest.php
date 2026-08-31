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
 * The models a site owns directly, each of which the command backfills.
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

it( 'stamps a siteless row with the given site', function ( string $model ): void {
    // Written while scoping was off, so the factory leaves site_id null.
    /** @var Model $row */
    $row = $model::factory()->create();

    expect( $row->site_id )->toBeNull();

    $this->artisan( 'bookings:backfill-site-id', [ '--site' => 5 ] )
        ->assertSuccessful();

    expect( $model::query()->acrossAllSites()->find( $row->getKey() )->site_id )->toBe( 5 );
} )->with( 'site owned models' );

it( 'accepts the site as a string, the way the command line passes it', function (): void {
    // Symfony hands `--site=5` over as the string '5'; a green test for the int
    // path alone would miss a regression on the path every real run takes.
    $booking = Booking::factory()->create();

    $this->artisan( 'bookings:backfill-site-id', [ '--site' => '5' ] )
        ->assertSuccessful();

    expect( Booking::query()->acrossAllSites()->find( $booking->getKey() )->site_id )->toBe( 5 );
} );

it( 'stamps nothing on a second run once every row has a site', function (): void {
    // A doubled run — the operator reruns to be sure — has to be a clean no-op
    // rather than restamping rows or failing.
    Booking::factory()->count( 2 )->create();

    $this->artisan( 'bookings:backfill-site-id', [ '--site' => 5 ] )->assertSuccessful();

    $this->artisan( 'bookings:backfill-site-id', [ '--site' => 9 ] )
        ->expectsOutputToContain( '0 row(s) stamped with site 9.' )
        ->assertSuccessful();

    expect( Booking::query()->acrossAllSites()->whereNull( 'site_id' )->count() )->toBe( 0 )
        ->and( Booking::query()->acrossAllSites()->where( 'site_id', 5 )->count() )->toBe( 2 );
} );

it( 'leaves a row that already belongs to a site untouched', function (): void {
    $orphan = Booking::factory()->create();

    $owned = Booking::factory()->create();
    $owned->forceFill( [ 'site_id' => 2 ] )->save();

    $this->artisan( 'bookings:backfill-site-id', [ '--site' => 5 ] )
        ->assertSuccessful();

    expect( Booking::query()->acrossAllSites()->find( $orphan->getKey() )->site_id )->toBe( 5 )
        ->and( Booking::query()->acrossAllSites()->find( $owned->getKey() )->site_id )->toBe( 2 );
} );

it( 'reaches a soft-deleted row', function (): void {
    // A booking pruned for retention still carries a null site_id, and an
    // erasure request has to be able to reach it — so the backfill must too.
    $booking = Booking::factory()->create();
    $booking->delete();

    $this->artisan( 'bookings:backfill-site-id', [ '--site' => 5 ] )
        ->assertSuccessful();

    expect( Booking::query()->acrossAllSites()->withTrashed()->find( $booking->getKey() )->site_id )->toBe( 5 );
} );

it( 'names the target site in its summary and leaves no siteless row behind', function (): void {
    Booking::factory()->count( 2 )->create();
    Service::factory()->create();

    $this->artisan( 'bookings:backfill-site-id', [ '--site' => 5 ] )
        ->expectsOutputToContain( 'stamped with site 5.' )
        ->assertSuccessful();

    expect( Booking::query()->acrossAllSites()->withTrashed()->whereNull( 'site_id' )->count() )->toBe( 0 )
        ->and( Service::query()->acrossAllSites()->withTrashed()->whereNull( 'site_id' )->count() )->toBe( 0 );
} );

describe( '--dry-run', function (): void {
    it( 'reports what it would stamp without writing anything', function (): void {
        $booking = Booking::factory()->create();

        $this->artisan( 'bookings:backfill-site-id', [ '--site' => 5, '--dry-run' => true ] )
            ->expectsOutputToContain( 'would be stamped with site 5.' )
            ->assertSuccessful();

        expect( $booking->fresh()->site_id )->toBeNull();
    } );
} );

describe( 'the --site option', function (): void {
    it( 'fails when --site is absent', function (): void {
        $this->artisan( 'bookings:backfill-site-id' )
            ->expectsOutputToContain( 'Pass --site with a positive integer' )
            ->assertFailed();
    } );

    it( 'refuses a value that is not a positive integer', function ( string $value ): void {
        $booking = Booking::factory()->create();

        $this->artisan( 'bookings:backfill-site-id', [ '--site' => $value ] )
            ->assertFailed();

        expect( $booking->fresh()->site_id )->toBeNull();
    } )->with( [
        'zero'         => [ '0' ],
        'negative'     => [ '-1' ],
        'non-digit'    => [ 'abc' ],
        'empty'        => [ '' ],
        // Past PHP_INT_MAX: a digits-only cast would clamp this to PHP_INT_MAX
        // and stamp every row with a site the operator never named.
        'out of range' => [ ( (string) PHP_INT_MAX ) . '0' ],
    ] );
} );
