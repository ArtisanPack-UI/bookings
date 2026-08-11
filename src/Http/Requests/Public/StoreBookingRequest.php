<?php

/**
 * Public booking creation request.
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

use ArtisanPackUI\Bookings\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

use function __;
use function config;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function max;
use function sanitizeEmail;
use function sanitizeText;
use function trim;

/**
 * Validates and cleans the one write the public API offers.
 *
 * Two jobs, and the order between them matters. Every string a customer sends
 * is put through `artisanpack-ui/security` first (§9.1) and validated second,
 * so what the rules pass judgement on is what will actually be stored — a name
 * whose markup is stripped to nothing fails `required` here rather than being
 * written as an empty string.
 *
 * The attributes handed to `BookingService` are then rebuilt field by field
 * rather than passed through wholesale. That service accepts `series_id`,
 * `series_index`, and a caller-named assignment strategy, none of which an
 * anonymous request over the internet has any business setting, and the
 * cheapest way to keep them out is to never copy them across.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class StoreBookingRequest extends FormRequest
{
    /**
     * How many answers one submission may carry.
     *
     * A ceiling rather than a schema-derived number, because it is enforced
     * before anything has looked at which service is being booked. An intake
     * form with more than a hundred questions is not a thing anybody fills in,
     * and without a cap the sanitizer below walks whatever an anonymous caller
     * cares to post — which is a body the request-size limit alone would let
     * through in the hundreds of thousands of keys.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const MAX_INTAKE_ANSWERS = 100;

    /**
     * Whether the intake answers were refused for their size.
     *
     * Set while the submission is being cleaned and read once the rules have
     * run, because the two halves of the check happen either side of validation:
     * the cleaning is what declines to walk an oversized payload, and the
     * validator is where declining to walk it has to become a 422 rather than a
     * booking carrying answers nothing sanitized.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $intakeIsOversized = false;

    /**
     * Determines whether the caller may make this request.
     *
     * @since 1.0.0
     *
     * @return bool Always true; booking is public by design.
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
            'service_slug'      => [ 'required', 'string', 'max:255' ],
            'provider_id'       => [ 'nullable', 'integer', 'min:1' ],
            'start_time'        => [ 'required', 'string', 'date' ],
            'customer_name'     => [ 'required', 'string', 'max:255' ],
            'customer_email'    => [ 'required', 'string', 'email', 'max:255' ],
            'customer_phone'    => [ 'nullable', 'string', 'max:50' ],
            'customer_timezone' => [ 'nullable', 'string', 'timezone' ],
            'notes'             => [ 'nullable', 'string', 'max:2000' ],
            'intake_data'       => [ 'nullable', 'array', 'max:' . self::MAX_INTAKE_ANSWERS ],
        ];
    }

    /**
     * Gets the booking to create, in the shape `BookingService` accepts.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service resolved from the slug.
     *
     * @return array<string, mixed> The booking attributes.
     */
    public function bookingAttributes( Service $service ): array
    {
        $providerId = $this->input( 'provider_id' );

        return [
            'service'           => $service,
            'provider_id'       => null === $providerId ? null : (int) $providerId,
            'start_time'        => $this->startTime(),
            'customer_name'     => (string) $this->input( 'customer_name' ),
            'customer_email'    => (string) $this->input( 'customer_email' ),
            'customer_phone'    => $this->input( 'customer_phone' ),
            'customer_timezone' => $this->input( 'customer_timezone' ),
            'notes'             => $this->input( 'notes' ),
            'intake_data'       => (array) $this->input( 'intake_data', [] ),
        ];
    }

    /**
     * Gets the instant the booking is asked to start at, in UTC.
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
        $cleaned = [];

        foreach ( [ 'service_slug', 'start_time', 'customer_name', 'customer_phone', 'customer_timezone', 'notes' ] as $field ) {
            $value = $this->input( $field );

            if ( is_string( $value ) ) {
                $cleaned[ $field ] = trim( sanitizeText( $value ) );
            }
        }

        $email = $this->input( 'customer_email' );

        if ( is_string( $email ) ) {
            $cleaned['customer_email'] = sanitizeEmail( $email );
        }

        $intake = $this->input( 'intake_data' );

        if ( is_array( $intake ) ) {
            // Counted before it is cleaned, and left untouched when it is over
            // the cap. Sanitizing runs before the rules do, so cleaning first
            // would put the whole of an oversized payload through the sanitizer
            // on the way to refusing it — which is the work the cap exists to
            // refuse. Counting is the same walk without the sanitizer's cost.
            $budget = self::MAX_INTAKE_ANSWERS;

            $this->intakeIsOversized = ! $this->fitsWithin( $intake, $budget );

            if ( ! $this->intakeIsOversized ) {
                $cleaned['intake_data'] = $this->sanitizeIntake( $intake );
            }
        }

        if ( [] !== $cleaned ) {
            $this->merge( $cleaned );
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
            $this->validateBookingWindow( $validator );

            // Refused here rather than left to the `max` rule, because that rule
            // counts the top level only — and one key holding a hundred thousand
            // children satisfies it. This is also what stops an oversized
            // payload reaching the domain unsanitized, since the cleaning above
            // declined to touch it.
            if ( $this->intakeIsOversized ) {
                $validator->errors()->add( 'intake_data', __( 'That submission carries too many answers.' ) );
            }
        } );
    }

    /**
     * Determines whether an array holds no more than the given number of values.
     *
     * Counts every value at every depth and stops the moment the budget is gone,
     * so the cost of rejecting a hostile payload is bounded by the cap rather
     * than by the payload.
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $values  The array to measure.
     * @param  int  $budget  How many values are left to spend. Passed by
     *                       reference so that siblings and children draw on one
     *                       allowance rather than each getting their own.
     *
     * @return bool True when the array fits.
     */
    protected function fitsWithin( array $values, int &$budget ): bool
    {
        foreach ( $values as $value ) {
            if ( $budget-- <= 0 ) {
                return false;
            }

            if ( is_array( $value ) && ! $this->fitsWithin( $value, $budget ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Refuses a start time outside the window the installation books in.
     *
     * Nothing further down enforces this. `BookingService` asks the resolver
     * whether a slot is free, and a slot last Tuesday is extremely free — so
     * without this check the endpoint would happily take bookings in the past,
     * and the widget would be the only thing standing between a customer and an
     * appointment nobody can keep.
     *
     * @since 1.0.0
     *
     * @param  Validator  $validator  The validator to record failures against.
     *
     * @return void
     */
    protected function validateBookingWindow( Validator $validator ): void
    {
        if ( $validator->errors()->has( 'start_time' ) ) {
            return;
        }

        $start = $this->startTime();

        if ( null === $start ) {
            return;
        }

        $now      = CarbonImmutable::now()->utc();
        $earliest = $now->addMinutes( max( 0, (int) config( 'artisanpack.bookings.booking_window.min_advance_minutes', 0 ) ) );
        $latest   = $now->addMinutes( max( 0, (int) config( 'artisanpack.bookings.booking_window.max_advance_minutes', 0 ) ) );

        if ( $start->lessThan( $earliest ) ) {
            $validator->errors()->add( 'start_time', __( 'That appointment time is too soon to book.' ) );

            return;
        }

        if ( $start->greaterThan( $latest ) ) {
            $validator->errors()->add( 'start_time', __( 'That appointment time is too far ahead to book.' ) );
        }
    }

    /**
     * Cleans the intake answers, however deeply they nest.
     *
     * The security package's own `sanitizeArray()` maps over one level and hands
     * each value to a string sanitizer, which a checkbox group — an array of
     * answers under one key — walks straight into. This recurses instead, and
     * leaves numbers and booleans as the types they arrived as so the schema
     * validator downstream still sees a number where the field declares one.
     *
     * Keys are left alone deliberately: they are field names matched against the
     * service's intake schema, and a key that came back altered would silently
     * stop matching the field it answers.
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $answers  The answers as submitted.
     *
     * @return array<array-key, mixed> The cleaned answers.
     */
    protected function sanitizeIntake( array $answers ): array
    {
        $cleaned = [];

        foreach ( $answers as $key => $value ) {
            if ( is_array( $value ) ) {
                $cleaned[ $key ] = $this->sanitizeIntake( $value );

                continue;
            }

            if ( is_string( $value ) ) {
                $cleaned[ $key ] = trim( sanitizeText( $value ) );

                continue;
            }

            $cleaned[ $key ] = is_bool( $value ) || is_numeric( $value ) || null === $value ? $value : null;
        }

        return $cleaned;
    }
}
