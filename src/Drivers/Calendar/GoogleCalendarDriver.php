<?php

/**
 * Google Calendar two-way sync driver.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Drivers\Calendar;

use ArtisanPackUI\Bookings\Contracts\CalendarSyncDriver;
use ArtisanPackUI\Bookings\Contracts\GoogleTokenProvider;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Exceptions\CalendarSyncException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\CalendarWatchChannel;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * The Google Calendar driver: outbound writes, push channels, and two-way sync.
 *
 * Google is the majority case, and the first driver that talks to a real
 * external calendar, so it exercises every corner of the two-way flow the
 * read-only {@see IcalFeedDriver} never reaches:
 *
 * - **Outbound.** {@see self::createEvent()}, {@see self::updateEvent()}, and
 *   {@see self::deleteEvent()} write the booking to the connected calendar. The
 *   external identifier is derived from the booking, not counted, so a write
 *   retried after a timeout lands on the event it already made rather than a
 *   second copy — the idempotency the contract requires and cannot enforce.
 * - **Push channels.** {@see self::subscribeToChanges()} registers a watch on
 *   the calendar so Google tells us when something moves, and
 *   {@see self::renewSubscription()} replaces it before its roughly seven-day
 *   life runs out — Google does not extend a channel, so a renewal is a fresh
 *   watch and a stop of the old one.
 * - **Incremental sync.** {@see self::incrementalSync()} walks the change feed
 *   from the connection's `sync_token` and turns what Google returns into busy
 *   blocks. When Google answers `410 Gone` — the token is too old to resume from
 *   — it falls back to a full resync bounded by `calendar.two_way_lookahead_days`
 *   so the recovery reads a fixed window and cannot loop.
 *
 * This driver ships here but runs on OAuth it does not own: every call is
 * authorised by a token from {@see GoogleTokenProvider}, bound only when
 * `artisanpack-ui/google` is installed. The package has no compile-time
 * dependency on Google's OAuth, and an installation without it never resolves
 * this driver at all.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class GoogleCalendarDriver implements CalendarSyncDriver
{
    /**
     * The Google Calendar API root.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected const API_BASE = 'https://www.googleapis.com/calendar/v3';

    /**
     * The prefix on every event identifier this driver assigns.
     *
     * Both makes a write idempotent — the identifier is a pure function of the
     * booking — and lets inbound sync recognise the events it wrote itself, so
     * ingesting a change feed does not fold this connection's own bookings back
     * in as busy blocks on top of the bookings they already are.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected const EVENT_ID_PREFIX = 'apbk';

    /**
     * How long a push channel is assumed to last when Google names no expiry.
     *
     * Google returns an explicit expiration on every watch, and this is only the
     * floor under a malformed response: roughly the seven-day maximum a channel
     * is granted, so a missing value still leaves a row the renewal sweep will
     * revisit rather than one that reads as never expiring.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const WATCH_FALLBACK_TTL_DAYS = 7;

    /**
     * The most change-feed pages a single sync will read.
     *
     * A backstop, not a working limit: incremental sync is bounded by the token
     * and a full resync by its time window, so neither reaches this in practice.
     * It exists so a pathological `nextPageToken` that never terminates cannot
     * spin a queue worker forever.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const MAX_PAGES = 100;

    /**
     * Constructs the driver.
     *
     * @since 1.0.0
     *
     * @param  GoogleTokenProvider  $tokens  Supplies a current access token for
     *                                       each connection, without this driver
     *                                       knowing how OAuth stores or refreshes
     *                                       it.
     */
    public function __construct(
        protected GoogleTokenProvider $tokens,
    ) {
    }

    /**
     * Gets the calendar system this driver talks to.
     *
     * @since 1.0.0
     *
     * @return CalendarDriver Always the Google system.
     */
    public function driver(): CalendarDriver
    {
        return CalendarDriver::Google;
    }

    /**
     * Creates the external event for a booking.
     *
     * The event is inserted under an identifier derived from the booking, so a
     * retried create is a no-op: Google answers `409` for the identifier that
     * already exists, and that is read as the success it is.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  Booking  $booking  The booking to represent on the calendar.
     *
     * @throws CalendarSyncException When Google refuses or is unreachable.
     *
     * @return string The identifier the event was written under.
     */
    public function createEvent( CalendarConnection $connection, Booking $booking ): string
    {
        $externalEventId = $this->eventIdFor( $booking );

        $body     = $this->buildEvent( $booking );
        $response = $this->send( $connection, fn ( PendingRequest $http ): Response => $http
            ->post( $this->eventsUrl( $connection ), $body + [ 'id' => $externalEventId ] ) );

        if ( ! $response->successful() && 409 !== $response->status() ) {
            throw $this->failed( 'create the event for', $booking, $response );
        }

        doAction( 'ap.bookings.calendarSync.pushed', $booking, $this->driver()->value, $externalEventId );

        return $externalEventId;
    }

    /**
     * Updates the external event for a booking that has changed.
     *
     * The identifier passed in is trusted, but the event behind it may have been
     * deleted on the calendar since it was written. Rather than fail an update
     * the caller expects to leave one event in place, a `404`/`410` re-inserts
     * under the same identifier — so the calendar ends up correct whether the
     * event was there to update or not.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  Booking  $booking  The booking in its current state.
     * @param  string  $externalEventId  The event to update.
     *
     * @throws CalendarSyncException When Google refuses or is unreachable.
     *
     * @return string The event identifier after the update.
     */
    public function updateEvent( CalendarConnection $connection, Booking $booking, string $externalEventId ): string
    {
        $body     = $this->buildEvent( $booking );
        $response = $this->send( $connection, fn ( PendingRequest $http ): Response => $http
            ->put( $this->eventUrl( $connection, $externalEventId ), $body ) );

        if ( $this->isGone( $response ) ) {
            $response = $this->send( $connection, fn ( PendingRequest $http ): Response => $http
                ->post( $this->eventsUrl( $connection ), $body + [ 'id' => $externalEventId ] ) );
        }

        if ( ! $response->successful() && 409 !== $response->status() ) {
            throw $this->failed( 'update the event for', $booking, $response );
        }

        doAction( 'ap.bookings.calendarSync.pushed', $booking, $this->driver()->value, $externalEventId );

        return $externalEventId;
    }

    /**
     * Removes an external event.
     *
     * Deleting an event that is already gone is a success: the caller's intent is
     * that the event not exist, and a `404`/`410` means it does not.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  string  $externalEventId  The event to remove.
     *
     * @throws CalendarSyncException When Google refuses or is unreachable.
     *
     * @return void
     */
    public function deleteEvent( CalendarConnection $connection, string $externalEventId ): void
    {
        $response = $this->send( $connection, fn ( PendingRequest $http ): Response => $http
            ->delete( $this->eventUrl( $connection, $externalEventId ) ) );

        if ( $response->successful() || $this->isGone( $response ) ) {
            return;
        }

        throw new CalendarSyncException( sprintf(
            'Google refused to delete event %s on calendar connection %d (HTTP %d).',
            $externalEventId,
            $connection->getKey(),
            $response->status(),
        ) );
    }

    /**
     * Reads back the periods the external calendar considers busy.
     *
     * Events the driver wrote itself are left out: they represent this
     * connection's own bookings, which already occupy their slots, and folding
     * them back in as busy blocks would double-count them. Events marked free —
     * Google's `transparent` transparency — are left out too, because a free
     * event is exactly one that does not suppress availability.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to read from.
     * @param  TimeRange  $window  The span of time to read.
     *
     * @throws CalendarSyncException When Google refuses or is unreachable.
     *
     * @return array<int, TimeRange> The busy periods, in UTC.
     */
    public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array
    {
        $periods   = [];
        $pageToken = null;
        $pages     = 0;

        do {
            $params   = array_filter( [
                'timeMin'      => $window->start->toRfc3339String(),
                'timeMax'      => $window->end->toRfc3339String(),
                'singleEvents' => 'true',
                'showDeleted'  => 'false',
                'maxResults'   => 2500,
                'pageToken'    => $pageToken,
            ] );
            $response = $this->send( $connection, fn ( PendingRequest $http ): Response => $http
                ->get( $this->eventsUrl( $connection ), $params ) );

            if ( ! $response->successful() ) {
                throw new CalendarSyncException( sprintf(
                    'Google refused to list events on calendar connection %d (HTTP %d).',
                    $connection->getKey(),
                    $response->status(),
                ) );
            }

            foreach ( (array) $response->json( 'items', [] ) as $event ) {
                if ( $this->isOwnEvent( $event ) ) {
                    continue;
                }

                $range = $this->busyRange( $event );

                if ( $range instanceof TimeRange ) {
                    $periods[] = $range;
                }
            }

            $pageToken = $response->json( 'nextPageToken' );
        } while ( is_string( $pageToken ) && '' !== $pageToken && ++$pages < self::MAX_PAGES );

        return $periods;
    }

    /**
     * Registers a push channel so Google reports changes to this calendar.
     *
     * The callback URL is passed in rather than resolved here: where an inbound
     * notification is received is the caller's concern, not the driver's. Google
     * answers with a resource identifier and an expiry, both recorded so the
     * inbound webhook can find the connection a notification belongs to and the
     * renewal sweep can replace the channel before it lapses.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to watch.
     * @param  string  $callbackUrl  Where Google should post notifications.
     *
     * @throws CalendarSyncException When Google refuses or is unreachable.
     *
     * @return CalendarWatchChannel The stored registration.
     */
    public function subscribeToChanges( CalendarConnection $connection, string $callbackUrl ): CalendarWatchChannel
    {
        $registration = $this->watch( $connection, $callbackUrl );

        return $connection->watchChannels()->create( $registration );
    }

    /**
     * Replaces a push channel that is expiring or has expired.
     *
     * Google has no notion of extending a channel, so a renewal is a new watch
     * and a stop of the old one. The existing row is updated in place rather than
     * replaced, and the old channel is stopped on a best-effort basis — a stop
     * that fails is not worth failing the renewal for, because the old channel
     * expires on its own regardless.
     *
     * @since 1.0.0
     *
     * @param  CalendarWatchChannel  $channel  The registration to replace.
     * @param  string  $callbackUrl  Where Google should post notifications.
     *
     * @throws CalendarSyncException When Google refuses to register the new
     *                               channel or is unreachable.
     *
     * @return CalendarWatchChannel The renewed registration.
     */
    public function renewSubscription( CalendarWatchChannel $channel, string $callbackUrl ): CalendarWatchChannel
    {
        $connection = $channel->connection()->first();

        if ( ! $connection instanceof CalendarConnection ) {
            throw new CalendarSyncException( sprintf(
                'Watch channel %d has no connection to renew against.',
                $channel->getKey(),
            ) );
        }

        $previousChannelId  = $channel->channel_id;
        $previousResourceId = $channel->resource_id;

        $registration = $this->watch( $connection, $callbackUrl );

        $channel->update( $registration );

        $this->stopChannel( $connection, $previousChannelId, $previousResourceId );

        return $channel;
    }

    /**
     * Pulls the changes Google has recorded into busy blocks.
     *
     * Walks the change feed from the connection's stored `sync_token`, or does a
     * full read when there is none. Every page is offered to
     * `ap.bookings.calendarSync.pullReceived` as Google sent it, before any
     * normalisation, so a consumer sees the raw payload. Each event then becomes,
     * updates, or removes a busy block; the `nextSyncToken` Google returns is
     * saved so the next run resumes where this one stopped.
     *
     * A `410 Gone` on the token means it is too old to resume from. The token is
     * dropped and a full resync runs in its place — bounded by
     * `calendar.two_way_lookahead_days`, so the recovery reads a fixed window and
     * cannot turn into an unbounded loop.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to read.
     *
     * @throws CalendarSyncException When Google refuses for any reason other than
     *                               a stale token, or is unreachable.
     *
     * @return void
     */
    public function incrementalSync( CalendarConnection $connection ): void
    {
        $syncToken = $connection->sync_token;

        if ( null === $syncToken || '' === $syncToken ) {
            $this->fullResync( $connection );

            return;
        }

        $pageToken     = null;
        $pages         = 0;
        $nextSyncToken = null;

        do {
            $params   = array_filter( [
                'syncToken'    => $syncToken,
                'singleEvents' => 'true',
                'showDeleted'  => 'true',
                'maxResults'   => 2500,
                'pageToken'    => $pageToken,
            ] );
            $response = $this->send( $connection, fn ( PendingRequest $http ): Response => $http
                ->get( $this->eventsUrl( $connection ), $params ) );

            // Only a 410 means the token itself is too stale to resume from. A
            // 404 is the calendar being gone — deleted, or access revoked — and
            // clearing the cursor for that would throw away a good token over a
            // problem the cursor did not cause, then fail the resync on the same
            // missing calendar anyway. Let it fall through to the failure path.
            if ( 410 === $response->status() ) {
                $this->clearSyncToken( $connection );
                $this->fullResync( $connection );

                return;
            }

            if ( ! $response->successful() ) {
                throw new CalendarSyncException( sprintf(
                    'Google refused an incremental sync on calendar connection %d (HTTP %d).',
                    $connection->getKey(),
                    $response->status(),
                ) );
            }

            $this->ingestPage( $connection, (array) $response->json() );

            $pageToken     = $response->json( 'nextPageToken' );
            $nextSyncToken = $response->json( 'nextSyncToken' ) ?? $nextSyncToken;
        } while ( is_string( $pageToken ) && '' !== $pageToken && ++$pages < self::MAX_PAGES );

        $this->saveSyncToken( $connection, is_string( $nextSyncToken ) ? $nextSyncToken : $syncToken );
    }

    /**
     * Builds the Google event body a booking is written as.
     *
     * The neutral payload is assembled and run through
     * `ap.bookings.calendarSync.eventPayload` — the same shape every driver
     * offers, so a consumer that reshapes an event does it once for all of them —
     * and only then mapped to Google's own event format.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to serialise.
     *
     * @throws UnexpectedValueException When a subscriber to
     *                                  `ap.bookings.calendarSync.eventPayload`
     *                                  returns something other than an array, or
     *                                  drops the start or end from a date.
     *
     * @return array<string, mixed> The Google event resource body.
     */
    public function buildEvent( Booking $booking ): array
    {
        /** @var array<string, mixed> $payload */
        $payload = applyFilters(
            'ap.bookings.calendarSync.eventPayload',
            $this->payloadFor( $booking ),
            $booking,
            $this->driver()->value,
        );

        if ( ! is_array( $payload ) ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.calendarSync.eventPayload must return an array, got %s.',
                get_debug_type( $payload ),
            ) );
        }

        return $this->eventBodyFrom( $payload );
    }

    /**
     * Gets the identifier a booking's event is written under.
     *
     * A pure function of the booking's key, encoded as hexadecimal so it uses
     * only the lowercase letters and digits Google allows in an event id. The
     * same booking always maps to the same id, which is what makes a retried
     * write idempotent.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to identify.
     *
     * @return string The Google event identifier.
     */
    public function eventIdFor( Booking $booking ): string
    {
        return self::EVENT_ID_PREFIX . sha1( 'booking:' . $booking->getKey() );
    }

    /**
     * Registers a watch on a connection's calendar and reads back the result.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to watch.
     * @param  string  $callbackUrl  Where Google should post notifications.
     *
     * @throws CalendarSyncException When Google refuses or is unreachable.
     *
     * @return array{channel_id: string, resource_id: string|null, expires_at: CarbonImmutable} The registration to store.
     */
    protected function watch( CalendarConnection $connection, string $callbackUrl ): array
    {
        $channelId = (string) Str::uuid();

        $response = $this->send( $connection, fn ( PendingRequest $http ): Response => $http
            ->post( $this->eventsUrl( $connection ) . '/watch', [
                'id'      => $channelId,
                'type'    => 'web_hook',
                'address' => $callbackUrl,
            ] ) );

        if ( ! $response->successful() ) {
            throw new CalendarSyncException( sprintf(
                'Google refused to register a watch channel on calendar connection %d (HTTP %d).',
                $connection->getKey(),
                $response->status(),
            ) );
        }

        $resourceId = $response->json( 'resourceId' );
        $expiration = $response->json( 'expiration' );

        return [
            'channel_id'  => $channelId,
            'resource_id' => is_string( $resourceId ) ? $resourceId : null,
            'expires_at'  => $this->expiryFrom( $expiration ),
        ];
    }

    /**
     * Tells Google to stop delivering on a channel that has been replaced.
     *
     * Best-effort: a channel with nothing to stop is skipped, and a stop that
     * fails is swallowed, because the old channel lapses on its own and a caller
     * renewing a subscription must not be handed the failure of tidying up the
     * one it just replaced.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection the channel is on.
     * @param  string|null  $channelId  The channel to stop.
     * @param  string|null  $resourceId  The resource the channel watched.
     *
     * @return void
     */
    protected function stopChannel( CalendarConnection $connection, ?string $channelId, ?string $resourceId ): void
    {
        if ( null === $channelId || null === $resourceId ) {
            return;
        }

        try {
            $this->client( $connection )->post( self::API_BASE . '/channels/stop', [
                'id'         => $channelId,
                'resourceId' => $resourceId,
            ] );
        } catch ( Throwable ) {
            // The replaced channel expires on its own; a failed stop is not worth
            // failing the renewal for.
        }
    }

    /**
     * Runs a full read of the calendar over the configured lookahead window.
     *
     * The window is what bounds it: `now` to `now + two_way_lookahead_days`, read
     * page by page, with a hard page cap underneath. Every page is offered to
     * `ap.bookings.calendarSync.pullReceived` raw, and the `nextSyncToken` Google
     * returns at the end is saved so the next run can go back to incremental.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to read.
     *
     * @throws CalendarSyncException When Google refuses or is unreachable.
     *
     * @return void
     */
    protected function fullResync( CalendarConnection $connection ): void
    {
        $lookaheadDays = (int) config( 'artisanpack.bookings.calendar.two_way_lookahead_days', 60 );
        $now           = CarbonImmutable::now()->utc();
        $window        = new TimeRange( $now, $now->addDays( max( 1, $lookaheadDays ) ) );

        $pageToken     = null;
        $pages         = 0;
        $nextSyncToken = null;

        do {
            $params   = array_filter( [
                'timeMin'      => $window->start->toRfc3339String(),
                'timeMax'      => $window->end->toRfc3339String(),
                'singleEvents' => 'true',
                'showDeleted'  => 'true',
                'maxResults'   => 2500,
                'pageToken'    => $pageToken,
            ] );
            $response = $this->send( $connection, fn ( PendingRequest $http ): Response => $http
                ->get( $this->eventsUrl( $connection ), $params ) );

            if ( ! $response->successful() ) {
                throw new CalendarSyncException( sprintf(
                    'Google refused a full resync on calendar connection %d (HTTP %d).',
                    $connection->getKey(),
                    $response->status(),
                ) );
            }

            $this->ingestPage( $connection, (array) $response->json() );

            $pageToken     = $response->json( 'nextPageToken' );
            $nextSyncToken = $response->json( 'nextSyncToken' ) ?? $nextSyncToken;
        } while ( is_string( $pageToken ) && '' !== $pageToken && ++$pages < self::MAX_PAGES );

        $this->saveSyncToken( $connection, is_string( $nextSyncToken ) ? $nextSyncToken : null );
    }

    /**
     * Turns one page of the change feed into busy blocks.
     *
     * The raw page fires `ap.bookings.calendarSync.pullReceived` first, so a
     * consumer sees exactly what Google sent. Then each event is applied: a
     * cancelled one removes any block it had, an event this driver wrote itself
     * or one marked free is ignored, and everything else is upserted on its
     * external id so a replayed change updates a block rather than duplicating it.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection being synced.
     * @param  array<string, mixed>  $page  The decoded events-list response.
     *
     * @return void
     */
    protected function ingestPage( CalendarConnection $connection, array $page ): void
    {
        doAction( 'ap.bookings.calendarSync.pullReceived', $page, $this->driver()->value );

        /** @var array<int, array<string, mixed>> $items */
        $items = (array) ( $page['items'] ?? [] );

        foreach ( $items as $event ) {
            $this->applyEvent( $connection, $event );
        }
    }

    /**
     * Applies one inbound event to the connection's busy blocks.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection being synced.
     * @param  array<string, mixed>  $event  A single Google event resource.
     *
     * @return void
     */
    protected function applyEvent( CalendarConnection $connection, array $event ): void
    {
        $externalEventId = $event['id'] ?? null;

        if ( ! is_string( $externalEventId ) || '' === $externalEventId ) {
            return;
        }

        if ( 'cancelled' === ( $event['status'] ?? null ) ) {
            $connection->busyBlocks()->where( 'external_event_id', $externalEventId )->delete();

            return;
        }

        if ( $this->isOwnEvent( $event ) ) {
            return;
        }

        $range = $this->busyRange( $event );

        if ( ! $range instanceof TimeRange ) {
            $connection->busyBlocks()->where( 'external_event_id', $externalEventId )->delete();

            return;
        }

        $connection->busyBlocks()->updateOrCreate(
            [ 'external_event_id' => $externalEventId ],
            [
                'starts_at_utc' => $range->start,
                'ends_at_utc'   => $range->end,
                'etag'          => is_string( $event['etag'] ?? null ) ? $event['etag'] : null,
            ],
        );
    }

    /**
     * Determines whether an event is one this driver wrote itself.
     *
     * Recognised by the identifier prefix every outbound write carries, so
     * reading a calendar back does not fold this connection's own bookings in as
     * busy blocks on top of the bookings they already are.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $event  A single Google event resource.
     *
     * @return bool True when the event's id is one this driver assigned.
     */
    protected function isOwnEvent( array $event ): bool
    {
        $externalEventId = $event['id'] ?? null;

        return is_string( $externalEventId ) && str_starts_with( $externalEventId, self::EVENT_ID_PREFIX );
    }

    /**
     * Reads the busy span a Google event represents, if it is one.
     *
     * Returns null for the events that do not suppress availability: an event
     * marked free, or one without a resolvable start and end. Both timed events
     * (`dateTime`) and all-day events (`date`) are understood, and both come back
     * as instants in UTC.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $event  A single Google event resource.
     *
     * @return TimeRange|null The busy span, or null when the event is free or
     *                        malformed.
     */
    protected function busyRange( array $event ): ?TimeRange
    {
        if ( 'transparent' === ( $event['transparency'] ?? null ) ) {
            return null;
        }

        $start = $this->instantFrom( $event['start'] ?? null );
        $end   = $this->instantFrom( $event['end'] ?? null );

        if ( ! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable || $end->lte( $start ) ) {
            return null;
        }

        return new TimeRange( $start, $end );
    }

    /**
     * Reads a Google event endpoint into a UTC instant.
     *
     * A timed event carries `dateTime` with an explicit offset, so parsing it
     * and normalising to UTC is unambiguous. An all-day event carries only a
     * bare `date` with no zone, and it must be anchored to UTC rather than left
     * to `Carbon::parse()`, which would read it in the application's timezone: on
     * an app configured to anything but UTC that shifts an all-day busy block by
     * the offset and corrupts availability around every day boundary.
     *
     * @since 1.0.0
     *
     * @param  mixed  $endpoint  A Google event `start` or `end` object.
     *
     * @return CarbonImmutable|null The instant, or null when there is none.
     */
    protected function instantFrom( mixed $endpoint ): ?CarbonImmutable
    {
        if ( ! is_array( $endpoint ) ) {
            return null;
        }

        $dateTime = $endpoint['dateTime'] ?? null;

        if ( is_string( $dateTime ) && '' !== $dateTime ) {
            return CarbonImmutable::parse( $dateTime )->utc();
        }

        $date = $endpoint['date'] ?? null;

        if ( is_string( $date ) && '' !== $date ) {
            return CarbonImmutable::parse( $date, 'UTC' );
        }

        return null;
    }

    /**
     * Gets the neutral payload a booking's event is built from.
     *
     * The same shape {@see IcalFeedDriver} uses, so a consumer filtering
     * `ap.bookings.calendarSync.eventPayload` reshapes one payload rather than a
     * different one per driver.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being serialised.
     *
     * @return array{uid: string, summary: string, start: CarbonImmutable, end: CarbonImmutable, status: string, description: string} The payload.
     */
    protected function payloadFor( Booking $booking ): array
    {
        return [
            'uid'         => (string) $booking->booking_number,
            'summary'     => (string) ( $booking->service?->name ?? __( 'Booking' ) ),
            'start'       => CarbonImmutable::instance( $booking->start_time )->utc(),
            'end'         => CarbonImmutable::instance( $booking->end_time )->utc(),
            'status'      => $this->statusFor( $booking ),
            'description' => sprintf( '%s: %s', __( 'Reference' ), $booking->booking_number ),
        ];
    }

    /**
     * Maps a booking to the neutral event status a driver serialises.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to read.
     *
     * @return string One of `CONFIRMED`, `TENTATIVE`, or `CANCELLED`.
     */
    protected function statusFor( Booking $booking ): string
    {
        return match ( $booking->status ) {
            BookingStatus::Requested => 'TENTATIVE',
            BookingStatus::Cancelled => 'CANCELLED',
            default                  => 'CONFIRMED',
        };
    }

    /**
     * Maps a filtered neutral payload to a Google event resource body.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $payload  The filtered payload.
     *
     * The booking's neutral `uid` is deliberately not carried to Google's
     * `iCalUID`. Google generates its own immutable iCalUID from the event `id`
     * this driver assigns on insert and ignores a supplied one there — so sending
     * the booking number back on the update PUT would not match the stored value
     * and Google would reject the whole update as an attempt to change an
     * immutable field. Idempotency already rides on the derived `id`, so the
     * field earns nothing here to justify that risk.
     *
     * @throws UnexpectedValueException When the start or end is no longer a date.
     *
     * @return array<string, mixed> The Google event body.
     */
    protected function eventBodyFrom( array $payload ): array
    {
        $start = $this->requireInstant( $payload, 'start' );
        $end   = $this->requireInstant( $payload, 'end' );

        return [
            'summary'     => (string) ( $payload['summary'] ?? '' ),
            'description' => (string) ( $payload['description'] ?? '' ),
            'status'      => strtolower( (string) ( $payload['status'] ?? 'CONFIRMED' ) ),
            'start'       => [ 'dateTime' => $start->toRfc3339String() ],
            'end'         => [ 'dateTime' => $end->toRfc3339String() ],
        ];
    }

    /**
     * Reads a date out of the payload as a UTC instant, or fails loudly.
     *
     * The two times are the one part of the payload that cannot fall back to a
     * default: an event without a start and end is not an event. A filter that
     * drops either fails the same way one that returns a non-array does — with an
     * `UnexpectedValueException` — rather than as a bare `TypeError` from deeper
     * in the serialiser.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $payload  The filtered payload.
     * @param  string  $key  The date key to read.
     *
     * @throws UnexpectedValueException When the key is not a date.
     *
     * @return CarbonImmutable The instant, in UTC.
     */
    protected function requireInstant( array $payload, string $key ): CarbonImmutable
    {
        $value = $payload[ $key ] ?? null;

        if ( ! $value instanceof DateTimeInterface ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.calendarSync.eventPayload must keep %s as a date, got %s.',
                $key,
                get_debug_type( $value ),
            ) );
        }

        return CarbonImmutable::instance( $value )->utc();
    }

    /**
     * Reads Google's watch expiration into an instant.
     *
     * Google reports it as epoch milliseconds, as a string or a number. Anything
     * else falls back to the assumed channel life, so a malformed response still
     * leaves a row the renewal sweep revisits rather than one that never expires.
     *
     * @since 1.0.0
     *
     * @param  mixed  $expiration  The `expiration` field from a watch response.
     *
     * @return CarbonImmutable When the channel lapses.
     */
    protected function expiryFrom( mixed $expiration ): CarbonImmutable
    {
        if ( is_numeric( $expiration ) ) {
            return CarbonImmutable::createFromTimestampMs( (int) $expiration )->utc();
        }

        return CarbonImmutable::now()->utc()->addDays( self::WATCH_FALLBACK_TTL_DAYS );
    }

    /**
     * Determines whether a response says the target no longer exists.
     *
     * @since 1.0.0
     *
     * @param  Response  $response  The response to read.
     *
     * @return bool True on `404 Not Found` or `410 Gone`.
     */
    protected function isGone( Response $response ): bool
    {
        return 404 === $response->status() || 410 === $response->status();
    }

    /**
     * Forgets the connection's stored change-feed cursor.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection being reset.
     *
     * @return void
     */
    protected function clearSyncToken( CalendarConnection $connection ): void
    {
        $this->saveSyncToken( $connection, null );
    }

    /**
     * Records where the next sync should resume from.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection being synced.
     * @param  string|null  $token  The `nextSyncToken` to store, or null to clear.
     *
     * @return void
     */
    protected function saveSyncToken( CalendarConnection $connection, ?string $token ): void
    {
        $connection->forceFill( [
            'sync_token'   => $token,
            'last_sync_at' => CarbonImmutable::now()->utc(),
        ] )->save();
    }

    /**
     * Builds an authorised HTTP client for a connection.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to authorise as.
     *
     * @throws CalendarSyncException When no access token can be produced.
     *
     * @return PendingRequest The configured client.
     */
    protected function client( CalendarConnection $connection ): PendingRequest
    {
        return Http::withToken( $this->tokens->accessTokenFor( $connection ) )
            ->acceptJson()
            ->asJson()
            ->connectTimeout( 10 )
            ->timeout( 30 );
    }

    /**
     * Runs an HTTP call and turns a transport failure into a sync exception.
     *
     * The client is configured not to throw on a response status, so a `4xx` or
     * `5xx` comes back as a {@see Response} for each caller to read. What is left
     * is the request never completing at all — DNS failure, a refused connection,
     * a timeout — which the HTTP client raises as a {@see ConnectionException}.
     * That is exactly the "unreachable" the {@see CalendarSyncDriver} contract
     * says a driver throws on, so it is rethrown as a {@see CalendarSyncException}
     * rather than escaping as a transport exception the sync orchestrator does not
     * count against the connection.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection the call is for.
     * @param  callable(PendingRequest): Response  $call  The request to run.
     *
     * @throws CalendarSyncException When Google cannot be reached.
     *
     * @return Response The response Google returned.
     */
    protected function send( CalendarConnection $connection, callable $call ): Response
    {
        try {
            return $call( $this->client( $connection ) );
        } catch ( ConnectionException $unreachable ) {
            throw new CalendarSyncException( sprintf(
                'Google was unreachable for calendar connection %d: %s',
                $connection->getKey(),
                $unreachable->getMessage(),
            ), 0, $unreachable );
        }
    }

    /**
     * Builds the URL of the connection's events collection.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to address.
     *
     * @return string The events collection URL.
     */
    protected function eventsUrl( CalendarConnection $connection ): string
    {
        return sprintf(
            '%s/calendars/%s/events',
            self::API_BASE,
            rawurlencode( $connection->external_calendar_id ),
        );
    }

    /**
     * Builds the URL of a single event on the connection's calendar.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to address.
     * @param  string  $externalEventId  The event to address.
     *
     * @return string The event URL.
     */
    protected function eventUrl( CalendarConnection $connection, string $externalEventId ): string
    {
        return $this->eventsUrl( $connection ) . '/' . rawurlencode( $externalEventId );
    }

    /**
     * Builds the exception for a write Google refused.
     *
     * @since 1.0.0
     *
     * @param  string  $action  What was being done, for the message.
     * @param  Booking  $booking  The booking the write was for.
     * @param  Response  $response  Google's response.
     *
     * @return CalendarSyncException The exception to throw.
     */
    protected function failed( string $action, Booking $booking, Response $response ): CalendarSyncException
    {
        return new CalendarSyncException( sprintf(
            'Google refused to %s booking %d (HTTP %d).',
            $action,
            $booking->getKey(),
            $response->status(),
        ) );
    }
}
