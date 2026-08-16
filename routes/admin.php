<?php

/**
 * Staff-facing admin routes.
 *
 * The screens from plan §6.2. Each mounts the Livewire admin components inside a
 * page whose layout is chosen at render time: cms-framework's admin shell when
 * that package is installed, the package's own layout when it is not. The routes
 * themselves do not change between the two — only the chrome they render inside —
 * so a navigation entry, a bookmark, or a `route()` call resolves identically in
 * both worlds.
 *
 * Mounted in the `web` group behind the `bookings.admin` gate. These are
 * server-rendered pages with a session and a logged-in operator, which is the
 * opposite of the stateless `api` surface the customer-facing endpoints live on.
 *
 * The actions are controller methods rather than closures so the whole file
 * survives `php artisan route:cache`, which cannot serialize a Closure — the
 * same reason `routes/public.php` and `routes/widget.php` name controllers too.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Http\Controllers\Admin\AdminScreenController;
use Illuminate\Support\Facades\Route;

Route::prefix( trim( (string) config( 'artisanpack.bookings.admin.route_prefix', 'bookings-admin' ), '/' ) )
    ->middleware( [ 'web', 'bookings.admin' ] )
    ->name( 'artisanpack.bookings.admin.' )
    ->group( static function (): void {
        Route::get( 'bookings', [ AdminScreenController::class, 'bookings' ] )->name( 'bookings' );

        Route::get( 'bookings/create', [ AdminScreenController::class, 'createBooking' ] )
            ->name( 'bookings.create' );

        Route::get( 'bookings/{booking}', [ AdminScreenController::class, 'showBooking' ] )
            ->whereNumber( 'booking' )
            ->name( 'bookings.show' );

        Route::get( 'calendar', [ AdminScreenController::class, 'calendar' ] )->name( 'calendar' );

        Route::get( 'services', [ AdminScreenController::class, 'services' ] )->name( 'services' );

        Route::get( 'services/{service}/intake-schema', [ AdminScreenController::class, 'intakeSchema' ] )
            ->whereNumber( 'service' )
            ->name( 'services.intake-schema' );

        Route::get( 'providers', [ AdminScreenController::class, 'providers' ] )->name( 'providers' );

        Route::get( 'blackout-dates', [ AdminScreenController::class, 'blackoutDates' ] )
            ->name( 'blackout-dates' );

        Route::get( 'series', [ AdminScreenController::class, 'series' ] )->name( 'series' );

        Route::get( 'calendar-connections', [ AdminScreenController::class, 'calendarConnections' ] )
            ->name( 'calendar-connections' );

        Route::get( 'webhooks', [ AdminScreenController::class, 'webhooks' ] )->name( 'webhooks' );

        Route::get( 'notifications', [ AdminScreenController::class, 'notifications' ] )
            ->name( 'notifications' );

        Route::get( 'settings', [ AdminScreenController::class, 'settings' ] )->name( 'settings' );
    } );
