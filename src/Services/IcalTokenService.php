<?php

/**
 * iCal feed token service.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Services;

use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\Concerns\MintsOpaqueTokens;
use Illuminate\Database\Eloquent\Builder;

use function doAction;
use function route;

/**
 * The one place a provider's calendar feed token is minted, hashed, and checked.
 *
 * A feed token is what makes `bookings/ical/providers/{token}.ics` addressable
 * by the provider it belongs to and by nobody else. The route was a slug once,
 * and a slug is published by the public providers endpoint — so the URL was
 * constructible by anybody who could read the booking widget, and a provider's
 * whole diary with it. The token is the fix, and it works only for as long as it
 * is genuinely unguessable, which is what {@see MintsOpaqueTokens} guarantees:
 * 32 CSPRNG bytes as 64 hex characters, `sha256` in the column, `hash_equals()`
 * on the way back.
 *
 * **A provider has no feed until somebody mints one.** Nothing auto-mints on
 * create, and a null `ical_token_hash` is a 404 rather than an open feed. That
 * is not only a safe default — with the hash being all that is stored, minting
 * on create would throw the plain token away unread and leave the provider with
 * a credential nobody can ever recover.
 *
 * **A lost token is replaced, not recovered.** The plain value is returned once,
 * by {@see self::issueFor()}, and there is deliberately no way back to it from a
 * saved row. The URL itself is not single-use — it can be pasted into as many
 * calendar clients as the provider owns, and they all go on working — but nobody
 * can ever read it off a screen again, so whoever mints it has to keep it or
 * rotate. The alternative is a plain token in a column, where a leaked backup is
 * a working subscription to somebody's diary.
 *
 * Rotating is therefore for a URL that has been lost or exposed, not for adding a
 * device. It is not free: every client subscribed to the previous URL stops
 * updating the moment {@see self::issueFor()} returns, and none of them says so.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class IcalTokenService
{
    use MintsOpaqueTokens;

    /**
     * Finds the provider a plain feed token belongs to.
     *
     * The lookup is an indexed match on the hash — a table scan comparing every
     * row in constant time would be timing-safe and unusable — and the row it
     * returns is confirmed with `hash_equals()` before it is handed back.
     *
     * Providers who have never been issued a token are excluded outright. The
     * hash of a presented token can never be null, so they could not match
     * anyway; the condition is there to say so at the query rather than to rely
     * on that holding for every engine and every future caller.
     *
     * @since 1.0.0
     *
     * @param  string  $token  The plain feed token from the URL.
     *
     * @return ServiceProvider|null The matching provider, or null when the token
     *                              is unknown.
     */
    public function findProvider( string $token ): ?ServiceProvider
    {
        $provider = $this->query()
            ->whereNotNull( 'ical_token_hash' )
            ->where( 'ical_token_hash', $this->hash( $token ) )
            ->first();

        if ( ! $provider instanceof ServiceProvider ) {
            return null;
        }

        return $this->verifyFor( $provider, $token ) ? $provider : null;
    }

    /**
     * Checks a plain token against a provider's stored hash.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider the token is claimed for.
     * @param  string  $token  The plain feed token presented by the caller.
     *
     * @return bool True when the token addresses this provider's feed.
     */
    public function verifyFor( ServiceProvider $provider, string $token ): bool
    {
        return null !== $provider->ical_token_hash
            && $this->verify( $token, $provider->ical_token_hash );
    }

    /**
     * Issues a fresh feed token for one provider and returns the plain value.
     *
     * Whatever token the provider had stops working the moment this returns, so
     * whoever calls it owns getting the new URL to them — every calendar client
     * already subscribed to the old one will simply stop updating, quietly, the
     * way a subscribed feed does when it starts 404ing.
     *
     * Only the hash column is written, for the reason
     * {@see ManageTokenService::issueFor()} writes only its own: a `save()` here
     * would flush whatever else the caller happens to have changed on the
     * instance, and "the feed URL was reissued" is not a sentence anybody
     * expects to mean "and the rest of your edits went live with it".
     *
     * The write is aimed at the provider by primary key with no scopes applied,
     * so reissuing works on a provider the caller already holds whichever site
     * is ambient, and on a soft-deleted one. Nothing crosses a tenant boundary
     * the caller had not already crossed to get the model.
     *
     * `ap.bookings.icalTokenIssued` fires with the new plain token, which is the
     * only moment anybody other than this caller can read it.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider to issue a token for.
     *
     * @return string The new plain feed token.
     */
    public function issueFor( ServiceProvider $provider ): string
    {
        $minted = $this->mint();

        if ( $provider->exists ) {
            $provider->newQueryWithoutScopes()
                ->whereKey( $provider->getKey() )
                ->toBase()
                ->update( [ 'ical_token_hash' => $minted['hash'] ] );
        }

        // Only this attribute is synced, not the whole model: syncOriginal()
        // would tell an instance carrying the caller's other pending edits that
        // they had already been written, and the save that was meant to persist
        // them would find nothing dirty and do nothing.
        $provider->ical_token_hash = $minted['hash'];
        $provider->syncOriginalAttribute( 'ical_token_hash' );

        doAction( 'ap.bookings.icalTokenIssued', $provider, $minted['token'] );

        return $minted['token'];
    }

    /**
     * Issues a token only while the provider still holds the one the caller saw.
     *
     * The unconditional {@see self::issueFor()} is a read followed by a write,
     * and a rotation is decided in between: the command reads the provider to
     * find out whether there is anything to warn about, waits for a human to
     * answer a prompt, and only then writes. Anything that issues a token in that
     * gap — a second operator, a queued job, an admin screen — has its token
     * overwritten by a caller who never saw it, and the operator holding *that*
     * URL walks away and sends a provider a subscription that is already dead.
     *
     * The database decides instead. The update carries the hash the caller
     * inspected as a condition, so exactly one of two concurrent rotations
     * writes; the loser gets null back and can go and look again. This is the
     * idiom `BookingService::complete()` and `Webhook::disable()` already use for
     * the same reason.
     *
     * `$expected` of null means "the caller saw no feed", which is a condition
     * too — it is how a first issue refuses to silently clobber a token that
     * appeared while a prompt was open.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider to issue a token for.
     * @param  string|null  $expected  The hash the caller inspected, or null when
     *                                 they saw no feed at all.
     *
     * @return string|null The new plain token, or null when the row moved and
     *                     nothing was written.
     */
    public function issueIfUnchanged( ServiceProvider $provider, ?string $expected ): ?string
    {
        if ( ! $provider->exists ) {
            return $this->issueFor( $provider );
        }

        $minted = $this->mint();

        $claimed = $provider->newQueryWithoutScopes()
            ->whereKey( $provider->getKey() )
            ->where( function ( Builder $query ) use ( $expected ): void {
                null === $expected
                    ? $query->whereNull( 'ical_token_hash' )
                    : $query->where( 'ical_token_hash', $expected );
            } )
            ->toBase()
            ->update( [ 'ical_token_hash' => $minted['hash'] ] );

        if ( $claimed < 1 ) {
            return null;
        }

        $provider->ical_token_hash = $minted['hash'];
        $provider->syncOriginalAttribute( 'ical_token_hash' );

        doAction( 'ap.bookings.icalTokenIssued', $provider, $minted['token'] );

        return $minted['token'];
    }

    /**
     * Withdraws a provider's feed entirely.
     *
     * The feed 404s from here on and no new token is minted, which is the answer
     * when a provider leaves rather than when their URL has leaked.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider to withdraw the feed from.
     *
     * @return void
     */
    public function revokeFor( ServiceProvider $provider ): void
    {
        if ( $provider->exists ) {
            $provider->newQueryWithoutScopes()
                ->whereKey( $provider->getKey() )
                ->toBase()
                ->update( [ 'ical_token_hash' => null ] );
        }

        $provider->ical_token_hash = null;
        $provider->syncOriginalAttribute( 'ical_token_hash' );

        doAction( 'ap.bookings.icalTokenRevoked', $provider );
    }

    /**
     * Determines whether a provider has a feed at all.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider to check.
     *
     * @return bool True when a token has been issued and not revoked.
     */
    public function hasFeed( ServiceProvider $provider ): bool
    {
        return null !== $provider->ical_token_hash;
    }

    /**
     * Builds the subscription URL for a plain token.
     *
     * Takes the token rather than the provider, because the provider does not
     * know it — only the caller who just minted it does, and only until they let
     * go of it.
     *
     * @since 1.0.0
     *
     * @param  string  $token  The plain feed token.
     *
     * @return string The absolute URL to hand to a calendar client.
     */
    public function feedUrl( string $token ): string
    {
        return route( 'artisanpack.bookings.ical.provider', [ 'token' => $token ] );
    }

    /**
     * Builds the query a token lookup runs over.
     *
     * Inactive providers are excluded here rather than at the controller, so a
     * token cannot be the one thing that reaches a diary the rest of the public
     * surface has stopped serving. Site scoping is left in place: a token issued
     * for one tenant's provider resolves to nothing while another tenant's site
     * is ambient, which is the same answer every other public lookup gives.
     *
     * @since 1.0.0
     *
     * @return Builder<ServiceProvider> The scoped query.
     */
    protected function query(): Builder
    {
        return ServiceProvider::query()->active();
    }
}
