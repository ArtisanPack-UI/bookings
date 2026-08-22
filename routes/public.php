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
 * The iCal feeds sit under the same setting without the `api/` — `bookings/ical/…`
 * — because they are not part of that contract. Nothing calls them from a widget:
 * a person pastes one into Apple Calendar or Google Calendar and it is fetched by
 * software that expects a file at a URL, so the address is one somebody has to be
 * able to read out loud.
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
use ArtisanPackUI\Bookings\Http\Controllers\Public\IcalFeedController;
use ArtisanPackUI\Bookings\Http\Controllers\Public\ManageBookingController;
use ArtisanPackUI\Bookings\Http\Controllers\Public\ServiceController;
use Illuminate\Support\Facades\Route;

Route::prefix( 'api/' . trim( (string) config( 'artisanpack.bookings.public.route_prefix', 'bookings' ), '/' ) )
    ->name( 'artisanpack.bookings.api.' )
    ->group( static function (): void {
        // The unauthenticated reads are bounded per address by the `read` bucket:
        // slot resolution is the most expensive read in the package, and without
        // it a script could walk a provider's whole calendar unbounded.
        Route::get( 'services', [ ServiceController::class, 'index' ] )
            ->middleware( 'bookings.rate-limit:read' )
            ->name( 'services.index' );

        Route::get( 'services/{slug}/providers', [ ServiceController::class, 'providers' ] )
            ->middleware( 'bookings.rate-limit:read' )
            ->name( 'services.providers' );

        Route::get( 'services/{slug}/slots', [ ServiceController::class, 'slots' ] )
            ->middleware( 'bookings.rate-limit:read' )
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
        // Every one of these carries two limits — one per address, one per token
        // — because they bound different abuses: a machine grinding through
        // guesses, and a link that has escaped into the world being hit from
        // everywhere at once. The writes spend the `post` bucket per address and
        // the `manage_token` bucket per link, matching the read's stack.
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
                    ->middleware( [
                        'bookings.rate-limit:post',
                        'bookings.rate-limit:manage_token',
                        'bookings.manage-token',
                    ] )
                    ->name( 'cancel' );

                Route::post( 'reschedule', [ ManageBookingController::class, 'reschedule' ] )
                    ->middleware( [
                        'bookings.rate-limit:post',
                        'bookings.rate-limit:manage_token',
                        'bookings.manage-token',
                    ] )
                    ->name( 'reschedule' );
            } );
    } );

// The subscribable calendars. These are fetched on a timer nobody here controls
// — Google roughly hourly, Apple about every fifteen minutes, forever, once per
// subscriber — so the `ical` bucket is sized for machines rather than for people
// and the endpoints answer 304 before they read a booking.
//
// The `.ics` is part of the path rather than a query string or a content
// negotiation: clients guess how to treat a subscription URL from its extension,
// and one without it gets offered as a download or refused outright.
//
// The whole feed surface is gated on `calendar.ical_feed.enabled`: an
// installation that switches it off registers no iCal routes at all, so a feed
// URL 404s rather than being served — the documented off-switch, made real.
if ( (bool) config( 'artisanpack.bookings.calendar.ical_feed.enabled', true ) ) {
    Route::prefix( trim( (string) config( 'artisanpack.bookings.public.route_prefix', 'bookings' ), '/' ) . '/ical' )
        ->name( 'artisanpack.bookings.ical.' )
        ->group( static function (): void {
            // Addressed by a token rather than by the provider's slug. The slug is
            // published by `GET api/bookings/services/{slug}/providers`, so a slug
            // route is a diary anybody who can read the booking widget can
            // construct. Limited per address and per token for the reason the
            // customer feed is: one bucket bounds a machine walking the path, the
            // other bounds one leaked URL fetched from everywhere at once.
            Route::get( 'providers/{token}.ics', [ IcalFeedController::class, 'provider' ] )
                ->middleware( [ 'bookings.rate-limit:ical', 'bookings.rate-limit:ical_token' ] )
                ->where( 'token', '[0-9a-f]{64}' )
                ->name( 'provider' );

            // Limited twice for the same reason, and with the resolver declared
            // last: middleware runs in declaration order, so written the other way
            // round each guess would cost an indexed lookup before anything counted
            // how many guesses had been made.
            Route::get( 'customers/{token}.ics', [ IcalFeedController::class, 'customer' ] )
                ->middleware( [
                    'bookings.rate-limit:ical',
                    'bookings.rate-limit:manage_token',
                    'bookings.manage-token',
                ] )
                ->where( 'token', '[0-9a-f]{64}' )
                ->name( 'customer' );
        } );
}
