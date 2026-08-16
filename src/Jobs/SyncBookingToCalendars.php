<?php

/**
 * Booking calendar sync job.
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

use ArtisanPackUI\Bookings\Services\CalendarSyncOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Writes one booking to one connected calendar, retrying transient failures.
 *
 * One job per connection rather than one per booking, so a calendar that is slow
 * or down holds up only its own write and a provider's other calendars are
 * unaffected. {@see CalendarSyncOrchestrator::sync()} dispatches these — and
 * fires the `ap.bookings.calendarSync.pushing` hook — so that the hook announces
 * a push once however many times the write below is retried.
 *
 * ## Why the retries are the queue's
 *
 * Unlike {@see DispatchWebhookDelivery}, which keeps its schedule in a row, this
 * job leans on the queue's own retry: a calendar sync has no ledger an operator
 * reads a "next attempt" out of, and the schedule being short and in-memory is
 * exactly right for a transient 500 from Google. {@see backoff()} spaces the
 * attempts out exponentially; {@see $tries} bounds them.
 *
 * The count of *consecutive* failures that disables a connection is deliberately
 * not this job's retries. Those retries are one push flapping; the streak is many
 * pushes failing in a row. So {@see handle()} lets a failure propagate and the
 * queue retry it, and only {@see failed()} — reached once the retries are spent —
 * counts the push against the connection.
 *
 * ## Why it takes ids rather than models
 *
 * The calendar-event ledger is retention-pruned and a booking can be cancelled
 * out from under a queued job, so a serialized model could resolve to a row that
 * is gone and fail the job on a `ModelNotFoundException`. Ids that no longer
 * resolve are simply nothing to do, which the orchestrator treats as a no-op.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SyncBookingToCalendars implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * How many times the queue may run this job.
     *
     * One first attempt plus one per {@see backoff()} entry, so the job is tried
     * up to four times before {@see failed()} counts the push as spent.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $tries = 4;

    /**
     * Constructs the job.
     *
     * @since 1.0.0
     *
     * @param  int  $bookingId  The booking to write out.
     * @param  int  $connectionId  The connection to write it through.
     */
    public function __construct(
        public readonly int $bookingId,
        public readonly int $connectionId,
    ) {
    }

    /**
     * Gets the delays between attempts, in seconds.
     *
     * Exponential, so a calendar that is briefly unreachable is retried soon and
     * one that is having a longer outage is not hammered: one minute, then two,
     * then four.
     *
     * @since 1.0.0
     *
     * @return array<int, int> The delays, in attempt order.
     */
    public function backoff(): array
    {
        return [ 60, 120, 240 ];
    }

    /**
     * Writes the booking to the calendar, letting a failure become a retry.
     *
     * @since 1.0.0
     *
     * @param  CalendarSyncOrchestrator  $orchestrator  The orchestrator.
     *
     * @return void
     */
    public function handle( CalendarSyncOrchestrator $orchestrator ): void
    {
        $orchestrator->push( $this->bookingId, $this->connectionId );
    }

    /**
     * Counts the push against the connection once its retries are spent.
     *
     * Resolved from the container rather than injected, so an application that has
     * rebound the orchestrator gets its own on the failure path as well as the
     * happy one.
     *
     * @since 1.0.0
     *
     * @param  Throwable  $exception  What the final attempt failed with.
     *
     * @return void
     */
    public function failed( Throwable $exception ): void
    {
        app( CalendarSyncOrchestrator::class )->recordFailure( $this->connectionId );
    }
}
