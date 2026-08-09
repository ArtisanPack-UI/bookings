<?php

/**
 * Slot resolver contract.
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
use ArtisanPackUI\Bookings\Support\TimeRange;

/**
 * Turns availability rules into a concrete list of bookable slots.
 *
 * This is the seam an application replaces when its idea of "free" is not the
 * package's — a clinic that needs a gap between appointments, a studio that
 * only starts sessions on the hour. Bind an implementation against this
 * interface and every caller in the package asks it instead.
 *
 * An implementation is expected to be free of side effects: the same service,
 * provider, and window must produce the same slots, because the result is
 * cached under exactly those three things.
 *
 * A returned slot is a candidate, never a reservation. Nothing here takes a
 * lock, so a slot can stop being free between being resolved and being booked;
 * the unique index on `bookings` is what settles that race.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface SlotResolver
{
    /**
     * Resolves the slots a service can be booked into over a window.
     *
     * Slots are returned in ascending start order and never overlap each other.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider|null  $provider  The provider to resolve for, or null
     *                                          to resolve across every provider the
     *                                          service is offered by.
     * @param  TimeRange  $window  The span of time to resolve within.
     *
     * @return array<int, Slot> The bookable slots, ascending by start.
     */
    public function resolve( Service $service, ?ServiceProvider $provider, TimeRange $window ): array;
}
