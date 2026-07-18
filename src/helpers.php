<?php

/**
 * Bookings helper functions.
 *
 * This file contains global helper functions for the Bookings package.
 * Add your custom helper functions below.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

use ArtisanPackUI\Bookings\Bookings;

if ( ! function_exists( 'bookings' ) ) {
    /**
     * Get the Bookings instance.
     *
     * @since 1.0.0
     *
     * @return Bookings
     */
    function bookings(): Bookings
    {
        return app( 'bookings' );
    }
}

// Add your custom helper functions below
