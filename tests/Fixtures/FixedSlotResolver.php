<?php

/**
 * Fixed slot resolver fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Bookings\Contracts\SlotResolver;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\TimeRange;

/**
 * A resolver that offers one slot at the start of whatever window it is given.
 *
 * Exists to prove the contract is implementable and to stand in for the real
 * resolver in tests that only care that one was consulted.
 *
 * @since 1.0.0
 */
class FixedSlotResolver implements SlotResolver
{
    /**
     * Constructs the resolver.
     *
     * @since 1.0.0
     *
     * @param  int  $minutes  How long the offered slot runs for.
     */
    public function __construct( protected int $minutes = 30 )
    {
    }

    /**
     * Resolves the slots a service can be booked into over a window.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider|null  $provider  The provider to resolve for.
     * @param  TimeRange  $window  The span of time to resolve within.
     *
     * @return array<int, Slot> The single slot at the head of the window.
     */
    public function resolve( Service $service, ?ServiceProvider $provider, TimeRange $window ): array
    {
        $end = $window->start->addMinutes( $this->minutes );

        if ( $end->greaterThan( $window->end ) ) {
            return [];
        }

        return [ new Slot( new TimeRange( $window->start, $end ), $provider?->id ) ];
    }
}
