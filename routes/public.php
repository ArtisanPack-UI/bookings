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
use ArtisanPackUI\Bookings\Http\Controllers\Public\ManageBookingController;
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

        // The self-serve surface. The token in the path is the customer's whole
        // credential, so every one of these is limited before it is resolved:
        // the rate limiters run first and cost a cache read, while resolving the
        // token costs an indexed lookup that an unguarded route would happily
        // pay for anybody walking the path.
        //
        // Reads carry two limits — one per address, one per token — because they
        // bound different abuses: a machine grinding through guesses, and a link
        // that has escaped into the world being fetched from everywhere at once.
        //
        // The limiters are listed before the resolver on every route, and the
        // order is load-bearing: middleware runs in the order it is declared, so
        // written the other way round each guess would cost a database lookup
        // before anything counted how many guesses had been made.
        Route::prefix( 'manage/{token}' )
            ->name( 'manage.' )
            ->group( static function (): void {
                Route::get( '/', [ ManageBookingController::class, 'show' ] )
                    ->middleware( [
                        'bookings.rate-limit:manage_get',
                        'bookings.rate-limit:manage_token',
                        'bookings.manage-token',
                    ] )
                    ->name( 'show' );

                Route::post( 'cancel', [ ManageBookingController::class, 'cancel' ] )
                    ->middleware( [ 'bookings.rate-limit:post', 'bookings.manage-token' ] )
                    ->name( 'cancel' );

                Route::post( 'reschedule', [ ManageBookingController::class, 'reschedule' ] )
                    ->middleware( [ 'bookings.rate-limit:post', 'bookings.manage-token' ] )
                    ->name( 'reschedule' );
            } );
    } );
