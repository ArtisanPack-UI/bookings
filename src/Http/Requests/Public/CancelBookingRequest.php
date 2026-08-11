<?php

/**
 * Self-serve cancellation request.
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

use Illuminate\Foundation\Http\FormRequest;

use function is_string;
use function sanitizeText;
use function trim;

/**
 * Validates the one optional thing a customer may say while cancelling.
 *
 * The reason is stored on the event rather than the row and travels to webhooks
 * and staff notices, so it goes through the security package's sanitizer first
 * — the same order {@see StoreBookingRequest} uses, for the same reason: what
 * the rules pass judgement on has to be what is actually kept.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class CancelBookingRequest extends FormRequest
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
            'reason' => [ 'nullable', 'string', 'max:1000' ],
        ];
    }

    /**
     * Gets the reason the customer gave, when they gave one.
     *
     * @since 1.0.0
     *
     * @return string|null The cleaned reason.
     */
    public function cancellationReason(): ?string
    {
        $reason = $this->input( 'reason' );

        return is_string( $reason ) && '' !== $reason ? $reason : null;
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
        $reason = $this->input( 'reason' );

        if ( is_string( $reason ) ) {
            $this->merge( [ 'reason' => trim( sanitizeText( $reason ) ) ] );
        }
    }
}
