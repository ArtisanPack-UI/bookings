<?php

/**
 * Public booking creation endpoint.
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

use ArtisanPackUI\Bookings\Exceptions\IntakeValidationException;
use ArtisanPackUI\Bookings\Exceptions\SlotLockTimeoutException;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Http\Controllers\Public\Concerns\ResolvesPublicService;
use ArtisanPackUI\Bookings\Http\Requests\Public\StoreBookingRequest;
use ArtisanPackUI\Bookings\Http\Resources\BookingResource;
use ArtisanPackUI\Bookings\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

use function __;
use function response;

/**
 * The one write the public API offers.
 *
 * Most of the work is turning the domain's exceptions into answers a widget can
 * act on, and the distinction between them is the point:
 *
 * - **422** — the submission is wrong. A malformed field, an intake answer the
 *   service's schema refuses, a provider who does not offer it. Resubmitting the
 *   same thing will fail again; the customer has to change something.
 * - **409** — the submission was fine and somebody else got there first. The
 *   widget's move is to refresh availability and offer another time, which is a
 *   different piece of interface from a field error.
 * - **503** — nobody got the slot, because the request could not even get in
 *   line for it within the lock's patience. Retrying the identical request is
 *   the correct response, which is exactly what neither of the others invites.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingController extends Controller
{
    use ResolvesPublicService;

    /**
     * Constructs the controller.
     *
     * @since 1.0.0
     *
     * @param  BookingService  $bookings  The service every booking is written by.
     */
    public function __construct( protected BookingService $bookings )
    {
    }

    /**
     * Creates a booking.
     *
     * @since 1.0.0
     *
     * @param  StoreBookingRequest  $request  The validated, sanitized submission.
     *
     * @throws ValidationException When the service names a provider it does not
     *                             have, or the intake answers are refused.
     *
     * @return JsonResponse The created booking, or why it could not be made.
     */
    public function store( StoreBookingRequest $request ): JsonResponse
    {
        $service = $this->resolvePublicService( (string) $request->input( 'service_slug' ) );

        try {
            $booking = $this->bookings->create( $request->bookingAttributes( $service ) );
        } catch ( IntakeValidationException $refused ) {
            throw $this->intakeFailed( $refused );
        } catch ( SlotUnavailableException ) {
            return response()->json(
                [ 'message' => __( 'That appointment time is no longer available. Please choose another.' ) ],
                JsonResponse::HTTP_CONFLICT,
            );
        } catch ( SlotLockTimeoutException ) {
            return response()->json(
                [ 'message' => __( 'That appointment time is busy right now. Please try again.' ) ],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        } catch ( InvalidArgumentException $rejected ) {
            throw ValidationException::withMessages( [
                'provider_id' => $rejected->getMessage(),
            ] );
        }

        return ( new BookingResource( $booking->loadMissing( [ 'service', 'provider' ] ) ) )
            ->response()
            ->setStatusCode( JsonResponse::HTTP_CREATED );
    }

    /**
     * Turns refused intake answers into field errors the widget can show.
     *
     * The keys are prefixed with `intake_data.` so they name the field as it was
     * submitted. The validator's own keys are bare field names, which a widget
     * would have to know to re-prefix before it could highlight anything.
     *
     * @since 1.0.0
     *
     * @param  IntakeValidationException  $refused  The refusal from the validator.
     *
     * @return ValidationException The equivalent 422.
     */
    protected function intakeFailed( IntakeValidationException $refused ): ValidationException
    {
        $errors = [];

        foreach ( $refused->errors()->toArray() as $field => $messages ) {
            $errors[ 'intake_data.' . $field ] = $messages;
        }

        return ValidationException::withMessages( $errors );
    }
}
