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
use ArtisanPackUI\Bookings\Contracts\CalendarDriverRegistry;
use ArtisanPackUI\Bookings\Contracts\TwoWayCalendarDriver;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * **The refresh runs the driver's own read-back.** Each due connection is routed
 * through {@see CalendarDriverRegistry} to a driver, and a driver that reads its
 * calendar back — one implementing {@see TwoWayCalendarDriver}, such as the
 * bundled {@see \ArtisanPackUI\Bookings\Drivers\Calendar\GoogleCalendarDriver} —
 * has {@see TwoWayCalendarDriver::incrementalSync()} called on it, which pulls
 * the calendar's changes in as busy blocks and advances the connection's cursor
 * itself. A failure is recorded on the connection and the sweep moves on, so one
 * unreachable calendar does not hold up the rest.
 *
 * A connection whose driver is not installed — the Microsoft and Apple drivers
 * ship in their own packages this one does not depend on — has nothing to sync
 * it, and the sweep says so rather than exiting silently, which is still the
 * answer an operator wants when they are asking why availability looks wrong.
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
     * @param  CalendarDriverRegistry  $registry  The registry a connection's
     *                                            driver is resolved through.
     *
     * @return int The command exit code.
     */
    public function handle( CalendarDriverRegistry $registry ): int
    {
        $connections = $this->dueConnections()->get();

        if ( $connections->isEmpty() ) {
            $this->info( __( 'No two-way calendar connections to refresh.' ) );

            return self::SUCCESS;
        }

        // Resolved once rather than per connection: reading the registry runs the
        // `ap.bookings.calendarSync.providers` filter, and a sweep over several
        // connections would otherwise re-run every subscriber once per row.
        $drivers = $registry->all();

        $refreshed = 0;
        $failed    = 0;
        $unsynced  = 0;

        foreach ( $connections as $connection ) {
            $driver = $drivers[ $connection->driver->value ] ?? null;

            if ( ! $driver instanceof TwoWayCalendarDriver ) {
                $unsynced++;

                continue;
            }

            try {
                $driver->incrementalSync( $connection );

                $this->recordRefreshed( $connection );

                $refreshed++;
            } catch ( Throwable $failure ) {
                $this->recordFailure( $connection, $failure );

                $failed++;
            }
        }

        // Nothing could be read back at all: every due connection's driver is
        // absent. That is the configuration state the shared warning is for.
        if ( 0 === $refreshed && 0 === $failed ) {
            return $this->reportUnsyncable( __(
                ':count two-way calendar connection(s) are due a refresh.',
                [ 'count' => $unsynced ],
            ) );
        }

        $this->info( __(
            'Refreshed :refreshed of :total two-way calendar connection(s); :failed failed, :unsynced had no driver installed.',
            [
                'refreshed' => $refreshed,
                'total'     => $connections->count(),
                'failed'    => $failed,
                'unsynced'  => $unsynced,
            ],
        ) );

        return self::SUCCESS;
    }

    /**
     * Records a successful read-back on the connection.
     *
     * Mirrors the successful-sync bookkeeping in
     * {@see \ArtisanPackUI\Bookings\Services\CalendarSyncOrchestrator}: the sync
     * timestamp moves forward and any stale error is cleared. The failure streak
     * belongs to the outbound push path and is left untouched here.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection that answered.
     *
     * @return void
     */
    protected function recordRefreshed( CalendarConnection $connection ): void
    {
        $connection->forceFill( [
            'last_sync_at'    => CarbonImmutable::now(),
            'last_sync_error' => null,
        ] )->save();
    }

    /**
     * Records a failed read-back on the connection and logs it.
     *
     * The message is capped so a driver that echoes a large API body cannot bloat
     * the column, matching the bound the orchestrator keeps on the outbound path.
     * The sweep does not abort on a failure — one unreachable calendar must not
     * stop the others being refreshed — so the error is recorded and the loop
     * continues.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection that failed.
     * @param  Throwable  $failure  What the read-back failed with.
     *
     * @return void
     */
    protected function recordFailure( CalendarConnection $connection, Throwable $failure ): void
    {
        $reason = mb_strimwidth( $failure->getMessage(), 0, 2000, '…' );

        $connection->forceFill( [ 'last_sync_error' => $reason ] )->save();

        Log::warning( 'A two-way calendar connection could not be refreshed.', [
            'connection_id' => $connection->getKey(),
            'exception'     => $reason,
        ] );

        $this->warn( __(
            'Calendar connection :id could not be refreshed.',
            [ 'id' => $connection->getKey() ],
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
