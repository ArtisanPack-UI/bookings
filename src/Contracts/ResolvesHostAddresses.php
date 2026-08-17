<?php

/**
 * Host address resolver contract.
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

/**
 * Turns a hostname into the addresses it currently resolves to.
 *
 * Split out from {@see \ArtisanPackUI\Bookings\Services\WebhookUrlGuard} so the
 * guard's range checks can be exercised without a working nameserver, and so an
 * installation whose resolution needs to go somewhere other than the system
 * resolver — a split-horizon setup, a test double — can rebind this one
 * interface rather than reach inside the guard.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface ResolvesHostAddresses
{
    /**
     * Resolves a hostname to its IP addresses.
     *
     * Returns every address the name maps to, IPv4 and IPv6 alike, because the
     * guard has to clear all of them: a name that answers with one public
     * address and one loopback address is still a way into the loopback.
     *
     * @since 1.0.0
     *
     * @param  string  $host  The hostname to resolve.
     *
     * @return array<int, string> The resolved addresses, or an empty array when
     *                            the name does not resolve.
     */
    public function resolve( string $host ): array;
}
