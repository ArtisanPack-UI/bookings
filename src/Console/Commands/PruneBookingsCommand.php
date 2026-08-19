<?php

/**
 * Prune bookings command.
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
use Illuminate\Console\Command;

/**
 * Soft-deletes bookings past their retention window.
 *
 * Retention and erasure are the two obligations a booking's personal data
 * carries and they pull in opposite directions: retention says stop keeping a
 * record after so long, erasure says scrub a named person's data on request.
 * This is the retention half. It soft-deletes — the row stays, its personal
 * columns untouched, its `deleted_at` set — because the record has to remain
 * legible for a legal or accounting record even once it drops out of the day to
 * day. Scrubbing the personal columns is {@see Booking::erasePersonalData()},
 * which {@see EraseBookingsCommand} drives, and is a different thing.
 *
 * The window is measured from the booking's `end_time`, not from the row's own
 * age, because retention is how long a record is kept after the appointment
 * happened — a booking taken a year ahead is not old the day it is created. That
 * column is stored in UTC, so the cutoff {@see PrunesRows::pruneCutoff()} hands
 * back in the application's zone is converted before the comparison.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class PruneBookingsCommand extends Command
{
    use PrunesRows;

    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:prune
        {--dry-run : Report what would be removed without deleting anything.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Soft-deletes bookings past their retention window, keeping the row and its personal data.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @return int The command exit code.
     */
    public function handle(): int
    {
        $days = $this->retentionDays( 'retention.prune_after_days' );

        if ( null === $days ) {
            $this->warn( __( 'No booking retention window is configured, so nothing was pruned.' ) );

            return self::SUCCESS;
        }

        $stale = Booking::query()->where( 'end_time', '<', $this->pruneCutoff( $days )->utc() );

        if ( $this->option( 'dry-run' ) ) {
            $this->info( __(
                ':count booking(s) would be soft-deleted.',
                [ 'count' => $stale->count() ],
            ) );

            return self::SUCCESS;
        }

        $this->info( __(
            ':count booking(s) soft-deleted.',
            [ 'count' => $this->pruneMatching( $stale ) ],
        ) );

        return self::SUCCESS;
    }
}
