<?php

/**
 * Shared reporting for the calendar sweep commands.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Console\Commands\Concerns;

use function __;

/**
 * What the three calendar sweeps say when they cannot act.
 *
 * `bookings:calendar-refresh`, `bookings:calendar-watch-renew`, and
 * `bookings:calendar-apple-poll` each find the rows that are due and then need a
 * {@see \ArtisanPackUI\Bookings\Contracts\CalendarSyncDriver} to do anything
 * about them. The drivers ship in `artisanpack-ui/google`,
 * `artisanpack-ui/microsoft`, and `artisanpack-ui/apple`, and this package
 * depends on none of them — so a sweep that finds work on an installation with
 * no driver installed has to say so rather than exit silently.
 *
 * Saying so at warning level, and on every run, is the point. A connection whose
 * calendar has stopped being read still shows availability, so the failure is
 * invisible from the outside: customers keep booking slots the provider is
 * already busy for, and nothing anywhere looks broken.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
trait SweepsCalendarConnections
{
    /**
     * Reports work that was found but cannot be carried out.
     *
     * Exits successfully rather than failing. A missing driver is a
     * configuration state, not a crash, and a non-zero exit here would put a red
     * line in the scheduler's log every hour on an installation that has simply
     * not connected any calendars yet.
     *
     * @since 1.0.0
     *
     * @param  string  $found  What the sweep found, already translated.
     *
     * @return int The command exit code.
     */
    protected function reportUnsyncable( string $found ): int
    {
        $this->warn( $found );
        $this->warn( __(
            'No calendar sync driver is installed, so nothing was synced. Install artisanpack-ui/google, artisanpack-ui/microsoft, or artisanpack-ui/apple.',
        ) );

        return self::SUCCESS;
    }
}
