<?php

/**
 * Public booking routes.
 *
 * The customer-facing contract. Everything here is reachable without
 * credentials, which is the whole point — a booking widget embedded on a
 * marketing page has no session to speak of — and is also why the one write is
 * rate limited and every input goes through the package's sanitizers.
 *
 * The prefix is `api/` plus `artisanpack.bookings.public.route_prefix`, so the
 * shipped defaults produce `api/bookings/...` and an installation that already
 * owns that path can move the whole surface with one setting.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Http\Controllers\Public\BookingController;
use ArtisanPackUI\Bookings\Http\Controllers\Public\ServiceController;
use Illuminate\Support\Facades\Route;

Route::prefix( 'api/' . trim( (string) config( 'artisanpack.bookings.public.route_prefix', 'bookings' ), '/' ) )
    ->name( 'artisanpack.bookings.api.' )
    ->group( static function (): void {
        Route::get( 'services', [ ServiceController::class, 'index' ] )
            ->name( 'services.index' );

        Route::get( 'services/{slug}/providers', [ ServiceController::class, 'providers' ] )
            ->name( 'services.providers' );

        Route::get( 'services/{slug}/slots', [ ServiceController::class, 'slots' ] )
            ->name( 'services.slots' );

        Route::post( '/', [ BookingController::class, 'store' ] )
            ->middleware( 'bookings.rate-limit:post' )
            ->name( 'bookings.store' );
    } );
