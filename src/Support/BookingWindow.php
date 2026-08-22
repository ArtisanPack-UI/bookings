<?php

/**
 * Bookable window resolution.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Support;

use ArtisanPackUI\Bookings\Models\Service;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns a month or a day into the span of it a customer may actually book.
 *
 * Availability is computed from schedules, blackouts, and existing bookings, and
 * knows nothing about the current time — a Tuesday morning is gloriously free at
 * four on Tuesday afternoon. The booking window is what makes that untrue, and
 * it has to be applied by everything that offers a slot rather than by whichever
 * caller remembered: the API, the widget, and the no-JavaScript form all resolve
 * the same period through here, so the three cannot disagree about what is on
 * offer while `StoreBookingRequest` refuses the same time on submission.
 *
 * A month or a day is named in the *service's* own timezone, so "September"
 * means the September a customer looking at that service's page would recognise
 * rather than whatever the server's clock is set to.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class BookingWindow
{
    /**
     * Gets the bookable span of a month.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being queried.
     * @param  string  $month  The month, as `YYYY-MM`.
     * @param  string|null  $timezone  The zone the month is named in, or null
     *                                 for the service's own.
     *
     * @return TimeRange|null The window to resolve within, or null when none of
     *                        the month can be booked — or when the string does
     *                        not name a month at all.
     */
    public static function month( Service $service, string $month, ?string $timezone = null ): ?TimeRange
    {
        $timezone ??= self::timezoneFor( $service );

        try {
            $start = CarbonImmutable::createFromFormat( '!Y-m', $month, $timezone );
        } catch ( Throwable ) {
            return null;
        }

        return self::clip( $start->utc(), $start->addMonth()->utc() );
    }

    /**
     * Gets the bookable span of a single day.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being queried.
     * @param  string  $date  The date, as `YYYY-MM-DD`.
     * @param  string|null  $timezone  The zone the date is named in, or null for
     *                                 the service's own.
     *
     * @return TimeRange|null The window to resolve within, or null when none of
     *                        the day can be booked — or when the string does not
     *                        name a date at all.
     */
    public static function day( Service $service, string $date, ?string $timezone = null ): ?TimeRange
    {
        $timezone ??= self::timezoneFor( $service );

        try {
            $start = CarbonImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
        } catch ( Throwable ) {
            return null;
        }

        return self::clip( $start->utc(), $start->addDay()->utc() );
    }

    /**
     * Gets the timezone a service's calendar is read in.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being queried.
     *
     * @return string The IANA zone name.
     */
    public static function timezoneFor( Service $service ): string
    {
        return $service->timezone ?: ( (string) config( 'artisanpack.bookings.timezone' ) ?: 'UTC' );
    }

    /**
     * Gets the earliest instant a booking may start.
     *
     * A non-positive `min_advance_minutes` reads as "no minimum" — the earliest
     * bookable instant is now — which is the counterpart of what {@see self::latest()}
     * does at the other end. The two bounds agree that zero means "no constraint".
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable  $now  The moment to measure the window from, in UTC.
     *
     * @return CarbonImmutable The earliest instant a booking may start.
     */
    public static function earliest( CarbonImmutable $now ): CarbonImmutable
    {
        return $now->addMinutes( max( 0, (int) config( 'artisanpack.bookings.booking_window.min_advance_minutes', 0 ) ) );
    }

    /**
     * Gets the latest instant a booking may start, or null when there is no limit.
     *
     * A non-positive `max_advance_minutes` — a missing key, a blank environment
     * variable, an explicit `0`, or a negative — means "no maximum", the same
     * reading zero already carries on the minimum. It deliberately does not mean
     * "nothing bookable": a bound that collapsed the window to the current instant
     * would empty every calendar in the installation over a blank `.env` value and
     * blame the customer's chosen time for it.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable  $now  The moment to measure the window from, in UTC.
     *
     * @return CarbonImmutable|null The latest instant a booking may start, or null
     *                              when the installation sets no maximum.
     */
    public static function latest( CarbonImmutable $now ): ?CarbonImmutable
    {
        $minutes = (int) config( 'artisanpack.bookings.booking_window.max_advance_minutes', 0 );

        return $minutes > 0 ? $now->addMinutes( $minutes ) : null;
    }

    /**
     * Trims a span to the part of it the installation takes bookings in.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable  $start  When the span begins, in UTC.
     * @param  CarbonImmutable  $end  When the span ends, in UTC.
     *
     * @return TimeRange|null The bookable remainder, or null when there is none.
     */
    private static function clip( CarbonImmutable $start, CarbonImmutable $end ): ?TimeRange
    {
        $now      = CarbonImmutable::now()->utc();
        $earliest = self::earliest( $now );
        $latest   = self::latest( $now );

        $start = $start->lessThan( $earliest ) ? $earliest : $start;
        $end   = null !== $latest && $end->greaterThan( $latest ) ? $latest : $end;

        if ( $end->lessThanOrEqualTo( $start ) ) {
            return null;
        }

        return new TimeRange( $start, $end );
    }
}
