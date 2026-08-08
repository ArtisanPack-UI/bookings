<?php

declare( strict_types=1 );

it( 'merges the package defaults under the artisanpack.bookings key', function (): void {
    expect( config( 'artisanpack.bookings' ) )->toBeArray()->not->toBeEmpty();
} );

it( 'defaults the slot interval and booking window', function (): void {
    expect( config( 'artisanpack.bookings.slot_interval' ) )->toBe( 15 )
        ->and( config( 'artisanpack.bookings.booking_window.min_advance_minutes' ) )->toBe( 60 )
        ->and( config( 'artisanpack.bookings.booking_window.max_advance_minutes' ) )->toBe( 60 * 24 * 90 );
} );

it( 'falls back to the application timezone', function (): void {
    expect( config( 'artisanpack.bookings.timezone' ) )->toBe( config( 'app.timezone' ) );
} );

it( 'leaves every calendar driver disabled by default', function ( string $driver ): void {
    expect( config( "artisanpack.bookings.calendar.drivers.{$driver}.enabled" ) )->toBeFalse();
} )->with( [ 'google', 'microsoft', 'apple' ] );

it( 'defaults calendar sync to outbound only', function (): void {
    expect( config( 'artisanpack.bookings.calendar.default_sync_mode' ) )->toBe( 'outbound' );
} );

it( 'ships the config keys the package plan defines', function ( string $key ): void {
    expect( config()->has( "artisanpack.bookings.{$key}" ) )->toBeTrue();
} )->with( [
    'timezone',
    'slot_interval',
    'availability_cache',
    'booking_window',
    'cancellation',
    'series',
    'notifications',
    'calendar',
    'webhooks',
    'admin',
    'public',
    'retention',
    'multi_tenant',
] );

it( 'reads the defaults from the file the publish tag copies', function (): void {
    $published = require __DIR__ . '/../../config/artisanpack/bookings.php';

    // The merge key has to match the key Laravel derives from the published
    // path, or an application that publishes the config edits a file the
    // package never reads.
    expect( config( 'artisanpack.bookings.slot_interval' ) )->toBe( $published[ 'slot_interval' ] )
        ->and( config( 'artisanpack.bookings.admin.gate' ) )->toBe( $published[ 'admin' ][ 'gate' ] );
} );
