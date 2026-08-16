<?php

/**
 * Google access token provider contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Contracts;

use ArtisanPackUI\Bookings\Models\CalendarConnection;
use Throwable;

/**
 * Hands the Google calendar driver a usable access token for a connection.
 *
 * This is the one seam between the driver, which ships here, and the OAuth it
 * runs on, which does not: token storage, refresh, and scope management all live
 * in `artisanpack-ui/google`, a package this one only suggests. The driver asks
 * this contract for a token and never learns how it was obtained, so the package
 * has no compile-time dependency on Google's OAuth classes — an installation
 * without `artisanpack-ui/google` simply has nothing bound here and never
 * resolves the Google driver.
 *
 * The token must be current. A connection's OAuth grant expires and is refreshed
 * behind the scenes; returning a stale token would make every call the driver
 * makes fail as unauthorized, so an implementation refreshes before it answers.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface GoogleTokenProvider
{
    /**
     * Gets a valid OAuth access token for a calendar connection.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection whose Google grant
     *                                          the token is drawn from, via its
     *                                          `oauth_connection_id`.
     *
     * @throws Throwable When no token can be produced — the OAuth link is
     *                   missing, revoked, or cannot be refreshed.
     *
     * @return string The bearer token to authorise Google Calendar API calls.
     */
    public function accessTokenFor( CalendarConnection $connection ): string;
}
