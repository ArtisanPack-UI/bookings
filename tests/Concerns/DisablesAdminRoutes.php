<?php

/**
 * Admin-routes-disabled test concern.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Concerns;

/**
 * Boots the package with the staff-facing admin routes switched off.
 *
 * The `admin.routes_enabled` seam is read once, when the provider mounts its
 * routes during boot, so a test cannot set it after the fact — the routes are
 * already registered by the time its body runs. Setting it here, in the
 * environment the application is built from, is what lets a test assert the
 * screens never registered rather than that they registered and were hidden.
 *
 * @since 1.0.0
 */
trait DisablesAdminRoutes
{
    /**
     * Switches the admin routes off before the provider boots.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return void
     */
    protected function defineEnvironment( $app ): void
    {
        parent::defineEnvironment( $app );

        $app['config']->set( 'artisanpack.bookings.admin.routes_enabled', false );
    }
}
