<?php

/**
 * No-JavaScript booking widget submission.
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
use ArtisanPackUI\Bookings\Services\BookingService;
use ArtisanPackUI\Bookings\Support\WidgetConfirmation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Where the booking widget's form posts when JavaScript never arrived.
 *
 * The widget is Livewire-first, and Livewire needs its bundle to have loaded
 * before a `wire:submit` means anything. This is the same form's plain-HTML
 * destination: an ordinary session-backed, CSRF-protected `POST` that creates
 * the booking and redirects back to the page the widget is embedded on, with
 * the confirmation flashed for the component to render.
 *
 * It deliberately reuses {@see StoreBookingRequest} rather than restating the
 * rules. That request already sanitizes every string, enforces the booking
 * window, and strips the fields an anonymous caller has no business setting — so
 * a submission that would be refused by the JSON API is refused here too, in the
 * same words, and there is no second rule list to drift out of step with it.
 *
 * The redirect goes to `back()` rather than to any address in the payload. The
 * previous URL comes from the session rather than from the request, which is
 * what keeps a form on somebody else's site from turning this into an open
 * redirect.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class WidgetController extends Controller
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
     * Creates a booking from the widget's plain-HTML form.
     *
     * @since 1.0.0
     *
     * @param  StoreBookingRequest  $request  The validated, sanitized submission.
     *
     * @throws ValidationException When the service names a provider it does not
     *                             have, or the intake answers are refused.
     *
     * @return RedirectResponse Back to the widget, confirmed or corrected.
     */
    public function store( StoreBookingRequest $request ): RedirectResponse
    {
        $service = $this->resolvePublicService( (string) $request->input( 'service_slug' ) );

        try {
            $booking = $this->bookings->create( $request->bookingAttributes( $service ) );
        } catch ( IntakeValidationException $refused ) {
            throw $this->intakeFailed( $refused );
        } catch ( SlotUnavailableException ) {
            return $this->refuse( __( 'That appointment time is no longer available. Please choose another.' ) );
        } catch ( SlotLockTimeoutException ) {
            return $this->refuse( __( 'That appointment time is busy right now. Please try again.' ) );
        } catch ( InvalidArgumentException $rejected ) {
            throw $this->providerRefused( $rejected );
        }

        return back()->with(
            WidgetConfirmation::SESSION_KEY,
            WidgetConfirmation::forBooking(
                $booking,
                $this->displayTimezone( $request, $service->timezone ),
            ),
        );
    }

    /**
     * Turns a refused submission into a field error that says nothing extra.
     *
     * `InvalidArgumentException` is a generic PHP exception, and
     * `BookingService::create()` raises it from more than one place — a service
     * that resolves to nothing, a start time it cannot read, a provider who does
     * not offer the service. Only the last of those is a sentence a customer
     * should be shown, and nothing here can tell them apart, so the message on
     * the page is a fixed translated one and the original goes to the log where
     * whoever has to fix it will look.
     *
     * @since 1.0.0
     *
     * @param  InvalidArgumentException  $rejected  Why the domain refused it.
     *
     * @return ValidationException The equivalent 422.
     */
    protected function providerRefused( InvalidArgumentException $rejected ): ValidationException
    {
        Log::warning( 'A booking from the widget form was refused.', [
            'exception' => $rejected->getMessage(),
        ] );

        return ValidationException::withMessages( [
            'provider_id' => __( 'That appointment could not be booked. Please choose another time.' ),
        ] );
    }

    /**
     * Sends the customer back to the form with an explanation.
     *
     * Keyed under `start_time` because that is the field the message is about,
     * and the widget renders it beside the slot list — where the customer's next
     * move is, rather than at the top of a form they have already filled in.
     *
     * @since 1.0.0
     *
     * @param  string  $message  What went wrong.
     *
     * @return RedirectResponse The redirect back.
     */
    protected function refuse( string $message ): RedirectResponse
    {
        return back()
            ->withInput()
            ->withErrors( [ 'start_time' => $message ] );
    }

    /**
     * Gets the zone to state the confirmed time in.
     *
     * @since 1.0.0
     *
     * @param  StoreBookingRequest  $request  The submission.
     * @param  string|null  $fallback  The service's own zone, when it has one.
     *
     * @return string The IANA zone name.
     */
    protected function displayTimezone( StoreBookingRequest $request, ?string $fallback ): string
    {
        $submitted = $request->input( 'customer_timezone' );

        if ( is_string( $submitted ) && in_array( $submitted, timezone_identifiers_list(), true ) ) {
            return $submitted;
        }

        return $fallback ?: ( (string) config( 'artisanpack.bookings.timezone' ) ?: 'UTC' );
    }

    /**
     * Turns refused intake answers into field errors the widget can show.
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
