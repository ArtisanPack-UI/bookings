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
} );
