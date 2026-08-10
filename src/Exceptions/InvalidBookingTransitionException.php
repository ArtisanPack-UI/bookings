<?php

/**
 * Invalid booking transition exception.
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

use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Models\Booking;

use function sprintf;

/**
 * A booking was asked to move somewhere it cannot go from where it is.
 *
 * Cancelling a cancelled booking, completing one nobody ever confirmed,
 * rescheduling one that has already happened. Each of these is a caller bug
 * rather than a customer one, and letting it through would fire a lifecycle
 * action twice for a transition that only occurred once — which is exactly the
 * guarantee every listener downstream is written against.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class InvalidBookingTransitionException extends BookingException
{
    /**
     * Builds the exception for a booking and the status it was asked for.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking that refused the move.
     * @param  BookingStatus  $to  The status it was asked to take.
     *
     * @return self The exception to throw.
     */
    public static function from( Booking $booking, BookingStatus $to ): self
    {
        return new self( sprintf(
            'Booking %d cannot move from %s to %s.',
            (int) $booking->getKey(),
            $booking->status->value,
            $to->value,
        ) );
    }

    /**
     * Builds the exception for a booking that has no slot left to move.
     *
     * Separate from {@see self::from()} because rescheduling does not name a
     * status to move to — the status is not what is wrong. A cancelled or
     * completed booking is not holding a provider's time, so there is nothing
     * for a new time to be a new time *for*.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking that refused the move.
     *
     * @return self The exception to throw.
     */
    public static function cannotReschedule( Booking $booking ): self
    {
        return new self( sprintf(
            'Booking %d is %s and no longer holds a slot to reschedule.',
            (int) $booking->getKey(),
            $booking->status->value,
        ) );
    }
}
