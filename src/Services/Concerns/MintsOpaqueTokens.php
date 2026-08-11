<?php

/**
 * Opaque token primitives.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Services\Concerns;

use function bin2hex;
use function hash;
use function hash_equals;
use function random_bytes;

/**
 * How this package mints, stores, and checks a credential nobody logs in with.
 *
 * Two things in the package are addressed by a token rather than by a session: a
 * customer's manage link, and a provider's calendar feed. Neither has an account
 * behind it, so in both cases the token *is* the credential, and the same three
 * properties have to hold. They live here rather than in each service so the two
 * cannot drift apart — the failure that shape of duplication produces is one of
 * them quietly ending up with a weaker hash or a `===` where the other has
 * `hash_equals()`, in a class nobody rereads because the other one is fine.
 *
 * **A token is unguessable.** 32 bytes from the CSPRNG rendered as 64
 * hexadecimal characters. Nothing about it is derived from the thing it points
 * at — not a name, not an email, not the time it was created — because a token
 * that encoded any of those would let somebody who holds one work out the rest.
 *
 * **The database holds only the hash.** `sha256(token)` and nothing else, so a
 * leaked row — a backup, a read-only replica, an admin API that forgot
 * `$hidden` — hands over something that cannot be turned back into a working
 * credential. The plain value is returned exactly once, by whoever minted it.
 *
 * A plain SHA-256 is the right primitive here and a password hash would not be.
 * The input is 256 bits of CSPRNG output rather than something a person chose,
 * so there is no dictionary to run and nothing for a work factor to slow down —
 * while the cost of one would be paid on a lookup that happens on every request,
 * and, worse, would rule out finding the row by its hash at all.
 *
 * **Comparison is timing-safe.** {@see self::verify()} uses `hash_equals()`,
 * never `==` or `===`. The indexed lookup finds the candidate row; this is what
 * decides whether the token is right, so a caller cannot learn a hash prefix by
 * measuring how long a wrong token takes to be rejected.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
trait MintsOpaqueTokens
{
    /**
     * The number of random bytes behind a token.
     *
     * Rendered as hex, so a token is twice this many characters — 64, which is
     * what the `char(64)` columns and the URLs are sized for.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const TOKEN_BYTES = 32;

    /**
     * Mints a token and returns the plain value alongside its hash.
     *
     * The plain token is returned here and nowhere else. Store the hash; put the
     * plain value wherever it has to go, and then forget it.
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
     * @param  string  $token  The plain token.
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
     * @param  string  $token  The plain token presented by the caller.
     * @param  string  $hash  The hash held in the column.
     *
     * @return bool True when the token is the one the hash was made from.
     */
    public function verify( string $token, string $hash ): bool
    {
        return hash_equals( $hash, $this->hash( $token ) );
    }
}
