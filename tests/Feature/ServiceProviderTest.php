<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Bookings;
use ArtisanPackUI\Bookings\Facades\Bookings as BookingsFacade;
use ArtisanPackUI\Bookings\Livewire\Public\BookingWidget;
use ArtisanPackUI\Bookings\Providers\BookingsServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

it( 'publishes the views under the bookings-views tag', function (): void {
    $paths = ServiceProvider::pathsToPublish( BookingsServiceProvider::class, 'bookings-views' );

    expect( $paths )->not->toBeEmpty()
        ->and( array_values( $paths )[ 0 ] )->toBe( resource_path( 'views/vendor/bookings' ) );
} );

it( 'resolves the package views under the bookings namespace', function (): void {
    expect( view()->exists( 'bookings::livewire.public.booking-widget' ) )->toBeTrue();
} );

it( 'registers the booking widget under the name an embed writes by hand', function (): void {
    expect( Livewire::new( 'artisanpack-booking-widget' ) )->toBeInstanceOf( BookingWidget::class );
} );

it( 'mounts the widget form target in the web group', function (): void {
    $route = Route::getRoutes()->getByName( 'artisanpack.bookings.widget.store' );

    expect( $route )->not->toBeNull()
        ->and( $route->methods() )->toContain( 'POST' )
        ->and( $route->gatherMiddleware() )->toContain( 'web' )
        ->and( $route->gatherMiddleware() )->toContain( 'bookings.rate-limit:post' );
} );
