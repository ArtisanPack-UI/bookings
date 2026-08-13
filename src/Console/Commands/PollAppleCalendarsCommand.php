<?php

/**
 * Poll Apple calendars command.
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
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads back every two-way Apple calendar.
 *
 * Scheduled every fifteen minutes, which is far more often than
 * {@see RefreshCalendarsCommand} sweeps the others, and for a reason that is not
 * a preference: CalDAV has no push mechanism this package can subscribe to, so
 * an Apple connection has no watch channel and no way to be told that something
 * moved. Polling is the only thing standing between a provider blocking out
 * their afternoon in Calendar.app and a customer booking into it, and the
 * interval is how wrong availability is allowed to get.
 *
 * Only two-way connections are polled. An outbound Apple connection is written
 * to and never read, so there is nothing to poll for.
 *
 * **The poll itself lands with `artisanpack-ui/apple`**, for the reason set out
 * in {@see SweepsCalendarConnections}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class PollAppleCalendarsCommand extends Command
{
    use SweepsCalendarConnections;

    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:calendar-apple-poll';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Polls two-way Apple calendar connections, which have no push notifications.';

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
            $this->info( __( 'No two-way Apple calendar connections to poll.' ) );

            return self::SUCCESS;
        }

        return $this->reportUnsyncable( __(
            ':count Apple calendar connection(s) are due a poll.',
            [ 'count' => $due ],
        ) );
    }

    /**
     * Gets the Apple connections whose calendars should be read back.
     *
     * @since 1.0.0
     *
     * @return Builder<CalendarConnection> The candidate query.
     */
    protected function dueConnections(): Builder
    {
        return CalendarConnection::query()
            ->acrossAllSites()
            ->active()
            ->twoWay()
            ->where( 'driver', CalendarDriver::Apple->value );
    }
}
