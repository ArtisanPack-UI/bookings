<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Bookings;
use ArtisanPackUI\Bookings\Facades\Bookings as BookingsFacade;
use ArtisanPackUI\Bookings\Providers\BookingsServiceProvider;
use Illuminate\Support\ServiceProvider;

it( 'boots in a Testbench application with no configuration', function (): void {
    expect( app()->getProviders( BookingsServiceProvider::class ) )->not->toBeEmpty();
} );

it( 'registers the bookings binding', function (): void {
    expect( app( 'bookings' ) )->toBeInstanceOf( Bookings::class );
} );

it( 'registers the binding as a singleton', function (): void {
    expect( app( 'bookings' ) )->toBe( app( 'bookings' ) );
} );

it( 'resolves the same instance through the facade', function (): void {
    expect( BookingsFacade::getFacadeRoot() )->toBe( app( 'bookings' ) );
} );

it( 'resolves the same instance through the helper function', function (): void {
    expect( bookings() )->toBe( app( 'bookings' ) );
} );

it( 'publishes the config under the bookings-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish( BookingsServiceProvider::class, 'bookings-config' );

    expect( $paths )->not->toBeEmpty()
        ->and( array_values( $paths )[ 0 ] )->toBe( config_path( 'artisanpack/bookings.php' ) );
} );
