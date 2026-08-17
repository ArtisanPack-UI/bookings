<?php

/**
 * DNS host resolver.
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

use ArtisanPackUI\Bookings\Contracts\ResolvesHostAddresses;

/**
 * Resolves a hostname through the system resolver.
 *
 * Both families are asked for, and both are asked for separately: `gethostbyname`
 * answers with a single IPv4 address and nothing else, so a name behind an
 * AAAA-only record would come back looking unresolved to a v4-only lookup while
 * still pointing at a v6 loopback. The two are unioned so the guard sees every
 * address the name actually offers.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class DnsHostResolver implements ResolvesHostAddresses
{
    /**
     * Resolves a hostname to its IPv4 and IPv6 addresses.
     *
     * @since 1.0.0
     *
     * @param  string  $host  The hostname to resolve.
     *
     * @return array<int, string> The resolved addresses.
     */
    public function resolve( string $host ): array
    {
        $addresses = [];

        $ipv4 = @gethostbynamel( $host );

        if ( is_array( $ipv4 ) ) {
            $addresses = $ipv4;
        }

        $ipv6 = @dns_get_record( $host, DNS_AAAA );

        if ( is_array( $ipv6 ) ) {
            foreach ( $ipv6 as $record ) {
                if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values( array_unique( $addresses ) );
    }
}
