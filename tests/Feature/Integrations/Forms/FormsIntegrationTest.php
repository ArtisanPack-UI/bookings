<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Integrations\Forms\BookingSlotField;
use ArtisanPackUI\Bookings\Integrations\Forms\FormsIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

afterEach( function (): void {
    removeAllFilters( 'ap.forms.fieldTypes' );
    removeAllFilters( 'ap.forms.fieldCategories' );
    removeAllFilters( 'ap.forms.fieldSettings' );
    removeAllFilters( 'ap.forms.fieldCardPreview' );
    removeAllFilters( 'ap.forms.fieldRender' );
    removeAllFilters( 'ap.forms.validationRules' );
    removeAllActions( 'ap.bookings.creating' );
    removeAllActions( 'ap.bookings.created' );
    removeAllActions( 'ap.bookings.confirmed' );
} );

/**
 * Builds a booking_slot field with no service configured.
 *
 * @return object The field.
 */
function unconfiguredBookingSlotField(): object
{
    return (object) [
        'type'         => BookingSlotField::TYPE,
        'name'         => 'appointment',
        'label'        => 'Pick a time',
        'css_classes'  => '',
        'is_required'  => false,
        'field_config' => [],
    ];
}

/**
 * Encodes a picked slot the way the JavaScript picker writes it.
 *
 * @param  string  $serviceSlug  The service the visitor chose.
 * @param  Carbon\CarbonImmutable  $start  The instant they picked.
 *
 * @return string The JSON slot value.
 */
function pickedSlotValue( string $serviceSlug, Carbon\CarbonImmutable $start ): string
{
    return (string) json_encode( [
        'service_slug' => $serviceSlug,
        'start'        => $start->toIso8601String(),
        'provider_id'  => null,
    ] );
}

/**
 * Runs the booking_slot availability rule over a submitted value.
 *
 * Stands the `ap.forms.validationRules` filter up the way forms does, pulls the
 * closure rule it appends for a booking_slot field, and runs it — returning the
 * messages it failed with, so a test can assert on visitor-facing feedback the
 * way Laravel's validator would surface it.
 *
 * @param  object  $field  The field being validated.
 * @param  mixed  $value  The submitted value.
 *
 * @return array<int, string> The failure messages, empty when the value passed.
 */
function runSlotValidationRules( object $field, mixed $value ): array
{
    addFilter( 'ap.forms.validationRules', [ BookingSlotField::class, 'validationRules' ] );

    $rules  = applyFilters( 'ap.forms.validationRules', [ 'nullable' ], $field );
    $errors = [];

    foreach ( $rules as $rule ) {
        if ( $rule instanceof Closure ) {
            $rule( 'formData.appointment', $value, static function ( string $message ) use ( &$errors ): void {
                $errors[] = $message;
            } );
        }
    }

    return $errors;
}

describe( 'the forms integration', function (): void {
    it( 'binds nothing while auto-registration is switched off', function (): void {
        config()->set( 'artisanpack.bookings.forms.auto_register', false );

        expect( FormsIntegration::subscribe() )->toBeFalse()
            ->and( applyFilters( 'ap.forms.fieldTypes', [] ) )->toBe( [] );
    } );

    it( 'lands the booking_slot type when forms applies its palette filter', function (): void {
        // The subscription's own gate cannot be opened here without aliasing the
        // forms probe class, and that alias is process-wide and irreversible — it
        // would make every later test in the run believe forms is installed. The
        // gate is covered generically by HookSubscriptionsTest, which owns the one
        // place that alias is allowed to happen. So this drives the seam the way
        // forms itself does: the callbacks the subscription binds, attached to the
        // real hooks and run through the `ap.forms.*` filters that stand in for
        // FieldTypes, FieldRenderer, and the builder.
        addFilter( 'ap.forms.fieldTypes', [ BookingSlotField::class, 'register' ] );

        $types = applyFilters( 'ap.forms.fieldTypes', [] );

        expect( $types )->toHaveKey( BookingSlotField::TYPE )
            ->and( $types[ BookingSlotField::TYPE ]['category'] )->toBe( 'advanced' );
    } );

    it( 'files the booking_slot type when forms applies its category filter', function (): void {
        addFilter( 'ap.forms.fieldCategories', [ BookingSlotField::class, 'registerCategory' ] );

        $categories = applyFilters( 'ap.forms.fieldCategories', [
            'advanced' => [ 'label' => 'Advanced', 'fields' => [ 'file', 'date', 'time' ] ],
        ] );

        expect( $categories['advanced']['fields'] )->toContain( BookingSlotField::TYPE );
    } );

    it( 'draws the settings panel when forms applies its settings filter', function (): void {
        addFilter( 'ap.forms.fieldSettings', [ BookingSlotField::class, 'settings' ] );

        $form = (object) [ 'fields' => [] ];
        $html = applyFilters( 'ap.forms.fieldSettings', '', unconfiguredBookingSlotField(), $form );

        expect( $html )->toContain( 'Name Form Field' );
    } );

    it( 'draws the card preview when forms applies its preview filter', function (): void {
        addFilter( 'ap.forms.fieldCardPreview', [ BookingSlotField::class, 'preview' ] );

        $html = applyFilters( 'ap.forms.fieldCardPreview', '', unconfiguredBookingSlotField() );

        expect( $html )->toContain( 'artisanpack-bookings-slot-field' );
    } );

    it( 'draws the picker when forms applies its render filter', function (): void {
        addFilter( 'ap.forms.fieldRender', [ BookingSlotField::class, 'render' ] );

        $html = applyFilters( 'ap.forms.fieldRender', '', unconfiguredBookingSlotField() );

        expect( $html )->toContain( 'artisanpack-bookings-slot-field' );
    } );

    it( 'appends an availability rule when forms applies its validation filter', function (): void {
        addFilter( 'ap.forms.validationRules', [ BookingSlotField::class, 'validationRules' ] );

        $rules = applyFilters( 'ap.forms.validationRules', [ 'nullable' ], unconfiguredBookingSlotField() );

        expect( $rules )->toHaveCount( 2 )
            ->and( $rules[1] )->toBeInstanceOf( Closure::class );
    } );

    it( 'leaves another field type\'s validation rules untouched', function (): void {
        addFilter( 'ap.forms.validationRules', [ BookingSlotField::class, 'validationRules' ] );

        $field = (object) [ 'type' => 'text', 'name' => 'full_name' ];
        $rules = applyFilters( 'ap.forms.validationRules', [ 'required', 'string' ], $field );

        expect( $rules )->toBe( [ 'required', 'string' ] );
    } );

    it( 'fails the submission when the picked slot was taken before submit', function (): void {
        [ $service ] = bookableService();

        // The visitor picked 10:00; someone else took it before they submitted.
        bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        $errors = runSlotValidationRules(
            unconfiguredBookingSlotField(),
            pickedSlotValue( $service->slug, bookingStart() ),
        );

        expect( $errors )->toHaveCount( 1 )
            ->and( $errors[0] )->toContain( 'no longer available' );
    } );

    it( 'passes the submission when the picked slot is still bookable', function (): void {
        [ $service ] = bookableService();

        $errors = runSlotValidationRules(
            unconfiguredBookingSlotField(),
            pickedSlotValue( $service->slug, bookingStart() ),
        );

        expect( $errors )->toBe( [] );
    } );

    it( 'fails the submission when the picked slot names a withdrawn service', function (): void {
        $errors = runSlotValidationRules(
            unconfiguredBookingSlotField(),
            pickedSlotValue( 'no-such-service', bookingStart() ),
        );

        expect( $errors )->toHaveCount( 1 );
    } );

    it( 'skips the check for an empty value, leaving it to required or nullable', function (): void {
        expect( runSlotValidationRules( unconfiguredBookingSlotField(), '' ) )->toBe( [] );
    } );

    it( 'skips the check for a bare instant that names no service', function (): void {
        [ $service ] = bookableService();

        // A no-JavaScript fallback sends the instant alone; the listener owns
        // resolving its service, so there is nothing to re-check here.
        $errors = runSlotValidationRules(
            unconfiguredBookingSlotField(),
            bookingStart()->toIso8601String(),
        );

        expect( $errors )->toBe( [] );
    } );
} );
