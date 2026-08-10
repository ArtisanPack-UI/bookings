<?php

/**
 * Webhook delivery job.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Jobs;

use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use ArtisanPackUI\Bookings\Services\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

use function ceil;
use function max;

/**
 * Makes one attempt at a queued webhook delivery, and books the next one.
 *
 * ## Why the retries are not the queue's
 *
 * `$tries` is 1 and nothing in here throws, so a failure is never a failed job.
 * The retry schedule lives in `booking_webhook_deliveries` instead, and this job
 * re-queues itself with the delay that row was given.
 *
 * That is a deliberate trade. A queue's own `backoff()` would be less code, but
 * it keeps the schedule in the worker's memory of a job rather than in a row
 * anybody can read — an operator asking when a delivery will next be tried would
 * have nothing to look at, an endpoint disabled mid-schedule would keep its
 * pending retries alive inside the queue, and `failed_jobs` would fill up with
 * entries for consumers being down, which is a thing that happens rather than an
 * incident.
 *
 * ## Why it takes an id rather than the model
 *
 * The delivery ledger is retention-pruned, so a queued job can outlive the row
 * it names. {@see \Illuminate\Queue\SerializesModels} would turn that into a
 * `ModelNotFoundException` and a failed job; an id that no longer resolves is
 * simply nothing to do.
 *
 * ## Duplicate delivery
 *
 * Safe to run twice. {@see WebhookDispatcher::deliver()} returns without sending
 * for a delivery that is no longer retryable, so a queue that redelivers a job
 * whose attempt already succeeded does not send the consumer the same event
 * again.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class DispatchWebhookDelivery implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * How many times the queue may run this job.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Constructs the job.
     *
     * @since 1.0.0
     *
     * @param  int  $deliveryId  The delivery row to attempt.
     */
    public function __construct( public readonly int $deliveryId )
    {
    }

    /**
     * Attempts the delivery and queues its retry, if it earned one.
     *
     * @since 1.0.0
     *
     * @param  WebhookDispatcher  $dispatcher  The dispatcher.
     *
     * @return void
     */
    public function handle( WebhookDispatcher $dispatcher ): void
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::query()->whereKey( $this->deliveryId )->first();

        if ( null === $delivery || ! $delivery->isRetryable() ) {
            return;
        }

        $dispatcher->deliver( $delivery );

        if ( ! $delivery->isRetryable() || null === $delivery->next_attempt_at ) {
            return;
        }

        // Rounded up rather than down, and never below zero. A delay computed as
        // 59.6 seconds truncates to 59, which puts the job back on the queue
        // fractionally before the row says it is due — harmless here, but only
        // because nothing else reads that row; a retry sweep added later would
        // find it not yet due and the attempt would be silently skipped.
        $seconds = (int) ceil( max( 0, Carbon::now()->diffInSeconds( $delivery->next_attempt_at, false ) ) );

        self::dispatch( $this->deliveryId )
            ->onQueue( WebhookDispatcher::queueName() )
            ->delay( $seconds );
    }
}
