<?php

/**
 * Time range value object.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use JsonSerializable;

use function sprintf;

/**
 * A half-open span of time, `[start, end)`.
 *
 * The half-open reading is the whole point: a range ending at 10:00 and one
 * starting at 10:00 do not overlap, so back-to-back bookings are legal while a
 * genuine double booking is not. Every overlap question in the package is asked
 * of this object so the answer cannot differ between two call sites.
 *
 * Instants are normalised to {@see CarbonImmutable} on construction. A caller
 * that hands in a mutable Carbon it later modifies cannot reach back in and
 * change a range it already built.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final readonly class TimeRange implements JsonSerializable
{
    /**
     * The instant the range begins, inclusive.
     *
     * @since 1.0.0
     *
     * @var CarbonImmutable
     */
    public CarbonImmutable $start;

    /**
     * The instant the range ends, exclusive.
     *
     * @since 1.0.0
     *
     * @var CarbonImmutable
     */
    public CarbonImmutable $end;

    /**
     * Constructs the range.
     *
     * @since 1.0.0
     *
     * @param  CarbonInterface  $start  When the range begins.
     * @param  CarbonInterface  $end  When the range ends. Must be after the start.
     *
     * @throws InvalidArgumentException When the end is not after the start.
     */
    public function __construct( CarbonInterface $start, CarbonInterface $end )
    {
        $this->start = CarbonImmutable::instance( $start );
        $this->end   = CarbonImmutable::instance( $end );

        if ( $this->end->lessThanOrEqualTo( $this->start ) ) {
            throw new InvalidArgumentException( sprintf(
                'A time range must end after it starts; got [%s, %s).',
                $this->start->toIso8601String(),
                $this->end->toIso8601String(),
            ) );
        }
    }

    /**
     * Gets the length of the range in whole minutes, rounded down.
     *
     * Truncation matters in one direction only. A range of 29 minutes 59
     * seconds reports 29 and fails a `>= 30` check, which is safe; a range of
     * 30 minutes 30 seconds reports 30 and passes an `=== 30` check, which
     * quietly accepts something longer than the service duration. Bookings are
     * stored on whole minutes so this does not arise from our own rows, but an
     * external calendar's busy period is not under our control. Compare the
     * endpoints directly when the difference would matter.
     *
     * @since 1.0.0
     *
     * @return int The number of whole minutes the range spans.
     */
    public function minutes(): int
    {
        return (int) $this->start->diffInMinutes( $this->end );
    }

    /**
     * Determines whether this range shares any instant with another.
     *
     * @since 1.0.0
     *
     * @param  self  $other  The range to compare against.
     *
     * @return bool True when the two ranges intersect.
     */
    public function overlaps( self $other ): bool
    {
        return $this->start->lessThan( $other->end ) && $other->start->lessThan( $this->end );
    }

    /**
     * Determines whether this range describes the same span as another.
     *
     * @since 1.0.0
     *
     * @param  self  $other  The range to compare against.
     *
     * @return bool True when both endpoints name the same instants.
     */
    public function equals( self $other ): bool
    {
        return $this->start->equalTo( $other->start ) && $this->end->equalTo( $other->end );
    }

    /**
     * Gets the range as an array, in UTC.
     *
     * Normalised rather than rendered in whatever zone the range was built
     * from. The endpoints are instants, so the zone carries no information the
     * range is entitled to keep — but leaving it in would make the serialized
     * text differ between two call sites that mean the identical span, which
     * matters the moment one of these is stored, sent to an external calendar,
     * or compared as a string. The zone a customer chose lives on the booking,
     * in `customer_timezone`, which is where it belongs.
     *
     * @since 1.0.0
     *
     * @return array{start: string, end: string} The range in ISO 8601, in UTC.
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->utc()->toIso8601String(),
            'end'   => $this->end->utc()->toIso8601String(),
        ];
    }

    /**
     * Gets the data that should be serialized to JSON.
     *
     * @since 1.0.0
     *
     * @return array{start: string, end: string} The range in ISO 8601.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
