<?php

/**
 * Calendar sync orchestrator.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Services;

use ArtisanPackUI\Bookings\Contracts\CalendarDriverRegistry;
use ArtisanPackUI\Bookings\Enums\CalendarSyncMode;
use ArtisanPackUI\Bookings\Events\CalendarSynced;
use ArtisanPackUI\Bookings\Events\CalendarSyncFailed;
use ArtisanPackUI\Bookings\Jobs\RemoveBookingFromCalendars;
use ArtisanPackUI\Bookings\Jobs\SyncBookingToCalendars;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\CalendarEvent;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pushes a booking out to every calendar its provider has connected.
 *
 * The cross-cutting concerns a single driver has no business carrying — retrying
 * a transient failure, counting the streak of real ones, disabling a connection
 * that has stopped answering, downgrading a two-way connection whose inbound side
 * has gone quiet — live here, once, rather than being re-implemented by every
 * driver package that ships.
 *
 * ## The retry seam
 *
 * A push is dispatched as one {@see SyncBookingToCalendars} job per connection,
 * and it is the job — not this service and not the driver — that carries the
 * retry schedule. This service is called from two ends of that job: {@see push()}
 * from its `handle()` on each attempt, and {@see recordFailure()} from its
 * `failed()` once the attempts are spent. Keeping both ends here is what lets the
 * failure counting be about a connection that is *gone* rather than one attempt
 * that timed out.
 *
 * ## The pushing hook fires once per logical push
 *
 * `ap.bookings.calendarSync.pushing` is fired by {@see sync()} as the job is
 * dispatched, once per connection, so a booking pushed to two calendars fires it
 * twice and a booking whose push is retried three times still fires it once. A
 * driver that additionally relays its own `pushing` from inside a write is doing
 * so for callers that reach it directly; the canonical per-logical-push fire is
 * this one, and it is the one the retry schedule cannot multiply.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class CalendarSyncOrchestrator
{
    /**
     * How much of a driver's error message is kept on the connection row.
     *
     * Far more than any operator-facing message needs, and far short of what a
     * third-party driver echoing an entire API response body would otherwise
     * write into the row.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const ERROR_MESSAGE_LIMIT = 2000;

    /**
     * Constructs the orchestrator.
     *
     * @since 1.0.0
     *
     * @param  CalendarDriverRegistry  $registry  The registry a connection's
     *                                            driver is resolved through.
     */
    public function __construct( protected CalendarDriverRegistry $registry )
    {
    }

    /**
     * Dispatches a sync job for every calendar the booking should be written to.
     *
     * A connection is written to only while it is active and its mode writes
     * events at all — an `off` connection is one somebody switched off — and only
     * when a driver is actually installed to carry it, since a connection whose
     * driver package is absent has nothing that could push it.
     *
     * `ap.bookings.calendarSync.pushing` is fired here, as the job is queued,
     * rather than inside the job: the job is retried, and firing it there would
     * announce a push per attempt instead of per push. Firing it beside the
     * dispatch keeps it to one per connection however many times the attempt is
     * retried.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to write out.
     *
     * @return void
     */
    public function sync( Booking $booking ): void
    {
        $provider = $booking->provider;

        if ( null === $provider ) {
            return;
        }

        $connections = $provider->calendarConnections()
            ->active()
            ->get()
            ->filter( static fn ( CalendarConnection $connection ): bool => $connection->sync_mode->writesEvents() );

        // Resolved once rather than per connection: reading the registry runs the
        // `ap.bookings.calendarSync.providers` filter, and a provider with several
        // connections would otherwise re-run every subscriber once per row.
        $drivers = $this->registry->all();

        foreach ( $connections as $connection ) {
            $slug = $connection->driver->value;

            if ( ! isset( $drivers[ $slug ] ) ) {
                continue;
            }

            doAction( 'ap.bookings.calendarSync.pushing', $booking, $slug );

            SyncBookingToCalendars::dispatch( (int) $booking->getKey(), (int) $connection->getKey() );
        }
    }

    /**
     * Dispatches a removal job for every calendar a former provider had a booking on.
     *
     * Called when a booking is reassigned: the booking now carries its new
     * provider, and {@see sync()} writes it out to their calendars, but the event
     * on the *previous* provider's calendars is still there pointing at an
     * appointment that has moved off their diary. This takes it back off.
     *
     * The stale events are found through the ledger rather than by asking the
     * previous provider for its connections: the ledger is what records that this
     * booking was ever written to a given connection, and a connection since
     * switched off still holds an event that ought to come down. Connections are
     * looked up across every site because the booking's own site is the previous
     * provider's too, and the primary key resolves them either way.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking that moved.
     * @param  int  $previousProviderId  The provider it moved away from.
     *
     * @return void
     */
    public function unsync( Booking $booking, int $previousProviderId ): void
    {
        $connectionIds = CalendarConnection::query()
            ->acrossAllSites()
            ->where( 'provider_id', $previousProviderId )
            ->pluck( 'id' );

        if ( $connectionIds->isEmpty() ) {
            return;
        }

        $events = CalendarEvent::query()
            ->where( 'booking_id', $booking->getKey() )
            ->whereIn( 'connection_id', $connectionIds )
            ->get();

        foreach ( $events as $event ) {
            RemoveBookingFromCalendars::dispatch( (int) $booking->getKey(), (int) $event->connection_id );
        }
    }

    /**
     * Makes one attempt at writing a booking to one connection's calendar.
     *
     * Called from the job's `handle()`, so a throw here is the job's failure and
     * the queue's cue to retry it on the backoff schedule. A first write for a
     * booking creates the event; a later one updates the event already recorded,
     * which is what keeps a rescheduled booking from stacking a second event on
     * the calendar.
     *
     * A success clears the connection's failure streak — the streak is for
     * *consecutive* failures, and one that reaches this line has just proven the
     * connection answers. A failure fires {@see CalendarSyncFailed}, which fires
     * on every attempt including retried ones, and records the error on the
     * connection for an operator to read before re-throwing so the queue can
     * retry.
     *
     * Both the booking and the connection are looked up across every site,
     * because the job runs on a queue worker with no request to resolve one from,
     * and an id that no longer resolves is simply nothing to sync.
     *
     * @since 1.0.0
     *
     * @param  int  $bookingId  The booking to write out.
     * @param  int  $connectionId  The connection to write it through.
     *
     * @throws Throwable When the driver could not write the event.
     *
     * @return void
     */
    public function push( int $bookingId, int $connectionId ): void
    {
        $booking    = Booking::query()->acrossAllSites()->whereKey( $bookingId )->first();
        $connection = CalendarConnection::query()->acrossAllSites()->whereKey( $connectionId )->first();

        if ( null === $booking || null === $connection || $connection->isDisabled() ) {
            return;
        }

        // The job carries a raw pair of ids, and only writing a booking to a
        // calendar its own provider connected makes sense — a mismatched pair,
        // from a mis-dispatched or future caller, would otherwise write one
        // provider's appointment onto another's diary. sync() always pairs them
        // correctly, so this only ever guards against a caller that does not.
        if ( $connection->provider_id !== $booking->provider_id ) {
            return;
        }

        $driver = $this->registry->get( $connection->driver->value );

        if ( null === $driver ) {
            return;
        }

        $event = CalendarEvent::query()
            ->where( 'booking_id', $booking->getKey() )
            ->where( 'connection_id', $connection->getKey() )
            ->first();

        try {
            $externalEventId = null === $event
                ? $driver->createEvent( $connection, $booking )
                : $driver->updateEvent( $connection, $booking, $event->external_event_id );
        } catch ( Throwable $failure ) {
            // Scrubbed and capped before it is broadcast or stored: the message
            // comes from a driver this package does not own, and a third-party
            // one that embeds a raw API response could carry calendar PII or an
            // invalid byte sequence that a utf8mb4 column would reject from
            // inside this very failure path.
            $reason = self::sanitizeError( $failure->getMessage() );

            CalendarSyncFailed::dispatch( $connection, $reason, $booking );

            $this->writeLastError( $connection, $reason );

            throw $failure;
        }

        $this->recordEvent( $booking, $connection, $externalEventId );
        $this->clearFailureStreak( $connection );

        CalendarSynced::dispatch( $connection, $booking, $externalEventId );
    }

    /**
     * Makes one attempt at taking a booking off one connection's calendar.
     *
     * Called from {@see RemoveBookingFromCalendars}'s `handle()`, so a throw here
     * is the job's failure and the queue's cue to retry it on the backoff
     * schedule. Nothing to remove — no ledger row, a missing connection, or a
     * driver package that is no longer installed — is a no-op rather than an
     * error: the event either was never written or cannot be reached, and neither
     * is a failure to retry.
     *
     * The ledger row is deleted only after the driver has confirmed the event is
     * gone. A delete that throws leaves the row in place, so a later attempt still
     * knows the event needs removing — the opposite of {@see push()}, which
     * records the row on success; here success is the row's disappearance.
     *
     * The connection is looked up across every site, because the job runs on a
     * queue worker with no request to resolve one from.
     *
     * @since 1.0.0
     *
     * @param  int  $bookingId  The booking to take off the calendar.
     * @param  int  $connectionId  The connection to take it off.
     *
     * @throws Throwable When the driver could not delete the event.
     *
     * @return void
     */
    public function remove( int $bookingId, int $connectionId ): void
    {
        $connection = CalendarConnection::query()->acrossAllSites()->whereKey( $connectionId )->first();

        if ( null === $connection ) {
            return;
        }

        $event = CalendarEvent::query()
            ->where( 'booking_id', $bookingId )
            ->where( 'connection_id', $connectionId )
            ->first();

        if ( null === $event ) {
            return;
        }

        $driver = $this->registry->get( $connection->driver->value );

        if ( null === $driver ) {
            return;
        }

        $driver->deleteEvent( $connection, $event->external_event_id );

        $event->delete();
    }

    /**
     * Counts a spent push against a connection and acts on the streak.
     *
     * Called from the job's `failed()`, once the retries are gone — so the count
     * moves one per push that genuinely could not be delivered rather than once
     * per attempt, and a connection is disabled for being gone rather than for a
     * single slow night.
     *
     * Two thresholds sit on that count. At `connection_failure_threshold` the
     * connection is disabled outright and stops being synced. Short of that, a
     * two-way connection that has gone `two_way_grace_hours` without a successful
     * sync is downgraded to outbound instead: a dead two-way connection keeps
     * reading busy blocks back and would over-block the provider's availability
     * on the strength of a calendar nobody can reach, and dropping it to outbound
     * stops that while still trying to write.
     *
     * The message the final attempt failed with is not taken here: {@see push()}
     * already recorded it on the connection's `last_sync_error` on the way past,
     * and the reason attached to a disable or downgrade is the one worth reading
     * at that point — the streak, or the grace window — not the last transient
     * error under it.
     *
     * @since 1.0.0
     *
     * @param  int  $connectionId  The connection whose push failed.
     *
     * @return void
     */
    public function recordFailure( int $connectionId ): void
    {
        $connection = CalendarConnection::query()->acrossAllSites()->whereKey( $connectionId )->first();

        if ( null === $connection || $connection->isDisabled() ) {
            return;
        }

        $connection->newQueryWithoutScopes()
            ->whereKey( $connection->getKey() )
            ->increment( 'consecutive_failure_count' );

        $connection->refresh();

        $threshold = self::failureThreshold();

        if ( $threshold > 0 && $connection->consecutive_failure_count >= $threshold ) {
            $disableReason = sprintf(
                'The calendar connection failed %d consecutive syncs.',
                $connection->consecutive_failure_count,
            );

            if ( $connection->disable( $disableReason ) ) {
                doAction( 'ap.bookings.calendarSync.connectionDisabled', $connection, $disableReason );
            }

            return;
        }

        $this->downgradeStaleTwoWay( $connection );
    }

    /**
     * Downgrades a two-way connection that has been broken past the grace window.
     *
     * The window is measured from the last successful sync, or from when the
     * connection was created if it has never had one — a connection that has
     * never once synced and is still failing hours later is exactly as broken as
     * one that stopped. The write is conditional on the mode still being two-way,
     * so two spent pushes racing to downgrade the same connection produce one
     * change and one hook between them.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection that failed.
     *
     * @return void
     */
    protected function downgradeStaleTwoWay( CalendarConnection $connection ): void
    {
        if ( CalendarSyncMode::TwoWay !== $connection->sync_mode ) {
            return;
        }

        $graceHours = self::graceHours();
        $baseline   = $connection->last_sync_at ?? $connection->created_at;

        if ( null === $baseline || ! $baseline->copy()->addHours( $graceHours )->isPast() ) {
            return;
        }

        $downgraded = $connection->newQueryWithoutScopes()
            ->whereKey( $connection->getKey() )
            ->where( 'sync_mode', CalendarSyncMode::TwoWay->value )
            ->update( [ 'sync_mode' => CalendarSyncMode::Outbound->value ] );

        if ( 1 !== $downgraded ) {
            return;
        }

        $connection->refresh();

        $reason = sprintf(
            'The two-way calendar connection was downgraded to outbound after %d hours without a successful sync.',
            $graceHours,
        );

        doAction( 'ap.bookings.calendarSync.connectionDisabled', $connection, $reason );
    }

    /**
     * Records or refreshes the ledger row for a booking on a connection.
     *
     * Keyed on the booking and the connection together, so a rescheduled booking
     * updates the one row it already owns rather than growing a second — the same
     * property the driver's writes are held to, kept in the ledger this service
     * owns.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking that was written.
     * @param  CalendarConnection  $connection  The connection it was written through.
     * @param  string  $externalEventId  The identifier the calendar assigned.
     *
     * @return void
     */
    protected function recordEvent( Booking $booking, CalendarConnection $connection, string $externalEventId ): void
    {
        CalendarEvent::query()->updateOrCreate(
            [
                'booking_id'    => $booking->getKey(),
                'connection_id' => $connection->getKey(),
            ],
            [
                'external_event_id' => $externalEventId,
                'last_synced_at'    => Carbon::now(),
                'sync_error'        => null,
            ],
        );
    }

    /**
     * Clears a connection's failure streak after a successful sync.
     *
     * Written straight to the row rather than through the loaded model, so a
     * worker holding a stale copy of the connection does not write an old failure
     * count back over a concurrent success's reset.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection that answered.
     *
     * @return void
     */
    protected function clearFailureStreak( CalendarConnection $connection ): void
    {
        $now = Carbon::now();

        $connection->newQueryWithoutScopes()
            ->whereKey( $connection->getKey() )
            ->update( [
                'consecutive_failure_count' => 0,
                'last_sync_at'              => $now,
                'last_sync_error'           => null,
                'updated_at'                => $now,
            ] );

        $connection->forceFill( [
            'consecutive_failure_count' => 0,
            'last_sync_at'              => $now,
            'last_sync_error'           => null,
        ] )->syncOriginal();
    }

    /**
     * Records the latest error on a connection without counting it.
     *
     * The count belongs to {@see recordFailure()}, which runs once the attempts
     * are spent; this only keeps the most recent message where an operator can
     * read it while the retries are still in flight.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection that failed.
     * @param  string  $reason  What the attempt failed with.
     *
     * @return void
     */
    protected function writeLastError( CalendarConnection $connection, string $reason ): void
    {
        $connection->newQueryWithoutScopes()
            ->whereKey( $connection->getKey() )
            ->update( [
                'last_sync_error' => $reason,
                'updated_at'      => Carbon::now(),
            ] );
    }

    /**
     * Gets how many consecutive failures disable a connection.
     *
     * @since 1.0.0
     *
     * @return int The threshold, or zero when disabling is switched off.
     */
    protected static function failureThreshold(): int
    {
        return max( 0, (int) config( 'artisanpack.bookings.calendar.connection_failure_threshold', 5 ) );
    }

    /**
     * Gets how long a two-way connection may stay broken before it is downgraded.
     *
     * @since 1.0.0
     *
     * @return int The grace window, in hours.
     */
    protected static function graceHours(): int
    {
        return max( 0, (int) config( 'artisanpack.bookings.calendar.two_way_grace_hours', 6 ) );
    }

    /**
     * Cuts a driver's error message down to what may be safely kept.
     *
     * Scrubbed to valid UTF-8 before it is cut, because a driver answers with
     * whatever its calendar API returned — a binary blob, a page in some other
     * encoding — and a `utf8mb4` column rejects an invalid byte sequence outright.
     * That rejection would surface as a QueryException from inside the write that
     * records a *failure*, replacing the driver's error with a database one and
     * leaving the operator-facing message unrecorded. Scrubbing first and cutting
     * afterwards avoids landing the cut in the middle of a multi-byte character.
     *
     * @since 1.0.0
     *
     * @param  string  $message  The message as the driver threw it.
     *
     * @return string The stored message.
     */
    protected static function sanitizeError( string $message ): string
    {
        return mb_strimwidth(
            mb_convert_encoding( $message, 'UTF-8', 'UTF-8' ),
            0,
            self::ERROR_MESSAGE_LIMIT,
            '…',
        );
    }
}
