<?php

/**
 * Booking status enum.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Enums;

/**
 * Where a booking sits in its lifecycle.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum BookingStatus: string
{
    /**
     * Gets the statuses that hold a provider's slot.
     *
     * This list is the PHP-side copy of the predicate in the partial unique
     * index on `bookings`. The two have to agree: the index is what actually
     * refuses a double booking, and a domain query that disagreed with it would
     * report a slot as free right up until the insert failed.
     *
     * @since 1.0.0
     *
     * @return array<int, self> The statuses that occupy a slot.
     */
    public static function active(): array
    {
        return [ self::Requested, self::Confirmed ];
    }

    /**
     * Gets the string values of the statuses that hold a provider's slot.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The active status values.
     */
    public static function activeValues(): array
    {
        return array_map( static fn ( self $status ): string => $status->value, self::active() );
    }

    /**
     * Determines whether this status holds a provider's slot.
     *
     * @since 1.0.0
     *
     * @return bool True when the booking occupies its slot.
     */
    public function occupiesSlot(): bool
    {
        return in_array( $this, self::active(), true );
    }

    /**
     * Gets a human, translatable label for the status.
     *
     * The `value` is the machine string the column and the unique index agree
     * on; this is what a person reads. Kept here rather than in each view so a
     * list, a calendar, and a detail page all name a status the same way.
     *
     * @since 1.0.0
     *
     * @return string The label for display.
     */
    public function label(): string
    {
        return match ( $this ) {
            self::Requested => __( 'Requested' ),
            self::Confirmed => __( 'Confirmed' ),
            self::Cancelled => __( 'Cancelled' ),
            self::Completed => __( 'Completed' ),
            self::NoShow    => __( 'No-show' ),
        };
    }
    case Requested = 'requested';

    case Confirmed = 'confirmed';

    case Cancelled = 'cancelled';

    case Completed = 'completed';

    case NoShow = 'no_show';
}
