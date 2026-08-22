<?php

/**
 * Retry webhook deliveries command.
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

use ArtisanPackUI\Bookings\Jobs\DispatchWebhookDelivery;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use ArtisanPackUI\Bookings\Services\WebhookDispatcher;
use Illuminate\Console\Command;

/**
 * Re-queues webhook deliveries that are due but have no attempt in flight.
 *
 * The retry chain re-queues itself: a failed attempt writes the next
 * `next_attempt_at` and dispatches the follow-up job. A worker that dies between
 * those two — after the `failed` row is written, before the follow-up is queued
 * — strands the delivery: it sits `failed` with a `next_attempt_at` in the past
 * and nothing coming to pick it up. A delivery whose first, immediate dispatch
 * was lost the same way sits `pending` with no `next_attempt_at` at all.
 *
 * This sweep is the backstop that closes both. It finds the deliveries
 * {@see WebhookDelivery::scopeDue()} matches — the same "due now" question the
 * retry chain answers per attempt — and dispatches a job for each. It does not
 * claim the rows itself: {@see DispatchWebhookDelivery} claims before it attempts
 * anything, so a delivery that already has an attempt in flight is claimed by
 * that attempt and the job this sweep queued simply steps aside. The claim, not
 * the sweep, is what stops a consumer being sent the same event twice.
 *
 * Scheduled every few minutes. It reads across every site, because it runs from
 * cron where no site is resolved and a delivery belongs to whichever tenant its
 * endpoint does.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class RetryWebhookDeliveriesCommand extends Command
{
    /**
     * How many due deliveries are read into memory at a time.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const CHUNK = 500;

    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:retry-webhook-deliveries';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Re-queues webhook deliveries that are due but have no attempt in flight.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @return int The command exit code.
     */
    public function handle(): int
    {
        $queued = 0;

        WebhookDelivery::query()
            ->due()
            ->orderBy( 'id' )
            ->chunkById( self::CHUNK, function ( $deliveries ) use ( &$queued ): void {
                foreach ( $deliveries as $delivery ) {
                    DispatchWebhookDelivery::dispatch( (int) $delivery->getKey() )
                        ->onQueue( WebhookDispatcher::queueName() );

                    $queued++;
                }
            } );

        $this->info( 0 === $queued
            ? __( 'No webhook deliveries are due to be retried.' )
            : __( ':count webhook delivery(s) were re-queued.', [ 'count' => $queued ] ) );

        return self::SUCCESS;
    }
}
