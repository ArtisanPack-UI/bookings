<?php

/**
 * Self-serve booking management endpoints.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Http\Controllers\Public;

use ArtisanPackUI\Bookings\Contracts\SlotResolver;
use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Exceptions\InvalidBookingTransitionException;
use ArtisanPackUI\Bookings\Exceptions\SlotLockTimeoutException;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Http\Controllers\Public\Concerns\ResolvesManagedBooking;
use ArtisanPackUI\Bookings\Http\Requests\Public\CancelBookingRequest;
use ArtisanPackUI\Bookings\Http\Requests\Public\RescheduleBookingRequest;
use ArtisanPackUI\Bookings\Http\Resources\BookingResource;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Services\BookingService;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

use function __;
use function response;

/**
 * What the customer can do with the link in their confirmation email.
 *
 * There is no account behind a public booking, so the manage token is the whole
 * credential and the middleware in front of these three actions is the whole
 * authentication layer. By the time a method here runs, the booking is known to
 * be the one the token was minted for.
 *
 * The answers a widget has to tell apart:
 *
 * - **403** — the installation does not offer self-serve cancellation at all.
 *   No form should have been drawn; nothing the customer changes will help.
 * - **409** — the booking is not in a state that can be changed. Already
 *   cancelled, already delivered, or the slot went to somebody else between the
 *   customer picking it and this request landing.
 * - **422** — the request was understood and refused on its merits: too close to
 *   the start time, a slot nobody is free for, a time outside the diary.
 *
 * Both writes go through {@see BookingService} rather than touching the model,
 * which is what keeps `ap.bookings.cancelled` and `ap.bookings.rescheduled`
 * firing exactly once with `actor: customer` intact — the notifications, the
 * webhooks, and the calendar sync all hang off that and none of them can tell
 * where the change came from except by the actor.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ManageBookingController extends Controller
{
    use ResolvesManagedBooking;

    /**
     * Constructs the controller.
     *
     * @since 1.0.0
     *
     * @param  BookingService  $bookings  The service every booking is written by.
     * @param  SlotResolver  $slots  The resolver that says what is bookable.
     */
    public function __construct(
        protected BookingService $bookings,
        protected SlotResolver $slots,
    ) {
    }

    /**
     * Shows the booking a manage token stands for.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return JsonResponse The booking and what may still be done to it.
     */
    public function show( Request $request ): JsonResponse
    {
        $booking = $this->managedBooking( $request );

        return $this->respondWith( $request, $booking );
    }

    /**
     * Cancels the booking, freeing the slot it was holding.
     *
     * @since 1.0.0
     *
     * @param  CancelBookingRequest  $request  The validated, sanitized submission.
     *
     * @return JsonResponse The cancelled booking, or why it could not be.
     */
    public function cancel( CancelBookingRequest $request ): JsonResponse
    {
        $booking = $this->managedBooking( $request );

        if ( ! $booking->occupiesSlot() ) {
            return $this->refuse(
                __( 'That booking can no longer be cancelled.' ),
                JsonResponse::HTTP_CONFLICT,
            );
        }

        if ( ! $this->selfServeCancellationAllowed() ) {
            return $this->refuse(
                __( 'Bookings cannot be cancelled from this link. Please contact us instead.' ),
                JsonResponse::HTTP_FORBIDDEN,
            );
        }

        if ( $this->pastChangeDeadline( $booking ) ) {
            return $this->refuse(
                __( 'It is too close to your appointment to cancel it online. Please contact us instead.' ),
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $this->bookings->cancel( $booking, BookingActor::Customer, $request->cancellationReason() );
        } catch ( InvalidBookingTransitionException ) {
            // Lost to whoever moved the booking between the check above and here.
            return $this->refuse(
                __( 'That booking can no longer be cancelled.' ),
                JsonResponse::HTTP_CONFLICT,
            );
        }

        return $this->respondWith( $request, $booking );
    }

    /**
     * Moves the booking to a different time the customer picked.
     *
     * Availability is re-resolved here rather than trusted from the request.
     * The slot list the widget is drawing from was computed when the page
     * loaded, and a manage link is often opened days later — so the time being
     * asked for has to be checked against the diary as it is now, not as it was
     * when somebody last looked at it.
     *
     * That check is a courtesy, not the guard. Nothing holds a lock between
     * resolving and writing; {@see BookingService::reschedule()} takes the slot
     * lock and the unique index settles the race, and a 409 from there is the
     * honest answer when two customers want the same freed slot.
     *
     * @since 1.0.0
     *
     * @param  RescheduleBookingRequest  $request  The validated, sanitized submission.
     *
     * @return JsonResponse The moved booking, or why it could not be moved.
     */
    public function reschedule( RescheduleBookingRequest $request ): JsonResponse
    {
        $booking = $this->managedBooking( $request );

        if ( ! $booking->occupiesSlot() ) {
            return $this->refuse(
                __( 'That booking can no longer be rescheduled.' ),
                JsonResponse::HTTP_CONFLICT,
            );
        }

        $this->requireBookableService( $booking );

        if ( $this->pastChangeDeadline( $booking ) ) {
            return $this->refuse(
                __( 'It is too close to your appointment to reschedule it online. Please contact us instead.' ),
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $start = $request->startTime();

        if ( null === $start ) {
            return $this->refuse(
                __( 'That appointment time could not be understood.' ),
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ( null !== $booking->start_time && $start->equalTo( $booking->start_time ) ) {
            return $this->refuse(
                __( 'Your booking is already at that time.' ),
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ( ! $this->isBookable( $booking, $start ) ) {
            return $this->refuse(
                __( 'That appointment time is not available. Please choose another.' ),
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $this->bookings->reschedule( $booking, $start, BookingActor::Customer );
        } catch ( InvalidBookingTransitionException ) {
            return $this->refuse(
                __( 'That booking can no longer be rescheduled.' ),
                JsonResponse::HTTP_CONFLICT,
            );
        } catch ( SlotUnavailableException ) {
            return $this->refuse(
                __( 'That appointment time is no longer available. Please choose another.' ),
                JsonResponse::HTTP_CONFLICT,
            );
        } catch ( SlotLockTimeoutException ) {
            return $this->refuse(
                __( 'That appointment time is busy right now. Please try again.' ),
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return $this->respondWith( $request, $booking );
    }

    /**
     * Determines whether the service is free at the time being asked for.
     *
     * The window runs a day forward from the requested instant, which is the
     * same window {@see BookingService::slotAt()} asks creation's question with
     * — deliberately, because the two have to agree. The resolver drops any slot
     * running past the end of the window it was given, and how long a slot runs
     * for is not knowable from here: a provider can be attached to a service at
     * a duration of their own, and `ap.bookings.slotDuration` can change it
     * again. A window cut to the local day would therefore reject a late slot
     * the booking endpoint would have taken happily, and the customer would be
     * told a time is unavailable that the widget is still offering.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being moved.
     * @param  CarbonImmutable  $start  The instant it should start at instead.
     *
     * @return bool True when a slot starts at exactly that instant.
     */
    protected function isBookable( Booking $booking, CarbonImmutable $start ): bool
    {
        $service = $booking->service;

        if ( null === $service ) {
            return false;
        }

        $window = new TimeRange( $start, $start->addDay() );

        foreach ( $this->slots->resolve( $service, $booking->provider, $window ) as $slot ) {
            if ( $slot->period->start->equalTo( $start ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds the answer every successful action gives.
     *
     * The same envelope on all three, so a widget renders one response shape
     * whether it just loaded the page or just cancelled — and reads what is
     * still possible out of `meta` rather than reimplementing the policy.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     * @param  Booking  $booking  The booking being managed.
     *
     * @return JsonResponse The booking and its remaining options.
     */
    protected function respondWith( Request $request, Booking $booking ): JsonResponse
    {
        $booking->loadMissing( [ 'service', 'provider' ] );

        $deadline = $this->changeDeadline( $booking );
        $open     = $booking->occupiesSlot() && ! $this->pastChangeDeadline( $booking );

        return response()->json( [
            'data' => ( new BookingResource( $booking ) )->toArray( $request ),
            'meta' => [
                'can_cancel'            => $open && $this->selfServeCancellationAllowed(),
                'can_reschedule'        => $open && null !== $booking->service && (bool) $booking->service->is_active,
                'changes_allowed_until' => $deadline?->toIso8601String(),
            ],
        ] );
    }

    /**
     * Builds a refusal in the shape the widget already handles.
     *
     * @since 1.0.0
     *
     * @param  string  $message  What to tell the customer.
     * @param  int  $status  The status code to answer with.
     *
     * @return JsonResponse The refusal.
     */
    protected function refuse( string $message, int $status ): JsonResponse
    {
        return response()->json( [ 'message' => $message ], $status );
    }
}
