<?php

/**
 * Webhook URL decision.
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

/**
 * What {@see WebhookUrlGuard} decided about one URL.
 *
 * Carries the verdict and, on an allow, the addresses the guard vetted — so the
 * delivery can connect to the address that was actually checked rather than let
 * the HTTP client resolve the name a second time. That second resolution is the
 * whole DNS-rebinding hole: the guard clears the addresses a name answered with,
 * and a name whose authoritative server answers differently on the next query
 * hands the client a loopback address the guard never saw. Pinning the vetted
 * address closes it; a check that only vets and then hands the name back does
 * not.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class WebhookUrlDecision
{
    /**
     * Constructs a decision.
     *
     * @since 1.0.0
     *
     * @param  bool  $allowed  Whether the URL may be delivered to.
     * @param  string|null  $reason  Why it was refused, or null when allowed.
     * @param  string|null  $host  The host the addresses belong to.
     * @param  int|null  $port  The port to connect on.
     * @param  array<int, string>  $addresses  The vetted addresses to pin to.
     * @param  bool  $pinnable  Whether the connection should pin an address.
     * @param  string|null  $requestUrl  The URL to post, canonicalised so the
     *                                   client's host matches the pinned one, or
     *                                   null to post the URL as stored.
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly array $addresses = [],
        public readonly bool $pinnable = false,
        public readonly ?string $requestUrl = null,
    ) {
    }

    /**
     * Builds a refusal carrying its reason.
     *
     * @since 1.0.0
     *
     * @param  string  $reason  Why the URL was refused.
     *
     * @return self The refusal.
     */
    public static function refuse( string $reason ): self
    {
        return new self( false, $reason );
    }

    /**
     * Builds an allow that connects to the name as given, without pinning.
     *
     * Used where there is nothing to pin or nothing to gain by it: the guard is
     * switched off, the host is on the operator's allow list, or the host is an
     * IP literal the client cannot resolve anywhere else.
     *
     * @since 1.0.0
     *
     * @return self The allow.
     */
    public static function allowUnpinned(): self
    {
        return new self( true );
    }

    /**
     * Builds an allow that pins the vetted addresses into the connection.
     *
     * @since 1.0.0
     *
     * @param  string  $host  The host the addresses belong to, canonicalised to
     *                        the exact string the client will resolve.
     * @param  int  $port  The port to connect on.
     * @param  array<int, string>  $addresses  The vetted addresses.
     * @param  string  $requestUrl  The URL to post, rewritten to the canonical
     *                              host so the client's host matches the pin.
     *
     * @return self The allow.
     */
    public static function allowPinned( string $host, int $port, array $addresses, string $requestUrl ): self
    {
        return new self( true, null, $host, $port, $addresses, true, $requestUrl );
    }
}
