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
 * **The renewal itself is deferred to whoever owns the callback URL.** Renewing
 * a push channel is a fresh watch registered against an inbound-notification URL,
 * and where that URL lives — the route that receives the calendar's POST and
 * resolves it back to a connection — is not this package's concern; the push side
 * of two-way sync (registering channels in the first place, receiving the
 * notifications, renewing them) belongs to the driver package that stands up that
 * route. So this sweep finds the due channels and offers them to the
 * `ap.bookings.calendarSync.renewChannels` filter: a subscriber renews the ones
 * it can and returns how many it handled. With nothing subscribed — the state as
 * this package ships, since it registers no channels itself — the count stays
 * zero and the sweep reports the channels as unrenewable rather than exiting
 * silently.
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
        $due = $this->dueChannels()->get();

        if ( $due->isEmpty() ) {
            $this->info( __( 'No calendar watch channels are due for renewal.' ) );

            return self::SUCCESS;
        }

        // The renewal is a call to the external calendar against a callback URL
        // only the driver package owns, so it is deferred to whatever subscribes
        // here. A subscriber renews the channels it can and returns how many it
        // handled; with nothing subscribed the count stays zero.
        $renewed = (int) applyFilters( 'ap.bookings.calendarSync.renewChannels', 0, $due );

        if ( $renewed < 1 ) {
            return $this->reportUnsyncable( __(
                ':count calendar watch channel(s) are due for renewal.',
                [ 'count' => $due->count() ],
            ) );
        }

        $this->info( __(
            'Renewed :renewed of :total calendar watch channel(s).',
            [ 'renewed' => $renewed, 'total' => $due->count() ],
        ) );

        return self::SUCCESS;
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
