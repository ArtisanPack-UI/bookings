<?php

declare( strict_types=1 );

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it( 'runs against sqlite in memory by default', function (): void {
    expect( config( 'database.default' ) )->toBe( 'testbench' )
        ->and( DB::connection()->getDriverName() )->toBe( 'sqlite' )
        ->and( config( 'database.connections.testbench.database' ) )->toBe( ':memory:' );
} );

it( 'has a working schema builder', function (): void {
    Schema::create( 'harness_check', function ( Blueprint $table ): void {
        $table->increments( 'id' );
        $table->string( 'name' );
    } );

    DB::table( 'harness_check' )->insert( [ 'name' => 'ok' ] );

    expect( Schema::hasTable( 'harness_check' ) )->toBeTrue()
        ->and( DB::table( 'harness_check' )->count() )->toBe( 1 );
} );

it( 'starts each test from a clean database', function (): void {
    // Testbench builds a fresh in-memory database per test, so the table the
    // previous test created is gone. Worth pinning: a harness that leaked
    // state between tests would make later failures unreproducible in
    // isolation.
    expect( Schema::hasTable( 'harness_check' ) )->toBeFalse();
} );

it( 'resolves model factories out of the package namespace', function (): void {
    // Package models live in ArtisanPackUI\Bookings\Models, not App\Models, so
    // Laravel's default guess would look for a factory that will never exist.
    expect( Factory::resolveFactoryName( 'ArtisanPackUI\\Bookings\\Models\\Service' ) )
        ->toBe( 'ArtisanPackUI\\Bookings\\Database\\Factories\\ServiceFactory' );
} );
