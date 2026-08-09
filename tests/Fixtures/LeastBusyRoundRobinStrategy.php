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

use function usort;

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
        usort( $candidates, static function ( ServiceProvider $a, ServiceProvider $b ): int {
            return $a->bookings()->count() <=> $b->bookings()->count();
        } );

        return $candidates[ 0 ] ?? null;
    }
}
