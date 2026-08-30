<?php

declare( strict_types=1 );

use Illuminate\Support\Facades\Route;
use Tests\Concerns\DisablesWidgetRoute;

uses( DisablesWidgetRoute::class );

describe( 'the public.widgetEnabled seam', function (): void {
    it( 'does not register the widget form target when switched off', function (): void {
        // A host routing bookings through its own forms never renders the no-JS
        // widget, so its `POST {prefix}/widget` target is dead weight — off, it
        // is not mounted.
        expect( Route::has( 'artisanpack.bookings.widget.store' ) )->toBeFalse();
    } );

    it( 'answers a post to the widget url with a 404, not a validation error', function (): void {
        // Gone, not merely empty: with the route unregistered the router refuses
        // the address outright rather than reaching the controller.
        $this->post( '/bookings/widget', [] )->assertNotFound();
    } );

    it( 'leaves the public json api registered', function (): void {
        // The seam is the Blade widget alone. The JSON endpoints the widget — and
        // any host-built form — actually books against are on the same public
        // route file and stay mounted.
        expect( Route::has( 'artisanpack.bookings.api.services.index' ) )->toBeTrue()
            ->and( Route::has( 'artisanpack.bookings.api.services.slots' ) )->toBeTrue()
            ->and( Route::has( 'artisanpack.bookings.api.bookings.store' ) )->toBeTrue();
    } );

    it( 'leaves the ical feeds registered', function (): void {
        expect( Route::has( 'artisanpack.bookings.ical.provider' ) )->toBeTrue()
            ->and( Route::has( 'artisanpack.bookings.ical.customer' ) )->toBeTrue();
    } );

    it( 'leaves the admin routes registered', function (): void {
        // Disabling the widget is independent of the admin seam — the two read
        // different config keys — so the staff-facing screens are untouched.
        expect( Route::has( 'artisanpack.bookings.admin.settings' ) )->toBeTrue();
    } );
} );
