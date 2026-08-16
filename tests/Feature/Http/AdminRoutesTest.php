<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Http\Middleware\AuthorizeBookingsAdmin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * The admin screens, keyed by route name with the URI each should mount at.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function adminScreens(): array
{
    return [
        'bookings'             => [ 'artisanpack.bookings.admin.bookings', 'bookings-admin/bookings' ],
        'bookings.create'      => [ 'artisanpack.bookings.admin.bookings.create', 'bookings-admin/bookings/create' ],
        'bookings.show'        => [ 'artisanpack.bookings.admin.bookings.show', 'bookings-admin/bookings/{booking}' ],
        'calendar'             => [ 'artisanpack.bookings.admin.calendar', 'bookings-admin/calendar' ],
        'services'             => [ 'artisanpack.bookings.admin.services', 'bookings-admin/services' ],
        'services.intake'      => [ 'artisanpack.bookings.admin.services.intake-schema', 'bookings-admin/services/{service}/intake-schema' ],
        'providers'            => [ 'artisanpack.bookings.admin.providers', 'bookings-admin/providers' ],
        'blackout-dates'       => [ 'artisanpack.bookings.admin.blackout-dates', 'bookings-admin/blackout-dates' ],
        'series'               => [ 'artisanpack.bookings.admin.series', 'bookings-admin/series' ],
        'calendar-connections' => [ 'artisanpack.bookings.admin.calendar-connections', 'bookings-admin/calendar-connections' ],
        'webhooks'             => [ 'artisanpack.bookings.admin.webhooks', 'bookings-admin/webhooks' ],
        'notifications'        => [ 'artisanpack.bookings.admin.notifications', 'bookings-admin/notifications' ],
        'settings'             => [ 'artisanpack.bookings.admin.settings', 'bookings-admin/settings' ],
    ];
}

describe( 'the admin routes', function (): void {
    it( 'mounts every screen from plan §6.2 under the configured prefix', function ( string $name, string $uri ): void {
        $route = Route::getRoutes()->getByName( $name );

        expect( $route )->not->toBeNull( $name . ' is not registered' )
            ->and( $route->uri() )->toBe( $uri );
    } )->with( adminScreens() );

    it( 'guards every screen with the bookings.admin gate middleware', function ( string $name ): void {
        $route = Route::getRoutes()->getByName( $name );

        expect( $route->gatherMiddleware() )->toContain( 'web', 'bookings.admin' );
    } )->with( array_map( static fn ( array $screen ): array => [ $screen[0] ], adminScreens() ) );

    it( 'binds every screen to a controller so the routes survive route:cache', function ( string $name ): void {
        // A closure action cannot be serialized, so a single closure route would
        // fail `php artisan route:cache` for the whole consuming application. The
        // action has to be a controller string for the route table to cache.
        $route = Route::getRoutes()->getByName( $name );

        expect( $route->getActionName() )->toContain( 'AdminScreenController' )
            ->and( $route->getActionName() )->not->toBe( 'Closure' );
    } )->with( array_map( static fn ( array $screen ): array => [ $screen[0] ], adminScreens() ) );
} );

describe( 'the admin gate', function (): void {
    it( 'refuses a request when the ability is undefined', function (): void {
        // The package deliberately ships no default ability, so an installation
        // that mounts these without wiring the gate gets a locked door, not an
        // open admin.
        $this->get( route( 'artisanpack.bookings.admin.settings' ) )->assertForbidden();
    } );

    it( 'refuses a request the ability denies', function (): void {
        Gate::define( 'bookings.manage', static fn ( ?Authenticatable $user = null ): bool => false );

        $this->get( route( 'artisanpack.bookings.admin.settings' ) )->assertForbidden();
    } );
} );

describe( 'the standalone layout', function (): void {
    beforeEach( function (): void {
        Gate::define( 'bookings.manage', static fn ( ?Authenticatable $user = null ): bool => true );
    } );

    it( 'registers the admin gate as persistent Livewire middleware', function (): void {
        // The registration is what keeps the gate on update requests; the
        // end-to-end proof is the next test.
        expect( app( PersistentMiddleware::class )->getPersistentMiddleware() )
            ->toContain( AuthorizeBookingsAdmin::class );
    } );

    it( 'keeps the gate on a Livewire update, not just the initial page load', function (): void {
        // The classic Livewire gap: route middleware guards the mount, but the
        // update request that runs a cancel or a PII erasure lands on Livewire's
        // own endpoint. Persist the gate and it is re-checked there too. Here the
        // operator loses the ability after the page was drawn, and the follow-up
        // update is refused rather than executed.
        $page = $this->get( route( 'artisanpack.bookings.admin.settings' ) )->assertOk();

        expect( preg_match( '/wire:snapshot="([^"]+)"/', $page->getContent(), $matches ) )->toBe( 1 );

        $snapshot = html_entity_decode( $matches[1], ENT_QUOTES );

        Gate::define( 'bookings.manage', static fn ( ?Authenticatable $user = null ): bool => false );

        $response = $this->withoutMiddleware( VerifyCsrfToken::class )
            ->withHeaders( [ 'X-Livewire' => 'true' ] )
            ->postJson( app( 'livewire' )->getUpdateUri(), [
                'components' => [
                    [
                        'snapshot' => $snapshot,
                        'calls'    => [],
                        'updates'  => [],
                    ],
                ],
            ] );

        $response->assertForbidden();
    } );

    it( 'renders the screens inside the package layout when cms-framework is absent', function ( string $name ): void {
        // cms-framework is a `suggest`, so it is genuinely absent here — the
        // branch the composer resolves to `bookings::admin.layouts.app`. The
        // hand-off partial's `@json` script compiles as part of these pages, so
        // rendering them is what proves it too.
        $response = $this->get( route( $name ) );

        $response->assertOk()
            ->assertSee( 'bookings-admin__sidebar', false )
            ->assertSee( 'bookings-admin/services', false );
    } )->with( [
        'settings' => [ 'artisanpack.bookings.admin.settings' ],
        'bookings' => [ 'artisanpack.bookings.admin.bookings' ],
        'services' => [ 'artisanpack.bookings.admin.services' ],
        'calendar' => [ 'artisanpack.bookings.admin.calendar' ],
    ] );
} );
