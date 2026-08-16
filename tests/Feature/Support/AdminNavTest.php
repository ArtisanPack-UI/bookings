<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Support\AdminNav;

describe( 'the admin navigation', function (): void {
    it( 'lists the staff-facing screens in order', function (): void {
        $slugs = array_column( AdminNav::items(), 'slug' );

        expect( $slugs )->toBe( [
            'bookings',
            'bookings-calendar',
            'bookings-services',
            'bookings-providers',
            'bookings-blackout-dates',
            'bookings-series',
            'bookings-calendar-connections',
            'bookings-webhooks',
            'bookings-notifications',
            'bookings-settings',
        ] );
    } );

    it( 'names a registered route for every screen', function (): void {
        foreach ( AdminNav::items() as $item ) {
            expect( Route::has( $item['route'] ) )->toBeTrue( $item['route'] . ' is not registered' );
        }
    } );

    it( 'builds one cms parent holding every screen as a child', function (): void {
        $entries = AdminNav::cmsMenuEntries();

        expect( $entries )->toHaveKey( AdminNav::CMS_MENU_SLUG );

        $parent = $entries[ AdminNav::CMS_MENU_SLUG ];

        expect( $parent['label'] )->toBe( __( 'Bookings' ) )
            ->and( $parent['permission'] )->toBe( 'bookings.manage' )
            ->and( array_keys( $parent['subItems'] ) )->toBe( array_column( AdminNav::items(), 'slug' ) );
    } );

    it( 'gates every cms child on the configured admin gate', function (): void {
        config()->set( 'artisanpack.bookings.admin.gate', 'custom.bookings.gate' );

        $parent = AdminNav::cmsMenuEntries()[ AdminNav::CMS_MENU_SLUG ];

        expect( $parent['permission'] )->toBe( 'custom.bookings.gate' );

        foreach ( $parent['subItems'] as $child ) {
            expect( $child['permission'] )->toBe( 'custom.bookings.gate' )
                ->and( $child )->toHaveKeys( [ 'label', 'url', 'order', 'external' ] );
        }
    } );

    it( 'lets an existing menu entry win over ours when merging', function (): void {
        $existing = [
            AdminNav::CMS_MENU_SLUG => [ 'label' => 'Reservations' ],
        ];

        $merged = AdminNav::injectInto( $existing );

        // The host's label survives; the parts it did not set are filled in.
        expect( $merged[ AdminNav::CMS_MENU_SLUG ]['label'] )->toBe( 'Reservations' )
            ->and( $merged[ AdminNav::CMS_MENU_SLUG ] )->toHaveKey( 'subItems' );
    } );
} );

describe( 'the cms-framework menu subscription', function (): void {
    it( 'binds nothing while cms-framework is absent', function (): void {
        // cms-framework is a `suggest`, so it is genuinely absent here — the same
        // branch a standalone install takes, and the one that has to stay silent.
        expect( AdminNav::subscribeToCmsMenu() )->toBeFalse()
            ->and( applyFilters( 'ap.cmsFramework.admin.menu', [] ) )->toBe( [] );
    } );

    it( 'binds nothing while auto-registration is switched off', function (): void {
        config()->set( 'artisanpack.bookings.admin.auto_register_cms_nav', false );

        expect( AdminNav::subscribeToCmsMenu() )->toBeFalse();
    } );

    it( 'lands the bookings entries when cms-framework applies its menu filter', function (): void {
        // The subscription's own gate cannot be opened here without aliasing the
        // cms-framework probe class, and that alias is process-wide and
        // irreversible — it would make every later test in the run believe
        // cms-framework is installed, exactly the trap {@see CmsFrameworkChannelTest}
        // documents. So this drives the seam the way the framework itself does:
        // the callback the subscription binds, attached to the real hook and run
        // through a `ap.cmsFramework.admin.menu` filter standing in for
        // cms-framework's AdminMenuManager. The gate that guards the binding is
        // covered generically by HookSubscriptionsTest.
        addFilter(
            'ap.cmsFramework.admin.menu',
            static fn ( array $menu ): array => AdminNav::injectInto( $menu ),
        );

        $menu = applyFilters( 'ap.cmsFramework.admin.menu', [] );

        expect( $menu )->toHaveKey( AdminNav::CMS_MENU_SLUG );

        $parent = $menu[ AdminNav::CMS_MENU_SLUG ];

        expect( $parent['label'] )->toBe( __( 'Bookings' ) )
            ->and( $parent['subItems'] )->toHaveCount( count( AdminNav::items() ) )
            ->and( $parent['subItems']['bookings-settings']['label'] )->toBe( __( 'Settings' ) )
            ->and( $parent['subItems']['bookings-settings']['url'] )->toContain( 'bookings-admin/settings' );
    } );
} );
