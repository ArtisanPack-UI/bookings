<?php

/**
 * Slot unavailable exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Exceptions;

use ArtisanPackUI\Bookings\Models\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Nobody could take the slot that was asked for.
 *
 * Thrown for both readings of "no", because a caller cannot act on the
 * difference: the slot may never have been offered, or it may have been offered
 * and taken by somebody else between the customer seeing it and pressing the
 * button. Either way the answer to give them is "pick another time", and the
 * only honest thing to do with the distinction is not to invent one.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SlotUnavailableException extends BookingException
{
    /**
     * Builds the exception for a service and a start time.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service that was being booked.
     * @param  CarbonInterface  $start  The instant that was asked for.
     *
     * @return self The exception to throw.
     */
    public static function for( Service $service, CarbonInterface $start ): self
    {
        return new self( sprintf(
            'No provider is available for service %d at %s.',
            (int) $service->getKey(),
            CarbonImmutable::instance( $start )->utc()->toIso8601String(),
        ) );
    }
}
