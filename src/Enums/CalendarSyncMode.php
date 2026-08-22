<?php

/**
 * Calendar sync mode enum.
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
 * Which direction a calendar connection syncs in.
 *
 * `two_way` is the only mode that lets an external calendar suppress
 * availability, which is a lot of power to hand a third party — so it is opted
 * into per connection rather than being the default.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum CalendarSyncMode: string
{
    /**
     * Determines whether this mode reads busy blocks back from the calendar.
     *
     * @since 1.0.0
     *
     * @return bool True when the external calendar can suppress availability.
     */
    public function readsBusyBlocks(): bool
    {
        return self::TwoWay === $this;
    }

    /**
     * Determines whether this mode writes bookings out to the calendar.
     *
     * @since 1.0.0
     *
     * @return bool True when bookings are pushed to the external calendar.
     */
    public function writesEvents(): bool
    {
        return self::Off !== $this;
    }
    case Off = 'off';

    case Outbound = 'outbound';

    case TwoWay = 'two_way';
}
