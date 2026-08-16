<?php

/**
 * Bookings admin authorization middleware.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Http\Middleware;

use ArtisanPackUI\Bookings\Support\AdminNav;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one gate the staff-facing screens sit behind.
 *
 * The package deliberately does not define the ability. An installation running
 * cms-framework already has roles and permissions, and the natural thing there
 * is a policy mapping `bookings.manage` onto one of them; a standalone
 * installation defines the gate against whatever "staff" means to it. Either
 * way the decision is the application's, and shipping a permissive default would
 * override a choice the host is better placed to make.
 *
 * That leaves the secure failure as the default: `Gate::authorize()` against an
 * ability nobody has defined denies, so an application that mounts these routes
 * without wiring the gate gets a 403 rather than an open admin. The ability name
 * is read from `artisanpack.bookings.admin.gate` through {@see AdminNav::gate()},
 * the same source the navigation gates its entries with, so what a user is shown
 * and what a user may reach cannot drift apart.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class AuthorizeBookingsAdmin
{
    /**
     * Authorizes the request against the configured admin gate.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure  $next  The next middleware.
     *
     * @return Response The response.
     */
    public function handle( Request $request, Closure $next ): Response
    {
        Gate::authorize( AdminNav::gate() );

        return $next( $request );
    }
}
