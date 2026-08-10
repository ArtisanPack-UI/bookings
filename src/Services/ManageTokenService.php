<?php

/**
 * Manage token service.
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

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Scopes\BelongsToSiteScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

use function bin2hex;
use function doAction;
use function hash;
use function hash_equals;
use function max;
use function random_bytes;

/**
 * The one place a manage token is minted, hashed, and checked.
 *
 * A manage token is the customer's whole credential. There is no account behind
 * a public booking — the link in the confirmation email is what proves the
 * person cancelling is the person who booked — so the token is the only thing
 * standing between a stranger and somebody else's appointment. Three properties
 * follow from that, and all three live here rather than at each call site.
 *
 * **It is unguessable.** The token is 32 bytes from the CSPRNG rendered as 64
 * hexadecimal characters. Nothing about it is derived from the booking: not the
 * reference, not the email, not the time it was created. A token that encoded
 * any of those would let somebody who knows one booking enumerate the rest.
 *
 * **The database never holds the token itself.** `bookings.manage_token_hash`
 * stores `sha256(token)` and nothing else, so a leaked row — a backup, a
 * read-only replica, an admin API that forgot `$hidden` — hands over a hash that
 * cannot be turned back into a working link. There is deliberately no way to
 * recover the plain token from a saved booking: it exists in the confirmation
 * email and the manage URL, and it is returned exactly once, by whoever minted
 * it. A customer who loses the link gets a new one issued, not the old one back.
 *
 * A plain SHA-256 is the right primitive here and a password hash would not be.
 * The input is 256 bits of CSPRNG output rather than something a person chose,
 * so there is no dictionary to run and nothing for a work factor to slow down —
 * while the cost of one would be paid on a lookup that happens on every manage
 * request, and, worse, would rule out finding the booking by its hash at all.
 *
 * **Comparison is timing-safe.** {@see self::verify()} compares with
 * `hash_equals()`, never `==`. The index lookup in {@see self::findBooking()}
 * gets the candidate row; the constant-time compare is what decides whether the
 * token is right, so a caller cannot learn a hash prefix by measuring how long
 * a wrong token takes to be rejected.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ManageTokenService
{
    /**
     * The number of random bytes behind a token.
     *
     * Rendered as hex, so the token itself is twice this many characters — 64,
     * which is what the `char(64)` column and the widget URL are sized for.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const TOKEN_BYTES = 32;

    /**
     * How many bookings a reissue rotates per query by default.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const REISSUE_CHUNK_SIZE = 500;

    /**
     * Mints a token and returns the plain value alongside its hash.
     *
     * The plain token is returned here and nowhere else. Store the hash; put the
     * plain value in the confirmation email and the manage URL, and then forget
     * it.
     *
     * @since 1.0.0
     *
     * @return array{token: string, hash: string} The plain token and its hash.
     */
    public function mint(): array
    {
        $token = bin2hex( random_bytes( self::TOKEN_BYTES ) );

        return [
            'token' => $token,
            'hash'  => $this->hash( $token ),
        ];
    }

    /**
     * Hashes a plain token the way the column stores it.
     *
     * @since 1.0.0
     *
     * @param  string  $token  The plain manage token.
     *
     * @return string The SHA-256 hash of the token.
     */
    public function hash( string $token ): string
    {
        return hash( 'sha256', $token );
    }

    /**
     * Checks a plain token against a stored hash in constant time.
     *
     * @since 1.0.0
     *
     * @param  string  $token  The plain manage token presented by the caller.
     * @param  string  $hash  The hash held in `bookings.manage_token_hash`.
     *
     * @return bool True when the token is the one the hash was made from.
     */
    public function verify( string $token, string $hash ): bool
    {
        return hash_equals( $hash, $this->hash( $token ) );
    }

    /**
     * Checks a plain token against a booking's stored hash.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking the caller claims the token is for.
     * @param  string  $token  The plain manage token presented by the caller.
     *
     * @return bool True when the token manages this booking.
     */
    public function verifyFor( Booking $booking, string $token ): bool
    {
        return $this->verify( $token, (string) $booking->manage_token_hash );
    }

    /**
     * Finds the booking a plain token manages.
     *
     * The lookup is an indexed match on the hash — a table scan comparing every
     * row in constant time would be timing-safe and unusable — and the row it
     * returns is then confirmed with `hash_equals()` before it is handed back.
     *
     * @since 1.0.0
     *
     * @param  string  $token  The plain manage token from the URL or email.
     *
     * @return Booking|null The matching booking, or null when the token is unknown.
     */
    public function findBooking( string $token ): ?Booking
    {
        $booking = Booking::query()->where( 'manage_token_hash', $this->hash( $token ) )->first();

        if ( null === $booking ) {
            return null;
        }

        return $this->verifyFor( $booking, $token ) ? $booking : null;
    }

    /**
     * Issues a fresh token for one booking and returns the plain value.
     *
     * The previous token stops working the moment this returns, so whoever calls
     * it owns getting the new link to the customer.
     *
     * Only the hash column is written. A `save()` here would flush whatever else
     * the caller happens to have changed on the instance — a half-filled admin
     * form, a status the caller had not decided to persist yet — and "the manage
     * link was reissued" is not a sentence anybody expects to mean "and the rest
     * of your edits went live with it".
     *
     * The write is aimed at the booking by primary key with no scopes applied,
     * so reissuing works on a booking the caller already holds whichever site is
     * ambient, and on a soft-deleted one. Nothing crosses a tenant boundary that
     * the caller had not already crossed to get the model.
     *
     * A booking that has not been saved yet is given its hash in memory and
     * nothing is written: the `creating` hook on the model keeps a hash that is
     * already set, so the token the caller is holding is the one that lands.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to reissue a token for.
     *
     * @return string The new plain manage token.
     */
    public function issueFor( Booking $booking ): string
    {
        $minted = $this->mint();

        if ( $booking->exists ) {
            $booking->newQueryWithoutScopes()
                ->whereKey( $booking->getKey() )
                ->toBase()
                ->update( [ 'manage_token_hash' => $minted['hash'] ] );
        }

        // Only this attribute is synced, not the whole model: syncOriginal()
        // would tell an instance carrying the caller's other pending edits that
        // they had already been written, and the save that was meant to persist
        // them would find nothing dirty and do nothing.
        $booking->manage_token_hash = $minted['hash'];
        $booking->syncOriginalAttribute( 'manage_token_hash' );

        return $minted['token'];
    }

    /**
     * Rotates the manage token of every booking.
     *
     * This is the emergency lever: it invalidates every manage link the package
     * has ever sent, whichever site the booking belongs to. Sites are not a
     * boundary here — a token leak is not one either — so the site scope is
     * lifted rather than honoured.
     *
     * Rows are updated through the base query builder, which keeps two things
     * out of the way that matter at this scale. `updated_at` is left alone, so a
     * rotation does not read as every booking in the system having been edited;
     * and no model events fire, so the availability cache is not invalidated
     * once per booking for a change that cannot affect availability.
     *
     * Each rotated booking fires `ap.bookings.manageTokenReissued` with its new
     * plain token, which is the only opportunity anybody gets to see it — an
     * application that wants to re-send the manage links has to listen there.
     * When it finishes, `ap.bookings.manageTokensReissued` fires once with the
     * total.
     *
     * @since 1.0.0
     *
     * @param  int|null  $chunkSize  How many bookings to rotate per query.
     *
     * @return int The number of bookings whose token was rotated.
     */
    public function reissueAll( ?int $chunkSize = null ): int
    {
        $chunkSize = max( 1, $chunkSize ?? self::REISSUE_CHUNK_SIZE );
        $rotated   = 0;

        // Chunking by id rather than by page: the rows being paged over are the
        // rows being rewritten, and an ordinary offset walk over a moving target
        // skips some of them. Nothing may be missed here — a booking left on its
        // old token is the one link the rotation was called to kill.
        $this->query()->chunkById( $chunkSize, function ( Collection $bookings ) use ( &$rotated ): void {
            foreach ( $bookings as $booking ) {
                $minted = $this->mint();

                $this->query()->whereKey( $booking->getKey() )->toBase()->update( [
                    'manage_token_hash' => $minted['hash'],
                ] );

                $booking->manage_token_hash = $minted['hash'];
                $booking->syncOriginalAttribute( 'manage_token_hash' );

                doAction( 'ap.bookings.manageTokenReissued', $booking, $minted['token'] );

                $rotated++;
            }
        } );

        doAction( 'ap.bookings.manageTokensReissued', $rotated );

        return $rotated;
    }

    /**
     * Builds the query a reissue runs over.
     *
     * @since 1.0.0
     *
     * @return Builder<Booking> The unscoped booking query.
     */
    protected function query(): Builder
    {
        return Booking::query()->withoutGlobalScope( BelongsToSiteScope::class );
    }
}
