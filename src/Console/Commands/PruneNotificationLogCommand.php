<?php

/**
 * Prune notification log command.
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
use ArtisanPackUI\Bookings\Models\NotificationLog;
use Illuminate\Console\Command;

/**
 * Removes notification log rows past their retention window.
 *
 * The log is two things at once, and the second is why this command has to be
 * careful. It is a record of what was sent to whom — which is personal data on a
 * clock — and it is also the idempotency key that stops the reminder cron
 * emailing a customer twice. Deleting a claim row un-claims the reminder it
 * stood for.
 *
 * That is safe only because the window is long relative to the thing it guards.
 * A reminder is claimed at most `hours_before` ahead of a booking and the
 * default retention is ninety days, so a row old enough to prune belongs to a
 * booking that happened months ago and no sweep will ever look at again. An
 * installation that shortens `retention.notification_log_days` to less than its
 * longest reminder window is asking for the same reminder to be sent twice.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class PruneNotificationLogCommand extends Command
{
    use PrunesRows;

    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:prune-notification-log
        {--dry-run : Report what would be removed without deleting anything.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Removes booking notification log rows past their retention window.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @return int The command exit code.
     */
    public function handle(): int
    {
        $days = $this->retentionDays( 'retention.notification_log_days' );

        if ( null === $days ) {
            $this->warn( __( 'No notification log retention window is configured, so nothing was pruned.' ) );

            return self::SUCCESS;
        }

        $stale = NotificationLog::query()->where( 'created_at', '<', $this->pruneCutoff( $days ) );

        if ( $this->option( 'dry-run' ) ) {
            $this->info( __(
                ':count notification log row(s) would be removed.',
                [ 'count' => $stale->count() ],
            ) );

            return self::SUCCESS;
        }

        $this->info( __(
            ':count notification log row(s) removed.',
            [ 'count' => $this->pruneMatching( $stale ) ],
        ) );

        return self::SUCCESS;
    }
}
