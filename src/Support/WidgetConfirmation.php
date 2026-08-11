<?php

/**
 * Booking widget confirmation payload.
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

use ArtisanPackUI\Bookings\Models\Booking;
use Carbon\CarbonImmutable;

/**
 * What the widget tells a customer once their booking exists.
 *
 * Lives here rather than on the Livewire component, and the reason is the whole
 * point of Livewire being a suggestion in this package. The no-JavaScript form's
 * controller has to build this same payload, and a static call into the
 * component would autoload a class extending `Livewire\Component` — which, on an
 * installation that never installed Livewire, is a fatal error raised *after*
 * `BookingService::create()` has written the booking. The customer gets a 500,
 * the appointment exists, and nobody has been told about it.
 *
 * It carries the few facts the confirmation screen shows rather than the booking
 * itself, because the component holds it in a public property: those are
 * serialised into the page and signed, but not encrypted, and a booking carries
 * a telephone number and whatever the intake form asked.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class WidgetConfirmation
{
    /**
     * The session key the no-JavaScript submission flashes its result under.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SESSION_KEY = 'artisanpack.bookings.widget.confirmation';

    /**
     * Describes a booking to the person who just made it.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking that was made.
     * @param  string  $timezone  The zone to state the time in.
     *
     * @return array<string, string> The confirmation.
     */
    public static function forBooking( Booking $booking, string $timezone ): array
    {
        // The relation rather than a lazy read of it. `BookingService::create()`
        // refreshes the model before returning, which discards whatever was
        // loaded — so under `Model::preventLazyLoading()`, which plenty of
        // applications enable outside production, reading it here would throw
        // with the booking already written.
        $booking->loadMissing( 'service' );

        $start = CarbonImmutable::parse( $booking->start_time )->setTimezone( $timezone );

        return [
            'booking_number' => (string) $booking->booking_number,
            'service'        => (string) $booking->service?->name,
            'starts_at'      => $start->translatedFormat( 'l j F Y, g:i a' ),
            'timezone'       => $timezone,
            'email'          => (string) $booking->customer_email,
        ];
    }
}
