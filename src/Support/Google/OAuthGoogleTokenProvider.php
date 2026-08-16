<?php

/**
 * Google OAuth-backed access token provider.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Support\Google;

use ArtisanPackUI\Bookings\Contracts\GoogleTokenProvider;
use ArtisanPackUI\Bookings\Exceptions\CalendarSyncException;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Google\Exceptions\TokenRefreshException;
use ArtisanPackUI\Google\Facades\Google;
use ArtisanPackUI\Google\Models\GoogleConnection;

/**
 * Resolves a connection's token through `artisanpack-ui/google`.
 *
 * The only implementation of {@see GoogleTokenProvider} that talks to real
 * OAuth, and the one place this package names a Google class. It is bound in the
 * container only when `artisanpack-ui/google` is installed, so nothing here is
 * loaded on an installation that lacks the package — the type hints on
 * {@see Google} and {@see GoogleConnection} are never reflected, and the missing
 * classes never matter.
 *
 * A connection's `oauth_connection_id` is the foreign key into Google's own
 * table, carried without a database constraint precisely because that table may
 * not exist. This maps that id to the Google connection and asks the token
 * manager for a currently-valid token, refreshing it if the stored one has
 * lapsed.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class OAuthGoogleTokenProvider implements GoogleTokenProvider
{
    /**
     * Gets a valid OAuth access token for a calendar connection.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection whose Google grant
     *                                          the token is drawn from.
     *
     * @throws CalendarSyncException When the connection carries no Google link,
     *                               the link no longer resolves to a stored
     *                               Google connection, or the grant cannot be
     *                               refreshed into a usable token.
     *
     * @return string The bearer token to authorise Google Calendar API calls.
     */
    public function accessTokenFor( CalendarConnection $connection ): string
    {
        $oauthConnectionId = $connection->oauth_connection_id;

        if ( null === $oauthConnectionId ) {
            throw new CalendarSyncException( sprintf(
                'Calendar connection %d has no Google OAuth connection to draw a token from.',
                $connection->getKey(),
            ) );
        }

        $googleConnection = GoogleConnection::query()->find( $oauthConnectionId );

        if ( ! $googleConnection instanceof GoogleConnection ) {
            throw new CalendarSyncException( sprintf(
                'Google OAuth connection %d for calendar connection %d no longer exists.',
                $oauthConnectionId,
                $connection->getKey(),
            ) );
        }

        // A revoked or unrefreshable grant surfaces as a TokenRefreshException.
        // Rethrow it as the sync exception the driver's callers already handle,
        // so a dead OAuth link is counted against the connection like any other
        // sync failure rather than escaping as a Google-package exception.
        try {
            return Google::tokens()->getValidAccessToken( $googleConnection );
        } catch ( TokenRefreshException $unrefreshable ) {
            throw new CalendarSyncException( sprintf(
                'Could not obtain a Google access token for calendar connection %d: %s',
                $connection->getKey(),
                $unrefreshable->getMessage(),
            ), 0, $unrefreshable );
        }
    }
}
