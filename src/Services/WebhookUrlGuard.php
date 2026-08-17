<?php

/**
 * Webhook URL guard.
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
 * Decides whether a webhook URL is safe to deliver to.
 *
 * A webhook URL is an address somebody typed into a screen, and this process can
 * reach hosts the person who typed it cannot — an internal service on the private
 * network, a cloud metadata endpoint holding instance credentials. The reply is
 * written into the delivery ledger where an admin screen reads it back, so an
 * unvalidated URL is a request-forgery primitive with an exfiltration channel
 * attached: point an endpoint at `http://169.254.169.254/…` and read the answer
 * out of the ledger.
 *
 * The guard is consulted in two places, and both matter:
 *
 * - When an endpoint is saved, through {@see \ArtisanPackUI\Bookings\Rules\ValidWebhookUrl},
 *   so a bad address is refused before it is stored.
 * - Again at delivery time, in {@see WebhookDispatcher::deliver()}, because a
 *   name approved once can be repointed afterwards. That is the whole
 *   DNS-rebinding case, and a check that only runs on the admin form never sees
 *   it — the name resolved to a public address when it was reviewed and to
 *   loopback by the time it is called.
 *
 * ## What is refused
 *
 * A URL whose scheme is not on the allowlist (`https` only by default, with
 * `http` addable for local development), or whose host resolves to any loopback,
 * private, link-local, or unique-local address. Resolution is checked against
 * *every* address a name answers with, since a name offering one public and one
 * loopback address is still a way into the loopback.
 *
 * ## What an operator controls
 *
 * The default blocks the private ranges outright, which is wrong for the
 * single-tenant installation that delivers to `http://internal-crm.local/hooks`
 * on purpose. `allowed_hosts` names the hosts a given installation does consider
 * legitimate — they skip the range check entirely — and `blocked_hosts` names
 * ones to refuse whatever they resolve to. The whole guard is switchable off for
 * an installation where every endpoint is operator-created and the range check
 * is only in the way.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class WebhookUrlGuard
{
    /**
     * The IPv4 ranges a resolved address is refused for landing in.
     *
     * Loopback, the three private blocks, link-local (which is where the cloud
     * metadata endpoint lives), the carrier-grade NAT space, and the reserved,
     * documentation, benchmark, and multicast ranges an outbound webhook has no
     * business reaching.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const BLOCKED_IPV4_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    /**
     * The IPv6 ranges a resolved address is refused for landing in.
     *
     * Loopback and the unspecified address, unique-local (`fc00::/7`, the v6
     * private range), link-local, multicast, and the documentation block.
     * `::/96` covers the deprecated IPv4-compatible embeddings (`::a.b.c.d`),
     * which are not routed to the embedded v4 by a modern stack but are refused
     * here rather than trusted to that. IPv4-*mapped* addresses (`::ffff:a.b.c.d`,
     * which a stack does route) are unpacked to their v4 form before this list is
     * consulted, so a mapped loopback is caught by the v4 ranges instead.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const BLOCKED_IPV6_RANGES = [
        '::1/128',
        '::/128',
        '::/96',
        '64:ff9b::/96',
        '100::/64',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
        '2001:db8::/32',
    ];

    /**
     * Constructs the guard.
     *
     * @since 1.0.0
     *
     * @param  ResolvesHostAddresses  $resolver  Turns a hostname into addresses.
     */
    public function __construct( protected ResolvesHostAddresses $resolver )
    {
    }

    /**
     * Determines whether a URL is safe to deliver to.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL to inspect.
     *
     * @return bool True when nothing about the URL refuses it.
     */
    public function allows( string $url ): bool
    {
        return $this->decide( $url )->allowed;
    }

    /**
     * Inspects a URL and returns why it is refused, or null when it is allowed.
     *
     * The reason is a sentence fit to record on a delivery row or surface as a
     * validation message; the host name and scheme are never interpolated into
     * it, so a refused URL cannot smuggle markup back out through the ledger the
     * message is read in.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL to inspect.
     *
     * @return string|null The reason it is refused, or null when it is allowed.
     */
    public function inspect( string $url ): ?string
    {
        return $this->decide( $url )->reason;
    }

    /**
     * Decides a URL, resolving its host at most once.
     *
     * The verdict and the addresses that produced it come back together on
     * purpose. A delivery that vetted a name and then let the HTTP client
     * resolve it again would be checking one set of addresses and connecting to
     * another — the DNS-rebinding hole this guard exists to close — so the
     * decision carries the vetted addresses for the connection to pin, and
     * {@see WebhookDispatcher::send()} pins them. A name is resolved here and
     * nowhere else.
     *
     * The addresses are not pinned when there is nothing to pin or nothing to
     * gain: the guard is off, the host is on the allow list (the operator has
     * taken responsibility for where it points), or the host is an IP literal
     * the client cannot resolve elsewhere anyway.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL to decide.
     *
     * @return WebhookUrlDecision The verdict and any addresses to pin.
     */
    public function decide( string $url ): WebhookUrlDecision
    {
        if ( ! $this->enabled() ) {
            return WebhookUrlDecision::allowUnpinned();
        }

        $parts = parse_url( $url );

        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
            return WebhookUrlDecision::refuse( __( 'The webhook URL is not a valid absolute URL.' ) );
        }

        $scheme = strtolower( $parts['scheme'] );

        if ( ! in_array( $scheme, $this->allowedSchemes(), true ) ) {
            return WebhookUrlDecision::refuse( __( 'The webhook URL scheme is not allowed.' ) );
        }

        $host = $this->normaliseHost( $parts['host'] );

        if ( '' === $host ) {
            return WebhookUrlDecision::refuse( __( 'The webhook URL is not a valid absolute URL.' ) );
        }

        if ( $this->hostIs( $host, $this->blockedHosts() ) ) {
            return WebhookUrlDecision::refuse( __( 'The webhook URL host is blocked.' ) );
        }

        if ( $this->hostIs( $host, $this->allowedHosts() ) ) {
            return WebhookUrlDecision::allowUnpinned();
        }

        // An IP literal is its own address: there is nothing to resolve, and so
        // nothing to rebind, so it is checked in place and left unpinned.
        if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return $this->addressIsBlocked( $host )
                ? WebhookUrlDecision::refuse( __( 'The webhook URL resolves to an address that is not allowed.' ) )
                : WebhookUrlDecision::allowUnpinned();
        }

        $addresses = $this->resolver->resolve( $host );

        if ( [] === $addresses ) {
            return WebhookUrlDecision::refuse( __( 'The webhook URL host could not be resolved.' ) );
        }

        foreach ( $addresses as $address ) {
            if ( $this->addressIsBlocked( $address ) ) {
                return WebhookUrlDecision::refuse( __( 'The webhook URL resolves to an address that is not allowed.' ) );
            }
        }

        return WebhookUrlDecision::allowPinned( $host, $this->portFor( $parts, $scheme ), $addresses );
    }

    /**
     * Works out which port a URL connects on.
     *
     * An explicit port wins; otherwise the scheme's default, so a pinned
     * connection targets the same port the client would have chosen from the
     * URL. Only the allowed schemes reach here, so the `https` fallback covers
     * everything but an explicitly permitted `http`.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $parts  The parsed URL.
     * @param  string  $scheme  The lower-cased scheme.
     *
     * @return int The port to connect on.
     */
    protected function portFor( array $parts, string $scheme ): int
    {
        if ( isset( $parts['port'] ) && is_int( $parts['port'] ) ) {
            return $parts['port'];
        }

        return 'http' === $scheme ? 80 : 443;
    }

    /**
     * Determines whether a resolved address lands in a refused range.
     *
     * An address that will not parse is refused rather than passed: the guard
     * cannot reason about it, and letting an unparseable address through is the
     * one outcome that turns a resolution surprise into a delivered request.
     *
     * @since 1.0.0
     *
     * @param  string  $address  The address to classify.
     *
     * @return bool True when the address is in a blocked range.
     */
    protected function addressIsBlocked( string $address ): bool
    {
        $packed = @inet_pton( $address );

        if ( false === $packed ) {
            return true;
        }

        // An IPv4-mapped IPv6 address (::ffff:a.b.c.d) is an IPv4 destination
        // wearing a v6 hat: unpack it to its four v4 bytes so a mapped loopback
        // is caught by the v4 ranges rather than slipping past them.
        if ( 16 === strlen( $packed ) && str_starts_with( $packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" ) ) {
            $packed = substr( $packed, 12 );
        }

        $ranges = 4 === strlen( $packed ) ? self::BLOCKED_IPV4_RANGES : self::BLOCKED_IPV6_RANGES;

        foreach ( $ranges as $range ) {
            if ( $this->packedAddressInRange( $packed, $range ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether a packed address falls inside a CIDR range.
     *
     * Compared as bytes rather than as integers so IPv4 and IPv6 go through the
     * same path: the full bytes of the prefix must match, and the partial byte
     * the prefix length ends inside is compared under a mask.
     *
     * @since 1.0.0
     *
     * @param  string  $packed  The address, as returned by {@see inet_pton()}.
     * @param  string  $cidr  The range in `address/bits` form.
     *
     * @return bool True when the address is within the range.
     */
    protected function packedAddressInRange( string $packed, string $cidr ): bool
    {
        [ $subnet, $bits ] = explode( '/', $cidr );

        $subnetPacked = @inet_pton( $subnet );

        if ( false === $subnetPacked || strlen( $subnetPacked ) !== strlen( $packed ) ) {
            return false;
        }

        $bits       = (int) $bits;
        $wholeBytes = intdiv( $bits, 8 );
        $remainder  = $bits % 8;

        if ( $wholeBytes > 0 && substr( $packed, 0, $wholeBytes ) !== substr( $subnetPacked, 0, $wholeBytes ) ) {
            return false;
        }

        if ( 0 === $remainder ) {
            return true;
        }

        $mask = 0xFF & ( 0xFF << ( 8 - $remainder ) );

        return ( ord( $packed[ $wholeBytes ] ) & $mask ) === ( ord( $subnetPacked[ $wholeBytes ] ) & $mask );
    }

    /**
     * Normalises a URL host for comparison.
     *
     * Lower-cased so a host list is not case-sensitive, and stripped of the
     * brackets `parse_url` leaves around an IPv6 literal so the literal can be
     * fed straight to {@see inet_pton()}.
     *
     * @since 1.0.0
     *
     * @param  string  $host  The host as `parse_url` returned it.
     *
     * @return string The normalised host.
     */
    protected function normaliseHost( string $host ): string
    {
        $host = strtolower( trim( $host ) );

        if ( str_starts_with( $host, '[' ) && str_ends_with( $host, ']' ) ) {
            $host = substr( $host, 1, -1 );
        }

        return $host;
    }

    /**
     * Determines whether a host matches any entry in a configured list.
     *
     * @since 1.0.0
     *
     * @param  string  $host  The already-normalised host.
     * @param  array<int, string>  $list  The host list to check against.
     *
     * @return bool True when the host is named in the list.
     */
    protected function hostIs( string $host, array $list ): bool
    {
        foreach ( $list as $entry ) {
            if ( ! is_string( $entry ) ) {
                continue;
            }

            if ( $host === $this->normaliseHost( $entry ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether the guard is switched on.
     *
     * @since 1.0.0
     *
     * @return bool True when a URL should be inspected.
     */
    protected function enabled(): bool
    {
        return (bool) config( 'artisanpack.bookings.webhooks.url_guard.enabled', true );
    }

    /**
     * Gets the schemes a webhook URL may use.
     *
     * Non-string and empty entries are dropped, and everything is lower-cased so
     * the list matches a lower-cased scheme. An empty list refuses every URL,
     * which is what a configuration that has cleared the list is asking for.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The allowed schemes.
     */
    protected function allowedSchemes(): array
    {
        $configured = config( 'artisanpack.bookings.webhooks.url_guard.allowed_schemes', [ 'https' ] );

        if ( ! is_array( $configured ) ) {
            return [ 'https' ];
        }

        return array_values( array_filter( array_map(
            static fn ( mixed $scheme ): string => is_string( $scheme ) ? strtolower( trim( $scheme ) ) : '',
            $configured,
        ), static fn ( string $scheme ): bool => '' !== $scheme ) );
    }

    /**
     * Gets the hosts allowed past the range check.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The allowed hosts.
     */
    protected function allowedHosts(): array
    {
        return $this->hostList( 'allowed_hosts' );
    }

    /**
     * Gets the hosts refused whatever they resolve to.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The blocked hosts.
     */
    protected function blockedHosts(): array
    {
        return $this->hostList( 'blocked_hosts' );
    }

    /**
     * Reads a configured host list.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The `url_guard` sub-key to read.
     *
     * @return array<int, string> The configured hosts, or an empty list.
     */
    protected function hostList( string $key ): array
    {
        $configured = config( 'artisanpack.bookings.webhooks.url_guard.' . $key, [] );

        if ( ! is_array( $configured ) ) {
            return [];
        }

        return array_values( array_filter(
            $configured,
            static fn ( mixed $entry ): bool => is_string( $entry ) && '' !== trim( $entry ),
        ) );
    }
}
