<?php

/**
 * Renew calendar watch channels command.
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
use ArtisanPackUI\Bookings\Models\CalendarWatchChannel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

use function __;

/**
 * Replaces push registrations before — and after — they lapse.
 *
 * Scheduled hourly. A Google watch channel and a Microsoft subscription both
 * expire, and an expired one fails in the worst possible way: the calendar
 * simply stops telling us anything. Nothing errors, nothing retries, and the
 * only visible symptom is availability that is slowly more wrong.
 *
 * The sweep runs an hour ahead of expiry so a renewal has somewhere to fail
 * before the channel it replaces goes quiet, and picks up already-expired
 * channels too — a lapsed one is the most urgent thing in the table, not
 * something to skip past.
 *
 * **The renewal itself lands with the driver packages**, for the reason set out
 * in {@see SweepsCalendarConnections}: registering a channel is a call to
 * Google or Microsoft, and neither driver ships here.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class RenewCalendarWatchChannelsCommand extends Command
{
    use SweepsCalendarConnections;

    /**
     * How far ahead of expiry a channel is renewed, in minutes.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const RENEWAL_LEAD_MINUTES = 60;

    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:calendar-watch-renew';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Renews calendar push registrations that are expiring or have expired.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @return int The command exit code.
     */
    public function handle(): int
    {
        $due = $this->dueChannels()->count();

        if ( 0 === $due ) {
            $this->info( __( 'No calendar watch channels are due for renewal.' ) );

            return self::SUCCESS;
        }

        return $this->reportUnsyncable( __(
            ':count calendar watch channel(s) are due for renewal.',
            [ 'count' => $due ],
        ) );
    }

    /**
     * Gets the push registrations that need replacing.
     *
     * Restricted to channels on connections that are still active, which the
     * table itself does not say: `CalendarConnection::disable()` clears the sync
     * token and leaves the watch rows behind. Without the join, a connection
     * disabled for a revoked token reports its channels as due every hour
     * forever — and this command's whole design rests on that warning being
     * worth reading, so a row that can never be renewed is not noise it can
     * afford. Reconnecting brings the channels back into scope on their own.
     *
     * @since 1.0.0
     *
     * @return Builder<CalendarWatchChannel> The candidate query.
     */
    protected function dueChannels(): Builder
    {
        return CalendarWatchChannel::query()
            ->whereIn(
                'connection_id',
                CalendarConnection::query()->acrossAllSites()->active()->select( 'id' ),
            )
            ->expiringBefore( CarbonImmutable::now()->addMinutes( self::RENEWAL_LEAD_MINUTES ) );
    }
}
