<?php

declare( strict_types=1 );

use Illuminate\Support\Facades\Route;
use Tests\Concerns\DisablesAdminRoutes;

uses( DisablesAdminRoutes::class );

/**
 * Every registered route name carrying the given prefix.
 *
 * @param  string  $prefix  The route-name prefix to collect.
 *
 * @return array<int, string> The matching route names.
 */
function routeNamesStartingWith( string $prefix ): array
{
    return array_values( array_filter(
        array_keys( Route::getRoutes()->getRoutesByName() ),
        static fn ( string $name ): bool => str_starts_with( $name, $prefix ),
    ) );
}

describe( 'the admin.routes_enabled seam', function (): void {
    it( 'registers not one bookings-admin screen when switched off', function (): void {
        // The whole point of the seam: the screens are a face for the package's
        // services, and a host with its own face has no use for a second one.
        // Off, none of `routes/admin.php` is mounted — asserted against the
        // whole name space rather than a hand-kept list, so a screen added
        // later cannot slip back in behind this test.
        expect( routeNamesStartingWith( 'artisanpack.bookings.admin.' ) )->toBe( [] );
    } );

    it( 'answers a request for a bookings-admin url with a 404, not a login', function (): void {
        // Gone, not merely guarded: with the route unregistered there is nothing
        // to authorize against, so the router itself refuses the address.
        $this->get( '/bookings-admin/settings' )->assertNotFound();
    } );

    it( 'leaves the public json api registered', function (): void {
        // The seam is the admin surface alone. The customer-facing endpoints the
        // booking flow runs on are on a different route file and stay mounted.
        expect( Route::has( 'artisanpack.bookings.api.services.index' ) )->toBeTrue()
            ->and( Route::has( 'artisanpack.bookings.api.bookings.store' ) )->toBeTrue()
            ->and( Route::has( 'artisanpack.bookings.api.manage.show' ) )->toBeTrue();
    } );

    it( 'leaves the ical feeds registered', function (): void {
        expect( Route::has( 'artisanpack.bookings.ical.provider' ) )->toBeTrue()
            ->and( Route::has( 'artisanpack.bookings.ical.customer' ) )->toBeTrue();
    } );

    it( 'leaves the widget route registered', function (): void {
        // Disabling the admin routes is independent of the widget seam — the two
        // read different config keys — so the widget's form target is untouched.
        expect( Route::has( 'artisanpack.bookings.widget.store' ) )->toBeTrue();
    } );
} );
