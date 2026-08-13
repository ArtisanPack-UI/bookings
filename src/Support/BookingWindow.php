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

        return self::clip( $start->utc(), $start->utc()->addMonth() );
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

        return self::clip( $start->utc(), $start->utc()->addDay() );
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
        $earliest = $now->addMinutes( max( 0, (int) config( 'artisanpack.bookings.booking_window.min_advance_minutes', 0 ) ) );
        $latest   = $now->addMinutes( max( 0, (int) config( 'artisanpack.bookings.booking_window.max_advance_minutes', 0 ) ) );

        $start = $start->lessThan( $earliest ) ? $earliest : $start;
        $end   = $end->greaterThan( $latest ) ? $latest : $end;

        if ( $end->lessThanOrEqualTo( $start ) ) {
            return null;
        }

        return new TimeRange( $start, $end );
    }
}
