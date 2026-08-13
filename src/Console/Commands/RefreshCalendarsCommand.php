<?php

/**
 * Refresh calendars command.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Console\Commands;

use ArtisanPackUI\Bookings\Console\Commands\Concerns\SweepsCalendarConnections;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Re-reads the busy blocks behind every two-way calendar connection.
 *
 * Scheduled daily. Two-way sync is normally driven by push — a Google watch
 * channel or a Microsoft subscription telling us something moved — and this is
 * the backstop underneath it. A push that was dropped, a channel that lapsed
 * before {@see RenewCalendarWatchChannelsCommand} reached it, a connection whose
 * subscription was never registered: all of them look identical from here, which
 * is a calendar quietly no longer suppressing availability, and a customer
 * booking a slot the provider is not free for.
 *
 * Only two-way connections are swept. An outbound connection is written to and
 * never read, so there is nothing about it that can go stale.
 *
 * **The refresh itself lands with the driver packages.** Reading a calendar back
 * needs a {@see \ArtisanPackUI\Bookings\Contracts\CalendarSyncDriver}, and those
 * ship in `artisanpack-ui/google`, `artisanpack-ui/microsoft`, and
 * `artisanpack-ui/apple` — none of which this package depends on. Until one is
 * installed and registered the sweep reports what it found and changes nothing,
 * which is still the answer an operator wants when they are asking why
 * availability looks wrong.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class RefreshCalendarsCommand extends Command
{
    use SweepsCalendarConnections;

    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:calendar-refresh';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Re-reads busy blocks for every two-way calendar connection.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @return int The command exit code.
     */
    public function handle(): int
    {
        $due = $this->dueConnections()->count();

        if ( 0 === $due ) {
            $this->info( __( 'No two-way calendar connections to refresh.' ) );

            return self::SUCCESS;
        }

        return $this->reportUnsyncable( __(
            ':count two-way calendar connection(s) are due a refresh.',
            [ 'count' => $due ],
        ) );
    }

    /**
     * Gets the connections whose calendars should be read back.
     *
     * @since 1.0.0
     *
     * @return Builder<CalendarConnection> The candidate query.
     */
    protected function dueConnections(): Builder
    {
        return CalendarConnection::query()->acrossAllSites()->active()->twoWay();
    }
}
