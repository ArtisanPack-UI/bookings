<?php

/**
 * Prune calendar events command.
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

use ArtisanPackUI\Bookings\Console\Commands\Concerns\PrunesRows;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarEvent;
use Illuminate\Console\Command;

/**
 * Removes the external-event mappings for bookings that are long over.
 *
 * A calendar event row is the link between a booking and the event a driver
 * created for it on somebody's Google or Microsoft calendar. It exists to be
 * followed: a reschedule updates that event, a cancellation deletes it. Once the
 * appointment is far enough in the past that neither can happen, the row is a
 * pointer to an event nobody will touch again.
 *
 * **Keyed on the booking having ended, not on the row's own age.** A mapping for
 * a booking a year out is older than one for yesterday's appointment and matters
 * far more — pruning by `created_at` would delete exactly the rows a reschedule
 * still needs. The row is only expendable once the appointment it points at is
 * over by the retention window.
 *
 * Deleting the row does not delete the external event, and is not meant to. The
 * event is on a calendar this package does not own, describing an appointment
 * that genuinely happened; removing it would erase history from somebody's
 * diary.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class PruneCalendarEventsCommand extends Command
{
    use PrunesRows;

    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:prune-calendar-events
        {--dry-run : Report what would be removed without deleting anything.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Removes calendar event mappings for bookings that ended past the retention window.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @return int The command exit code.
     */
    public function handle(): int
    {
        $days = $this->retentionDays( 'retention.calendar_events_ttl_days' );

        if ( null === $days ) {
            $this->warn( __( 'No calendar event retention window is configured, so nothing was pruned.' ) );

            return self::SUCCESS;
        }

        // A subquery rather than `whereHas`, because the bookings side has to be
        // read across every site and with the soft-deleted rows included, and
        // `whereHas` builds its own relation query with the model's global
        // scopes already applied. A deleted booking's mapping is the one most
        // certainly finished with.
        //
        // `->utc()` because this compares against `end_time`, which the package
        // writes as UTC, while the cutoff comes back in the application's zone
        // — see {@see PrunesRows::pruneCutoff()}. Without it, an application in
        // Asia/Tokyo prunes nine hours' worth of mappings early.
        $stale = CalendarEvent::query()->whereIn(
            'booking_id',
            Booking::query()
                ->acrossAllSites()
                ->withTrashed()
                ->where( 'end_time', '<', $this->pruneCutoff( $days )->utc() )
                ->select( 'id' ),
        );

        if ( $this->option( 'dry-run' ) ) {
            $this->info( __(
                ':count calendar event mapping(s) would be removed.',
                [ 'count' => $stale->count() ],
            ) );

            return self::SUCCESS;
        }

        $this->info( __(
            ':count calendar event mapping(s) removed.',
            [ 'count' => $this->pruneMatching( $stale ) ],
        ) );

        return self::SUCCESS;
    }
}
