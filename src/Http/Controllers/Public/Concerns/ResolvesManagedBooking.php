<?php

/**
 * Managed booking resolution.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Http\Controllers\Public\Concerns;

use ArtisanPackUI\Bookings\Http\Middleware\ResolveManageToken;
use ArtisanPackUI\Bookings\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The booking a manage token resolved to, and what its holder may still do.
 *
 * The self-serve endpoints share one policy and it has to be one policy: a GET
 * that advertises "you can still cancel this" while the POST behind it refuses
 * is a customer clicking a button that does nothing, twice, and then telephoning.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
trait ResolvesManagedBooking
{
    /**
     * Gets the booking the request's manage token resolved to.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @throws RuntimeException When the route was reached without the middleware
     *                          that resolves the token.
     *
     * @return Booking The booking being managed.
     */
    protected function managedBooking( Request $request ): Booking
    {
        $booking = $request->attributes->get( ResolveManageToken::BOOKING_ATTRIBUTE );

        if ( ! $booking instanceof Booking ) {
            // Unreachable through the shipped routes, and loud rather than
            // convenient on purpose: resolving the token here as a fallback
            // would let a route that forgot the middleware serve somebody's
            // booking with none of the rate limiting that goes with it.
            throw new RuntimeException(
                'A manage route was reached without the bookings.manage-token middleware.',
            );
        }

        return $booking;
    }

    /**
     * Gets the instant after which the customer may no longer change a booking.
     *
     * Measured back from the booking's start by
     * `artisanpack.bookings.cancellation.min_advance_minutes`, which is the same
     * number the confirmation email quotes. A window of zero or less means the
     * customer may act right up to the start time.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being managed.
     *
     * @return CarbonImmutable|null The deadline, or null when there is none.
     */
    protected function changeDeadline( Booking $booking ): ?CarbonImmutable
    {
        $minutes = max( 0, (int) config( 'artisanpack.bookings.cancellation.min_advance_minutes', 0 ) );

        if ( 0 === $minutes || null === $booking->start_time ) {
            return null;
        }

        return CarbonImmutable::instance( $booking->start_time )->utc()->subMinutes( $minutes );
    }

    /**
     * Determines whether the deadline for changing a booking has passed.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being managed.
     *
     * @return bool True when it is too late to cancel or reschedule.
     */
    protected function pastChangeDeadline( Booking $booking ): bool
    {
        $deadline = $this->changeDeadline( $booking );

        return null !== $deadline && CarbonImmutable::now()->utc()->greaterThan( $deadline );
    }

    /**
     * Determines whether the installation lets customers cancel their own bookings.
     *
     * @since 1.0.0
     *
     * @return bool True when self-serve cancellation is switched on.
     */
    protected function selfServeCancellationAllowed(): bool
    {
        return (bool) config( 'artisanpack.bookings.cancellation.allowed', true );
    }

    /**
     * Gives up on a booking whose service has since been withdrawn.
     *
     * A manage link outlives the thing it was made for. Rescheduling into a
     * service an administrator has switched off would resolve availability from
     * rules nobody is maintaining any more, so the link keeps working for
     * reading and cancelling and stops working for booking new time.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being managed.
     *
     * @throws NotFoundHttpException When the service is no longer bookable.
     *
     * @return void
     */
    protected function requireBookableService( Booking $booking ): void
    {
        if ( null === $booking->service || ! $booking->service->is_active ) {
            throw new NotFoundHttpException( __( 'That service is no longer taking bookings.' ) );
        }
    }
}
