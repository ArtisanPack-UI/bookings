<?php

/**
 * Series exception.
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

use ArtisanPackUI\Bookings\Enums\SeriesEditScope;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use Throwable;

/**
 * The domain refused something asked of a recurring series.
 *
 * Every case here is a caller mistake rather than a slot that has gone — an
 * unparseable rule, an occurrence handed to the wrong series, an edit scope
 * asked to work without the occurrence it is named after. They are separated
 * from {@see SlotUnavailableException} because none of them get better by
 * retrying, and a widget that offers "pick another time" in response to a
 * malformed RRULE is telling the customer something untrue about their own
 * request.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SeriesException extends BookingException
{
    /**
     * Builds the exception for a recurrence rule that could not be read.
     *
     * @since 1.0.0
     *
     * @param  string  $rrule  The rule that was given.
     * @param  Throwable|null  $previous  Whatever the parser threw.
     *
     * @return self The exception to throw.
     */
    public static function invalidRule( string $rrule, ?Throwable $previous = null ): self
    {
        return new self(
            sprintf( 'The recurrence rule "%s" is not a valid RFC 5545 RRULE.', $rrule ),
            0,
            $previous,
        );
    }

    /**
     * Builds the exception for a rule that named no occurrences at all.
     *
     * A rule bounded by an `UNTIL` earlier than its own start is the usual way
     * in. It parses, so the parser has no complaint to make, and it describes a
     * series with nothing in it — which is not a series.
     *
     * @since 1.0.0
     *
     * @param  string  $rrule  The rule that was expanded.
     *
     * @return self The exception to throw.
     */
    public static function producedNoOccurrences( string $rrule ): self
    {
        return new self( sprintf(
            'The recurrence rule "%s" produced no occurrences.',
            $rrule,
        ) );
    }

    /**
     * Builds the exception for a series that has already been called off.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series that was already cancelled.
     *
     * @return self The exception to throw.
     */
    public static function alreadyCancelled( BookingSeries $series ): self
    {
        return new self( sprintf(
            'Series %d has already been cancelled.',
            (int) $series->getKey(),
        ) );
    }

    /**
     * Builds the exception for an occurrence belonging to a different series.
     *
     * Worth refusing loudly rather than ignoring: a "this and following" split
     * keyed off the wrong occurrence would bound one arrangement at a date taken
     * from another and cancel the wrong customer's appointments.
     *
     * @since 1.0.0
     *
     * @param  Booking  $occurrence  The booking that was handed over.
     * @param  BookingSeries  $series  The series it was supposed to belong to.
     *
     * @return self The exception to throw.
     */
    public static function occurrenceNotInSeries( Booking $occurrence, BookingSeries $series ): self
    {
        return new self( sprintf(
            'Booking %d is not an occurrence of series %d.',
            (int) $occurrence->getKey(),
            (int) $series->getKey(),
        ) );
    }

    /**
     * Builds the exception for an edit scope asked to work without an occurrence.
     *
     * @since 1.0.0
     *
     * @param  SeriesEditScope  $scope  The scope that needs one.
     *
     * @return self The exception to throw.
     */
    public static function scopeNeedsAnOccurrence( SeriesEditScope $scope ): self
    {
        return new self( sprintf(
            'A "%s" edit has to name the occurrence it starts from.',
            $scope->value,
        ) );
    }
}
