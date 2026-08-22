<?php

/**
 * Booking calendar removal job.
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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes one booking's event from one connected calendar, retrying transient
 * failures.
 *
 * The counterpart to {@see SyncBookingToCalendars}: that one writes a booking to
 * a provider's calendar, this one takes it off. It is dispatched by
 * {@see CalendarSyncOrchestrator::unsync()} when a booking is reassigned, once
 * per calendar the previous provider had it on, so a slow or unreachable calendar
 * holds up only its own removal.
 *
 * ## Why the retries are the queue's
 *
 * As with the sync job, a transient failure from the calendar API is worth
 * retrying on a short exponential backoff, and there is no ledger an operator
 * reads a "next attempt" out of. {@see backoff()} spaces the attempts out;
 * {@see $tries} bounds them.
 *
 * ## Why a spent removal is not counted against the connection
 *
 * Unlike a spent *sync*, a spent removal does not disable the connection. A
 * connection whose writes keep failing is one an operator wants pulled out of
 * the rotation; a delete that will not land is a stale event left on a calendar,
 * which is a smaller problem that disabling the connection would not fix. So
 * {@see failed()} records the miss and stops, rather than counting it toward the
 * failure streak — and the ledger row is deliberately left in place by
 * {@see CalendarSyncOrchestrator::remove()} until a delete succeeds, so nothing
 * forgets the event still needs removing.
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
class RemoveBookingFromCalendars implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * How many times the queue may run this job.
     *
     * One first attempt plus one per {@see backoff()} entry, so the job is tried
     * up to four times before {@see failed()} records the removal as spent.
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
     * @param  int  $bookingId  The booking to take off the calendar.
     * @param  int  $connectionId  The connection to take it off.
     */
    public function __construct(
        public readonly int $bookingId,
        public readonly int $connectionId,
    ) {
    }

    /**
     * Gets the delays between attempts, in seconds.
     *
     * Exponential, matching {@see SyncBookingToCalendars::backoff()}: one minute,
     * then two, then four.
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
     * Removes the booking from the calendar, letting a failure become a retry.
     *
     * @since 1.0.0
     *
     * @param  CalendarSyncOrchestrator  $orchestrator  The orchestrator.
     *
     * @return void
     */
    public function handle( CalendarSyncOrchestrator $orchestrator ): void
    {
        $orchestrator->remove( $this->bookingId, $this->connectionId );
    }

    /**
     * Records that the removal could not be delivered once its retries are spent.
     *
     * @since 1.0.0
     *
     * @param  Throwable  $exception  What the final attempt failed with.
     *
     * @return void
     */
    public function failed( Throwable $exception ): void
    {
        Log::warning( 'A booking could not be removed from a connected calendar.', [
            'booking_id'    => $this->bookingId,
            'connection_id' => $this->connectionId,
            'exception'     => $exception->getMessage(),
        ] );
    }
}
