<?php

/**
 * Self-serve reschedule request.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Http\Requests\Public;

use ArtisanPackUI\Bookings\Support\BookingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

/**
 * Validates the time a customer wants to move their appointment to.
 *
 * The booking window is enforced here for the same reason it is enforced on
 * creation: nothing further down the stack asks what time it is. `BookingService`
 * checks that the new slot is not taken, and last Tuesday is extremely untaken —
 * so without this a manage link would let a customer move an appointment into
 * the past, or a year out past whatever the diary is planned to.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class RescheduleBookingRequest extends FormRequest
{
    /**
     * Determines whether the caller may make this request.
     *
     * @since 1.0.0
     *
     * @return bool Always true; the manage token is the authorization, and the
     *              middleware has already checked it.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Gets the validation rules that apply to the request.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, string>> The rules.
     */
    public function rules(): array
    {
        return [
            'start_time' => [ 'required', 'string', 'date' ],
        ];
    }

    /**
     * Gets the instant the customer wants to move to, in UTC.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable|null The start instant, or null when the input
     *                              cannot be read as a time at all.
     */
    public function startTime(): ?CarbonImmutable
    {
        $start = $this->input( 'start_time' );

        if ( ! is_string( $start ) || '' === trim( $start ) ) {
            return null;
        }

        try {
            return CarbonImmutable::parse( $start )->utc();
        } catch ( Throwable ) {
            return null;
        }
    }

    /**
     * Cleans the submission before it is validated.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $start = $this->input( 'start_time' );

        if ( is_string( $start ) ) {
            $this->merge( [ 'start_time' => trim( sanitizeText( $start ) ) ] );
        }
    }

    /**
     * Adds the checks the rule list cannot express.
     *
     * @since 1.0.0
     *
     * @param  Validator  $validator  The validator running the rules.
     *
     * @return void
     */
    protected function withValidator( Validator $validator ): void
    {
        $validator->after( function ( Validator $validator ): void {
            if ( $validator->errors()->has( 'start_time' ) ) {
                return;
            }

            $start = $this->startTime();

            if ( null === $start ) {
                return;
            }

            $now    = CarbonImmutable::now()->utc();
            $latest = BookingWindow::latest( $now );

            if ( $start->lessThan( BookingWindow::earliest( $now ) ) ) {
                $validator->errors()->add( 'start_time', __( 'That appointment time is too soon to book.' ) );

                return;
            }

            if ( null !== $latest && $start->greaterThan( $latest ) ) {
                $validator->errors()->add( 'start_time', __( 'That appointment time is too far ahead to book.' ) );
            }
        } );
    }
}
