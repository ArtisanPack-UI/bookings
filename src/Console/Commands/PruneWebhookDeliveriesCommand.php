<?php

/**
 * Prune webhook deliveries command.
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
use ArtisanPackUI\Bookings\Enums\WebhookDeliveryStatus;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

use function __;
use function config;

/**
 * Removes webhook delivery attempts past their retention window.
 *
 * The delivery table is the noisiest one the package has — a row per attempt,
 * per endpoint, per event, and the retry schedule means a failing endpoint
 * writes six of them for one booking. It is kept so an operator can answer "did
 * that fire, and what did they say", which is a question with a short shelf
 * life.
 *
 * A `pending` delivery is never pruned, however old it is. The backoff schedule
 * spans about fourteen hours against a default retention of thirty days, so a
 * pending row that old is one nothing has picked up — but deleting it makes the
 * delivery stop existing rather than fail, and an endpoint that should have
 * received the event never hears about it with nothing anywhere to say why. A
 * stuck delivery is a queue to look at, not a row to tidy away.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class PruneWebhookDeliveriesCommand extends Command
{
    use PrunesRows;

    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:prune-webhook-deliveries
        {--dry-run : Report what would be removed without deleting anything.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Removes webhook delivery attempts past their retention window.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @return int The command exit code.
     */
    public function handle(): int
    {
        // `retention.webhook_delivery_days` is the knob this family of commands
        // reads, and `webhooks.delivery_retention_days` is the one that shipped
        // with the webhook config before there was a prune command to read it.
        // The retention block wins where it is set, so an installation that has
        // configured either gets the window it asked for.
        // Only an *absent* setting defers to the webhook block. `retentionDays()`
        // reads a zero, a negative, and a typo all as null, so falling back on
        // null alone would turn "keep nothing" — and, worse, a mistyped window —
        // into pruning at somebody else's thirty days. Switching this off has to
        // mean off, which is what the config comment and the README promise.
        $days = null === config( 'artisanpack.bookings.retention.webhook_delivery_days' )
            ? $this->retentionDays( 'webhooks.delivery_retention_days' )
            : $this->retentionDays( 'retention.webhook_delivery_days' );

        if ( null === $days ) {
            $this->warn( __( 'No webhook delivery retention window is configured, so nothing was pruned.' ) );

            return self::SUCCESS;
        }

        $cutoff = $this->pruneCutoff( $days );

        $stale = WebhookDelivery::query()
            ->where( 'created_at', '<', $cutoff )
            ->where( 'status', '!=', WebhookDeliveryStatus::Pending->value );

        $this->reportStuck( $cutoff );

        if ( $this->option( 'dry-run' ) ) {
            $this->info( __(
                ':count webhook delivery attempt(s) would be removed.',
                [ 'count' => $stale->count() ],
            ) );

            return self::SUCCESS;
        }

        $this->info( __(
            ':count webhook delivery attempt(s) removed.',
            [ 'count' => $this->pruneMatching( $stale ) ],
        ) );

        return self::SUCCESS;
    }

    /**
     * Says how many pending deliveries have outlived the retention window.
     *
     * Exempting them from the prune is deliberate — see the class docblock —
     * but exempting them silently is not the same thing. A `payload` holds the
     * customer's name, their email, and their appointment, so a delivery stuck
     * pending keeps that data indefinitely, for exactly the rows nobody is
     * watching. That is the retention window quietly not applying, and the whole
     * argument for keeping the row is that somebody should go and look at it.
     *
     * Reported rather than pruned on a longer secondary window, because the row
     * is evidence of a queue that has stopped working and a second window would
     * eventually destroy it. Booking erasure still deletes these payloads
     * directly, so this is the clock-based path only.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable  $cutoff  The retention cutoff.
     *
     * @return void
     */
    protected function reportStuck( CarbonImmutable $cutoff ): void
    {
        $stuck = WebhookDelivery::query()
            ->where( 'created_at', '<', $cutoff )
            ->where( 'status', WebhookDeliveryStatus::Pending->value )
            ->count();

        if ( 0 === $stuck ) {
            return;
        }

        $this->warn( __(
            ':count pending delivery attempt(s) are past the retention window and were kept. Check the queue.',
            [ 'count' => $stuck ],
        ) );
    }
}
