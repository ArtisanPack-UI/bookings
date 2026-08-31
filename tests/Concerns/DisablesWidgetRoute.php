<?php

/**
 * Widget-route-disabled test concern.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Concerns;

/**
 * Boots the package with the no-JS Blade widget route switched off.
 *
 * The `public.widget_enabled` seam is read while the provider mounts the public
 * surface during boot, so — like its admin counterpart — it has to be set in
 * the environment the application is built from rather than in the test body,
 * where the route would already exist.
 *
 * @since 1.0.0
 */
trait DisablesWidgetRoute
{
    /**
     * Switches the widget route off before the provider boots.
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

        $app['config']->set( 'artisanpack.bookings.public.widget_enabled', false );
    }
}
