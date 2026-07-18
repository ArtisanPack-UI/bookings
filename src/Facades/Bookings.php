<?php

/**
 * Bookings Facade.
 *
 * Provides static access to the Bookings class.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Bookings Facade.
 *
 * @see \ArtisanPackUI\Bookings\Bookings
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class Bookings extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'bookings';
    }
}
