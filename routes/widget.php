<?php

/**
 * Public booking widget routes.
 *
 * The one endpoint the widget needs that the JSON API cannot provide: a
 * session-backed, CSRF-protected form target that answers with a redirect
 * rather than JSON, so the booking flow completes for a visitor whose browser
 * never ran Livewire's bundle.
 *
 * Mounted in the `web` group rather than the `api` one for exactly that reason.
 * A browser posting a plain form has a session and a CSRF token and nowhere to
 * put a `201` — which is the mirror image of the widget-on-a-static-page case
 * `routes/public.php` is built for, and why the two are separate files rather
 * than one group with two middleware stacks.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Http\Controllers\Public\WidgetController;
use Illuminate\Support\Facades\Route;

Route::prefix( trim( (string) config( 'artisanpack.bookings.public.route_prefix', 'bookings' ), '/' ) )
    ->name( 'artisanpack.bookings.widget.' )
    ->group( static function (): void {
        Route::post( 'widget', [ WidgetController::class, 'store' ] )
            ->middleware( 'bookings.rate-limit:post' )
            ->name( 'store' );
    } );
