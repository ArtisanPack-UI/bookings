<?php

/**
 * Read-only outbound iCal calendar sync driver.
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
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\IcalFeedService;
use ArtisanPackUI\Bookings\Services\IcalTokenService;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use UnexpectedValueException;

/**
 * The simplest calendar driver, and the one that fixes the shape of the rest.
 *
 * A provider subscribes their calendar client to a signed `.ics` URL and the
 * feed serves their bookings from there on — so this driver pushes nothing over
 * the wire and reads nothing back. It is deliberately the first driver written:
 * the four that sync a real external calendar all implement the same
 * {@see CalendarSyncDriver} contract, and building the read-only one first is
 * how that contract was shaken out before Google, Microsoft, or Apple depended
 * on it.
 *
 * What "outbound and read-only" means for each contract method:
 *
 * - **The writes have no external call to make.** {@see self::createEvent()} and
 *   {@see self::updateEvent()} do not reach a calendar API — the subscription
 *   already carries the booking. They still run the push lifecycle so a consumer
 *   can observe it and reshape the event: `ap.bookings.calendarSync.pushing`
 *   fires, `ap.bookings.calendarSync.eventPayload` filters the VEVENT payload,
 *   the event is serialised, and `ap.bookings.calendarSync.pushed` fires. Both
 *   return the booking's iCal UID as the external identifier, which is stable
 *   across a reschedule so an update moves the event a subscriber already has
 *   rather than leaving the old time behind.
 * - **The delete is a no-op.** A booking drops out of the feed the moment it is
 *   cancelled or removed, so there is nothing to tell an external calendar.
 * - **Nothing is read back.** {@see self::busyPeriods()} returns no periods,
 *   because a read-only feed never learns what the subscriber's calendar
 *   considers busy — which is exactly why an iCal connection cannot suppress
 *   availability the way a two-way one can.
 *
 * Because there is no external calendar, this driver never fires
 * `ap.bookings.calendarSync.pullReceived`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class IcalFeedDriver implements CalendarSyncDriver
{
    /**
     * Constructs the driver.
     *
     * @since 1.0.0
     *
     * @param  IcalFeedService  $feed  Shares the UID and status the subscription
     *                                 feed serves, so a pushed event and a
     *                                 subscribed one describe the same booking.
     * @param  IcalTokenService  $tokens  Builds the signed subscription URL.
     */
    public function __construct(
        protected IcalFeedService $feed,
        protected IcalTokenService $tokens,
    ) {
    }

    /**
     * Gets the calendar system this driver talks to.
     *
     * @since 1.0.0
     *
     * @return CalendarDriver Always the iCal system.
     */
    public function driver(): CalendarDriver
    {
        return CalendarDriver::Ical;
    }

    /**
     * Represents a booking on the feed for the first time.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  Booking  $booking  The booking to represent on the calendar.
     *
     * @return string The booking's iCal UID.
     */
    public function createEvent( CalendarConnection $connection, Booking $booking ): string
    {
        return $this->push( $booking );
    }

    /**
     * Re-represents a booking that has changed.
     *
     * The external identifier passed in is ignored: the UID is derived from the
     * booking and does not change, so a reschedule updates the same event rather
     * than orphaning the old one.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  Booking  $booking  The booking in its current state.
     * @param  string  $externalEventId  The event to update.
     *
     * @return string The booking's iCal UID.
     */
    public function updateEvent( CalendarConnection $connection, Booking $booking, string $externalEventId ): string
    {
        return $this->push( $booking );
    }

    /**
     * Removes an external event.
     *
     * A no-op: a booking leaves the feed by being cancelled or removed, and a
     * read-only outbound driver has no external calendar to notify.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to write through.
     * @param  string  $externalEventId  The event to remove.
     *
     * @return void
     */
    public function deleteEvent( CalendarConnection $connection, string $externalEventId ): void
    {
    }

    /**
     * Reads back the periods the external calendar considers busy.
     *
     * Always empty: a read-only feed is written to, never read from, so it
     * cannot report what the subscriber's calendar is busy with.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to read from.
     * @param  TimeRange  $window  The span of time to read.
     *
     * @return array<int, TimeRange> Always no periods.
     */
    public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array
    {
        return [];
    }

    /**
     * Gets the signed URL a provider subscribes their calendar client to.
     *
     * The token is the plain value {@see IcalTokenService::issueFor()} returns,
     * readable only at the moment it is minted — the column stores a hash, so a
     * saved provider cannot have its URL reconstructed. Delegated to the token
     * service, which is the one place the feed's addressing is decided.
     *
     * @since 1.0.0
     *
     * @param  string  $token  The provider's plain feed token.
     *
     * @return string The absolute `.ics` subscription URL.
     */
    public function subscriptionUrl( string $token ): string
    {
        return $this->tokens->feedUrl( $token );
    }

    /**
     * Determines whether a provider has a feed to subscribe to.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider to check.
     *
     * @return bool True when a feed token has been minted for the provider.
     */
    public function servesFeedFor( ServiceProvider $provider ): bool
    {
        return $this->tokens->hasFeed( $provider );
    }

    /**
     * Builds the VEVENT that represents a booking on this driver's feed.
     *
     * The payload is assembled, run through the
     * `ap.bookings.calendarSync.eventPayload` filter so a consumer can reshape
     * what the outbound event carries, and only then serialised — a subscriber
     * that rewrites the summary, the times, the status, or the description is
     * honoured. Only those keyed fields are read, so the filter reshapes the
     * event rather than adding arbitrary properties to it.
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
     * @return string The RFC 5545 VEVENT component.
     */
    public function buildEvent( Booking $booking ): string
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

        return $this->serialize( $payload );
    }

    /**
     * Runs the push lifecycle for a booking and returns its external identifier.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being pushed.
     *
     * @return string The booking's iCal UID.
     */
    protected function push( Booking $booking ): string
    {
        $externalEventId = $this->feed->uid( $booking );

        doAction( 'ap.bookings.calendarSync.pushing', $booking, $this->driver()->value );

        $this->buildEvent( $booking );

        doAction( 'ap.bookings.calendarSync.pushed', $booking, $this->driver()->value, $externalEventId );

        return $externalEventId;
    }

    /**
     * Gets the payload a booking's VEVENT is built from.
     *
     * A neutral shape rather than the provider or customer feed's — the driver
     * describes the booking, and a consumer decides through the filter what an
     * outbound event should say about it.
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
            'uid'         => $this->feed->uid( $booking ),
            'summary'     => (string) ( $booking->service?->name ?? __( 'Booking' ) ),
            'start'       => CarbonImmutable::instance( $booking->start_time )->utc(),
            'end'         => CarbonImmutable::instance( $booking->end_time )->utc(),
            'status'      => $this->feed->status( $booking ),
            'description' => sprintf( '%s: %s', __( 'Reference' ), $booking->booking_number ),
        ];
    }

    /**
     * Serialises a payload into a single VEVENT component.
     *
     * The event is added to a throwaway calendar because VObject serialises a
     * component in the context of its parent; only the VEVENT is returned. Times
     * go out in UTC, as instants a client renders in whoever's zone is reading.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $payload  The filtered payload.
     *
     * @return string The RFC 5545 VEVENT component.
     */
    protected function serialize( array $payload ): string
    {
        $start = $this->instant( $payload, 'start' );
        $end   = $this->instant( $payload, 'end' );

        $calendar = new VCalendar();

        /** @var VEvent $event */
        $event = $calendar->add( 'VEVENT', [
            'UID'     => (string) ( $payload['uid'] ?? '' ),
            'SUMMARY' => (string) ( $payload['summary'] ?? '' ),
        ] );

        $event->DTSTART = $start->toDateTime();
        $event->DTEND   = $end->toDateTime();
        $event->add( 'STATUS', (string) ( $payload['status'] ?? 'CONFIRMED' ) );
        $event->add( 'DESCRIPTION', (string) ( $payload['description'] ?? '' ) );

        return $event->serialize();
    }

    /**
     * Reads a date out of the payload as a UTC instant.
     *
     * The two times are the one part of the payload that cannot fall back to a
     * string default: an event without a start and end is not an event. So a
     * filter that drops either from a date fails the same way a filter that
     * returns a non-array does — loudly, with an `UnexpectedValueException` —
     * rather than as a bare `TypeError` from deep inside the serialiser.
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
    protected function instant( array $payload, string $key ): CarbonImmutable
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
}
