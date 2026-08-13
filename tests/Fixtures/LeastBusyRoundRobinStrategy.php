<?php

/**
 * Round-robin strategy fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Bookings\Contracts\RoundRobinStrategy;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Support\Slot;

/**
 * A strategy that hands the slot to whoever has the fewest bookings.
 *
 * A plausible replacement for plain rotation, which is the point: it proves an
 * application can decide assignment on its own terms without the package
 * knowing what those terms are.
 *
 * @since 1.0.0
 */
class LeastBusyRoundRobinStrategy implements RoundRobinStrategy
{
    /**
     * Chooses the provider for a slot.
     *
     * @since 1.0.0
     *
     * @param  array<int, ServiceProvider>  $candidates  The providers free for the slot.
     * @param  Service  $service  The service being booked.
     * @param  Slot  $slot  The slot being assigned.
     *
     * @return ServiceProvider|null The least busy candidate.
     */
    public function select( array $candidates, Service $service, Slot $slot ): ?ServiceProvider
    {
        // Counted once per candidate rather than inside the comparator: usort
        // makes O(n log n) comparisons, so counting there issues the same query
        // for the same provider several times over. This fixture is documented
        // as an example of application-defined assignment, and the pattern is
        // worth copying correctly.
        $counts = [];

        foreach ( $candidates as $index => $candidate ) {
            $counts[ $index ] = $candidate->bookings()->count();
        }

        // Sorts the counts while keeping their keys, so the winner is looked up
        // by index rather than by searching the candidate list again. PHP's
        // sorts are stable, so equal counts keep the order they arrived in.
        asort( $counts );

        $leastBusy = array_key_first( $counts );

        return null === $leastBusy ? null : $candidates[ $leastBusy ];
    }
}
