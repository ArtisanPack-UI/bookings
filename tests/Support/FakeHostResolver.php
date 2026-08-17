<?php

/**
 * Fake host resolver.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Support;

use ArtisanPackUI\Bookings\Contracts\ResolvesHostAddresses;

/**
 * Resolves hostnames from a fixed map rather than from a nameserver.
 *
 * The webhook URL guard's range checks are the point of the guard, and pinning
 * a real name to a real address to exercise them would be a flaky test and a
 * network round-trip in a suite that should have neither. Named hosts resolve to
 * whatever the map says; everything else resolves to a public address, so the
 * bulk of the delivery suite — which does not care where a name points, only
 * that it points somewhere allowed — is unaffected by the guard being on.
 *
 * @since 1.0.0
 */
class FakeHostResolver implements ResolvesHostAddresses
{
    /**
     * Constructs the resolver.
     *
     * @since 1.0.0
     *
     * @param  array<string, array<int, string>>  $map  Host-to-addresses overrides.
     * @param  array<int, string>  $default  What an unmapped host resolves to.
     */
    public function __construct(
        protected array $map = [],
        protected array $default = [ '93.184.216.34' ],
    ) {
    }

    /**
     * Resolves a hostname from the map.
     *
     * @since 1.0.0
     *
     * @param  string  $host  The hostname to resolve.
     *
     * @return array<int, string> The mapped addresses, or the default.
     */
    public function resolve( string $host ): array
    {
        return $this->map[ strtolower( $host ) ] ?? $this->default;
    }
}
