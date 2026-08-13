<?php

/**
 * iCal feed service.
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

use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * Turns bookings into a subscribable calendar, and into the stamp that says
 * whether it has changed.
 *
 * A subscribed feed is not fetched the way a page is. Google refetches roughly
 * hourly, Apple about every fifteen minutes, and neither ever stops — so a
 * provider with a busy diary is a standing request for their whole schedule,
 * forever, from every client they ever subscribed with. Two things keep that
 * affordable, and both live here rather than at the controller:
 *
 * **The window is bounded.** A feed carries the recent past and the booked
 * future, not the archive. `public.ical.past_days` and `future_days` say how
 * much of each; a calendar client has no use for last year's appointments and
 * serialising them is the difference between a few kilobytes an hour and a few
 * megabytes.
 *
 * **The stamp is computed without reading the bookings.** {@see self::providerStamp()}
 * is one aggregate — a maximum and two counts — so a caller can answer `304 Not
 * Modified` having touched no rows at all, which is what almost every one of
 * those hourly refetches deserves.
 *
 * The counts are in there alongside the maximum because timestamps only detect
 * changes that raise them. A booking that goes away lowers the maximum rather
 * than raising it, so a cancellation of the most recently touched booking in a
 * window would otherwise leave the stamp reading exactly as it did a moment
 * before — and every subscriber would go on being told nothing had changed. The
 * total covers rows appearing and disappearing; the published count covers a
 * booking that stays but stops being served, which is what a cancellation inside
 * the same second as the previous newest write looks like.
 *
 * The rows counted are deliberately wider than the events served. Soft-deleted
 * and cancelled bookings are stamped, because their disappearance from the feed
 * is itself the change a subscriber is waiting for.
 *
 * One case survives all of that: `updated_at` is stored to the second, so two
 * different edits to the same booking inside one second produce one stamp. The
 * feed is then stale until something else changes it. A feed refetched at best
 * every fifteen minutes is not the place to spend a column migration on that.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class IcalFeedService
{
    /**
     * The `PRODID` every calendar this package serialises carries.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PRODUCT_ID = '-//ArtisanPack UI//Bookings//EN';

    /**
     * How far back a provider feed reaches by default, in days.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_PAST_DAYS = 30;

    /**
     * How far ahead a provider feed reaches by default, in days.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_FUTURE_DAYS = 365;

    /**
     * Gets the span of time a provider feed covers.
     *
     * Measured from now rather than pinned to a calendar boundary, so the window
     * slides with the clock and a subscriber never has to be told to resubscribe.
     * A configured window of zero or less on either side falls back to the
     * shipped default: a blank environment variable is a likelier way to reach
     * zero than a decision to serve an empty calendar.
     *
     * @since 1.0.0
     *
     * @return TimeRange The window, in UTC.
     */
    public function window(): TimeRange
    {
        $now = CarbonImmutable::now()->utc();

        return new TimeRange(
            $now->subDays( $this->days( 'past_days', self::DEFAULT_PAST_DAYS ) ),
            $now->addDays( $this->days( 'future_days', self::DEFAULT_FUTURE_DAYS ) ),
        );
    }

    /**
     * Gets the stamp that says whether a provider's feed has changed.
     *
     * Cheap on purpose: one aggregate over an indexed range, no models built,
     * nothing serialised. This is what a conditional GET costs when the answer
     * is "nothing has changed", which is what almost every one of them is.
     *
     * The provider's own `updated_at` is folded in because the feed's name and
     * its timezone come from the provider row, so renaming one has to move the
     * stamp even when no booking has been touched.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider whose feed is being served.
     * @param  TimeRange  $window  The window the feed covers.
     *
     * @return string The entity tag's value, without its quotes.
     */
    public function providerStamp( ServiceProvider $provider, TimeRange $window ): string
    {
        /** @var object{last_change: string|null, total: int|string, published: int|string|null}|null $summary */
        $summary = $this->windowQuery( $provider, $window )
            ->withTrashed()
            ->toBase()
            ->selectRaw(
                'max(updated_at) as last_change, count(*) as total, sum(case when status = ? then 0 else 1 end) as published',
                [ BookingStatus::Cancelled->value ],
            )
            ->first();

        return $this->stamp( [
            (string) $provider->getKey(),
            (string) $provider->updated_at?->utc()->format( 'Y-m-d H:i:s' ),
            (string) ( $summary?->last_change ?? '' ),
            (string) ( $summary?->total ?? 0 ),
            (string) ( $summary?->published ?? 0 ),
        ] );
    }

    /**
     * Gets the stamp that says whether one booking's feed has changed.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking the feed carries.
     *
     * @return string The entity tag's value, without its quotes.
     */
    public function bookingStamp( Booking $booking ): string
    {
        // The times and the status are in here as well as `updated_at` because
        // the booking is already in hand, so they are free — and `updated_at` is
        // stored to the second, which is not fine enough to notice a booking
        // rescheduled in the same second it was last written. Everything the
        // event is built from is stamped, so nothing the customer would see can
        // change without the tag changing.
        return $this->stamp( [
            (string) $booking->getKey(),
            (string) $booking->updated_at?->utc()->format( 'Y-m-d H:i:s' ),
            (string) $booking->start_time?->utc()->format( 'Y-m-d H:i:s' ),
            (string) $booking->end_time?->utc()->format( 'Y-m-d H:i:s' ),
            $booking->status->value,
        ] );
    }

    /**
     * Gets the bookings a provider's feed carries.
     *
     * Cancelled bookings are left out rather than published as `STATUS:CANCELLED`.
     * Clients disagree about what to do with a cancelled event in a subscribed
     * feed — some drop it, some draw it with a line through it, some ignore the
     * property entirely — and a provider looking at their week wants the
     * cancelled hour to be free, not annotated. The stamp still moves when one
     * is cancelled, so the removal reaches every subscriber.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider whose feed is being served.
     * @param  TimeRange  $window  The window the feed covers.
     *
     * @return Collection<int, Booking> The bookings, ascending by start.
     */
    public function providerBookings( ServiceProvider $provider, TimeRange $window ): Collection
    {
        return $this->windowQuery( $provider, $window )
            ->where( 'status', '!=', BookingStatus::Cancelled->value )
            ->with( 'service' )
            ->orderBy( 'start_time' )
            ->get();
    }

    /**
     * Builds the calendar a provider subscribes to.
     *
     * The customer is named in it. That is only defensible because the feed is
     * addressed by an unguessable token rather than by the provider's slug — the
     * slug is published by the public providers endpoint, and a customer
     * directory behind an address anybody can construct is a different thing
     * entirely.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider whose feed is being served.
     * @param  Collection<int, Booking>  $bookings  The bookings to serialise.
     *
     * @return string The RFC 5545 document.
     */
    public function providerCalendar( ServiceProvider $provider, Collection $bookings ): string
    {
        $calendar = $this->calendar( $provider->name, $provider->timezone );

        foreach ( $bookings as $booking ) {
            $this->addEvent( $calendar, $booking, $this->providerSummary( $booking ), true );
        }

        return $calendar->serialize();
    }

    /**
     * Builds the calendar a customer subscribes to.
     *
     * One booking, because one manage token stands for one booking. A feed that
     * reached the customer's other appointments would make the token disclose
     * more than the link it came from does.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking the token manages.
     *
     * @return string The RFC 5545 document.
     */
    public function customerCalendar( Booking $booking ): string
    {
        $timezone = $booking->customer_timezone ?: 'UTC';
        $calendar = $this->calendar( (string) ( $booking->service?->name ?? __( 'Booking' ) ), $timezone );

        // The customer's own name is left off their own calendar: it tells them
        // nothing they do not know, and the description is the one place the
        // event carries text a client will index and sync onward.
        $this->addEvent( $calendar, $booking, $this->customerSummary( $booking ), false );

        return $calendar->serialize();
    }

    /**
     * Builds an empty calendar with the headers a subscriber reads.
     *
     * `X-WR-CALNAME` and `X-WR-TIMEZONE` are not RFC 5545 — they are Apple's,
     * adopted by everybody else — and they are what stops a subscribed feed
     * showing up in the sidebar as its own URL.
     *
     * @since 1.0.0
     *
     * @param  string  $name  What the calendar should be called.
     * @param  string  $timezone  The zone a client should render it in.
     *
     * @return VCalendar The calendar, with no events yet.
     */
    protected function calendar( string $name, string $timezone ): VCalendar
    {
        $calendar = new VCalendar();

        $calendar->PRODID   = self::PRODUCT_ID;
        $calendar->VERSION  = '2.0';
        $calendar->CALSCALE = 'GREGORIAN';

        $calendar->add( 'METHOD', 'PUBLISH' );
        $calendar->add( 'X-WR-CALNAME', $name );
        $calendar->add( 'X-WR-TIMEZONE', $timezone );

        return $calendar;
    }

    /**
     * Adds one booking to a calendar.
     *
     * `DTSTAMP` and `LAST-MODIFIED` are the booking's `updated_at` rather than
     * the current time, which is what makes two serialisations of an unchanged
     * feed byte-identical. A `DTSTAMP` of "now" would produce a different
     * document on every request while the entity tag stayed put — so a client
     * that had cached the body and revalidated it would be holding something
     * that no longer matched what the origin would send, and a proxy comparing
     * bodies would see churn where there is none.
     *
     * Times go out in UTC. The zone the customer booked in lives on the booking
     * and is what the summary is rendered from; the event itself is an instant,
     * and a client renders it in whatever zone the person reading it is in.
     *
     * @since 1.0.0
     *
     * @param  VCalendar  $calendar  The calendar being built.
     * @param  Booking  $booking  The booking to serialise.
     * @param  string  $summary  The event's title.
     * @param  bool  $includeCustomer  Whether the description may name the customer.
     *
     * @return void
     */
    protected function addEvent( VCalendar $calendar, Booking $booking, string $summary, bool $includeCustomer ): void
    {
        $updated = CarbonImmutable::instance( $booking->updated_at ?? $booking->start_time )->utc();

        /** @var VEvent $event */
        $event = $calendar->add( 'VEVENT', [
            'UID'     => $this->uid( $booking ),
            'SUMMARY' => $summary,
        ] );

        $event->DTSTAMP = $updated->toDateTime();
        $event->DTSTART = CarbonImmutable::instance( $booking->start_time )->utc()->toDateTime();
        $event->DTEND   = CarbonImmutable::instance( $booking->end_time )->utc()->toDateTime();
        $event->add( 'LAST-MODIFIED', $updated->toDateTime() );
        $event->add( 'STATUS', $this->status( $booking ) );

        if ( null !== $booking->created_at ) {
            $event->add( 'CREATED', CarbonImmutable::instance( $booking->created_at )->utc()->toDateTime() );
        }

        $event->add( 'DESCRIPTION', $this->description( $booking, $includeCustomer ) );
    }

    /**
     * Gets the globally unique identifier one booking keeps forever.
     *
     * Built from `booking_number`, which is unique across the installation and
     * never changes — so rescheduling moves the event a subscriber already has
     * rather than leaving the old time behind and adding a second one. The host
     * half comes from `app.url` per RFC 5545's guidance that a UID be unique
     * beyond the system that minted it.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being serialised.
     *
     * @return string The event's UID.
     */
    protected function uid( Booking $booking ): string
    {
        $host = parse_url( (string) config( 'app.url', 'localhost' ), PHP_URL_HOST );

        return sprintf(
            '%s@%s',
            $booking->booking_number,
            is_string( $host ) && '' !== $host ? $host : 'artisanpack-bookings',
        );
    }

    /**
     * Gets the `STATUS` a booking's event carries.
     *
     * A booking nobody has approved yet is `TENTATIVE`, which is what a provider
     * wants to see in the week view: the hour is spoken for, and it is not yet a
     * commitment.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being serialised.
     *
     * @return string The RFC 5545 status value.
     */
    protected function status( Booking $booking ): string
    {
        return match ( $booking->status ) {
            BookingStatus::Requested => 'TENTATIVE',
            BookingStatus::Cancelled => 'CANCELLED',
            default                  => 'CONFIRMED',
        };
    }

    /**
     * Gets the title a provider's feed gives one booking.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being serialised.
     *
     * @return string The event's title.
     */
    protected function providerSummary( Booking $booking ): string
    {
        return sprintf(
            '%s — %s',
            (string) ( $booking->service?->name ?? __( 'Booking' ) ),
            $booking->customer_name,
        );
    }

    /**
     * Gets the title a customer's feed gives their booking.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being serialised.
     *
     * @return string The event's title.
     */
    protected function customerSummary( Booking $booking ): string
    {
        $service = (string) ( $booking->service?->name ?? __( 'Booking' ) );

        if ( null === $booking->provider ) {
            return $service;
        }

        return sprintf( '%s — %s', $service, $booking->provider->name );
    }

    /**
     * Gets the body of one booking's event.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being serialised.
     * @param  bool  $includeCustomer  Whether the customer may be named.
     *
     * @return string The description.
     */
    protected function description( Booking $booking, bool $includeCustomer ): string
    {
        $lines = [ sprintf( '%s: %s', __( 'Reference' ), $booking->booking_number ) ];

        if ( $includeCustomer ) {
            $lines[] = sprintf( '%s: %s', __( 'Customer' ), $booking->customer_name );
            $lines[] = sprintf( '%s: %s', __( 'Email' ), $booking->customer_email );
        }

        return implode( "\n", $lines );
    }

    /**
     * Builds the query a feed's window is read through.
     *
     * The window is matched on `start_time` alone rather than on an overlap.
     * Bookings are minutes or hours long against a window measured in months, so
     * an overlap test would differ only for something straddling an edge — and
     * pay for it with a condition no index can serve, on the one query a
     * conditional GET is meant to make cheap.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider whose feed is being served.
     * @param  TimeRange  $window  The window the feed covers.
     *
     * @return Builder<Booking> The scoped query.
     */
    protected function windowQuery( ServiceProvider $provider, TimeRange $window ): Builder
    {
        return Booking::query()
            ->where( 'provider_id', $provider->getKey() )
            ->where( 'start_time', '>=', $window->start )
            ->where( 'start_time', '<', $window->end );
    }

    /**
     * Hashes the parts a stamp is made of.
     *
     * SHA-1 because an entity tag is a change detector rather than a security
     * boundary: nothing here is trusted because it hashes to a particular value,
     * and the inputs are timestamps and counts a caller cannot choose.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $parts  The values the stamp is derived from.
     *
     * @return string The entity tag's value, without its quotes.
     */
    protected function stamp( array $parts ): string
    {
        return sha1( implode( '|', $parts ) );
    }

    /**
     * Gets a configured window bound, in whole days.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The setting under `public.ical`.
     * @param  int  $default  What to use when it is missing or not positive.
     *
     * @return int The number of days.
     */
    protected function days( string $key, int $default ): int
    {
        $configured = (int) config( 'artisanpack.bookings.public.ical.' . $key, $default );

        return $configured > 0 ? $configured : $default;
    }
}
