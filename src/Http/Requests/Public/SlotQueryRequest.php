<?php

/**
 * Public slot availability query request.
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

/**
 * Validates the month and provider an availability query names.
 *
 * The month is matched against a pattern rather than parsed by `date_format`,
 * because `Y-m` accepts a great deal that is not a month — a two digit year, a
 * thirteenth month, trailing text Carbon shrugs at — and every one of those
 * reaches the resolver as a window somewhere unexpected.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SlotQueryRequest extends FormRequest
{
    /**
     * Determines whether the caller may make this request.
     *
     * @since 1.0.0
     *
     * @return bool Always true; availability is public.
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
            'date'        => [ 'required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/' ],
            'provider_id' => [ 'nullable', 'integer', 'min:1' ],
        ];
    }

    /**
     * Gets the messages for rules the defaults describe poorly.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The messages.
     */
    public function messages(): array
    {
        return [
            'date.regex' => __( 'The date must be a month in YYYY-MM form.' ),
        ];
    }

    /**
     * Cleans the query before it is validated.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $date = $this->input( 'date' );

        if ( is_string( $date ) ) {
            $this->merge( [ 'date' => trim( sanitizeText( $date ) ) ] );
        }
    }
}
