<?php

/**
 * Round-robin strategy contract.
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

use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Support\Slot;

/**
 * Picks which provider serves a slot when the service does not name one.
 *
 * "Round robin" is the default reading, not the only one an application is
 * allowed to have. Fair rotation, least-loaded, highest-rated, and cheapest are
 * all the same decision made on different grounds, so the whole decision is
 * behind one method rather than a rotation the package hard-codes.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface RoundRobinStrategy
{
    /**
     * Chooses the provider for a slot.
     *
     * The candidate list has already been filtered down to providers who offer
     * the service and are free for the slot, so an implementation is choosing
     * between equals rather than checking availability again.
     *
     * Returning null means "none of these should take it", which the caller
     * treats as the slot being unbookable rather than as an error. Returning a
     * provider that is not in `$candidates` is a programming error.
     *
     * @since 1.0.0
     *
     * @param  array<int, ServiceProvider>  $candidates  The providers free for the
     *                                                   slot. Never empty.
     * @param  Service  $service  The service being booked.
     * @param  Slot  $slot  The slot being assigned.
     *
     * @return ServiceProvider|null The chosen provider, or null to leave the slot
     *                              unassigned.
     */
    public function select( array $candidates, Service $service, Slot $slot ): ?ServiceProvider;
}
