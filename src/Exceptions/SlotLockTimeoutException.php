<?php

/**
 * Slot lock timeout exception.
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

use Carbon\CarbonInterface;

use function sprintf;

/**
 * The advisory lock on a provider's slot could not be taken in time.
 *
 * Distinct from {@see SlotUnavailableException} on purpose. That one means the
 * slot is gone; this one means nobody managed to find out. A caller can retry
 * this and should not retry that, and telling a customer their slot was taken
 * when the truth is that a lock waiter timed out is a lie the domain should not
 * be in a position to tell.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SlotLockTimeoutException extends BookingException
{
    /**
     * Builds the exception for a provider and a start time.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider whose slot was being locked.
     * @param  CarbonInterface  $start  The instant the slot begins.
     * @param  int  $seconds  How long the lock was waited for.
     *
     * @return self The exception to throw.
     */
    public static function for( int $providerId, CarbonInterface $start, int $seconds ): self
    {
        return new self( sprintf(
            'Timed out after %ds waiting for the slot lock on provider %d at %s.',
            $seconds,
            $providerId,
            $start->utc()->toIso8601String(),
        ) );
    }
}
