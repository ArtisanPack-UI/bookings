<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Integrations\Forms\BookingSlotField;
use ArtisanPackUI\Bookings\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * Builds a booking_slot form field object shaped the way the callbacks read one.
 *
 * @param  array<string, mixed>  $overrides  Anything to change.
 *
 * @return object The field.
 */
function bookingSlotFormField( array $overrides = [] ): object
{
    return (object) ( $overrides + [
        'type'         => BookingSlotField::TYPE,
        'name'         => 'appointment',
        'label'        => 'Pick a time',
        'css_classes'  => '',
        'is_required'  => false,
        'field_config' => [],
    ] );
}

/**
 * Builds a plain form object exposing a set of mappable fields.
 *
 * @param  array<int, array{name: string, label: string, type: string}>  $fields  The fields.
 *
 * @return object The form.
 */
function bookingSlotForm( array $fields = [] ): object
{
    return (object) [
        'fields' => array_map( static fn ( array $f ): object => (object) $f, $fields ),
    ];
}

describe( 'the booking_slot field type', function (): void {
    it( 'adds itself to the forms palette with a built-in shape', function (): void {
        $types = BookingSlotField::register( [] );

        expect( $types )->toHaveKey( BookingSlotField::TYPE );

        $entry = $types[ BookingSlotField::TYPE ];

        expect( $entry )->toHaveKeys( [
            'label',
            'icon',
            'category',
            'has_options',
            'supports_placeholder',
            'supports_default_value',
            'validation_options',
            'defaults',
        ] )
            ->and( $entry['category'] )->toBe( 'advanced' )
            ->and( $entry['has_options'] )->toBeFalse();
    } );

    it( 'does not overwrite an existing booking_slot definition', function (): void {
        $types = BookingSlotField::register( [
            BookingSlotField::TYPE => [ 'label' => 'Custom Slot' ],
        ] );

        expect( $types[ BookingSlotField::TYPE ]['label'] )->toBe( 'Custom Slot' );
    } );
} );

describe( 'the booking_slot palette category', function (): void {
    it( 'files the type under its category so the palette shows it', function (): void {
        $categories = BookingSlotField::registerCategory( [
            'advanced' => [ 'label' => 'Advanced', 'fields' => [ 'file', 'date', 'time' ] ],
        ] );

        expect( $categories['advanced']['fields'] )->toBe( [ 'file', 'date', 'time', BookingSlotField::TYPE ] );
    } );

    it( 'does not file the type twice', function (): void {
        $categories = BookingSlotField::registerCategory( [
            'advanced' => [ 'label' => 'Advanced', 'fields' => [ 'file', BookingSlotField::TYPE ] ],
        ] );

        expect( array_count_values( $categories['advanced']['fields'] )[ BookingSlotField::TYPE ] )->toBe( 1 );
    } );

    it( 'leaves the categories untouched when its own category is absent', function (): void {
        $categories = BookingSlotField::registerCategory( [
            'basic' => [ 'label' => 'Basic', 'fields' => [ 'text' ] ],
        ] );

        expect( $categories )->toBe( [
            'basic' => [ 'label' => 'Basic', 'fields' => [ 'text' ] ],
        ] );
    } );
} );

describe( 'the booking_slot field render', function (): void {
    it( 'draws the picker for a configured booking_slot field', function (): void {
        $service = Service::factory()->create( [ 'name' => 'Website Consultation' ] );

        $html = BookingSlotField::render( '', bookingSlotFormField( [
            'field_config' => [ 'service_slugs' => [ $service->slug ] ],
        ] ) );

        expect( $html )->toContain( 'artisanpack-bookings-slot-field' )
            ->and( $html )->toContain( 'Website Consultation' );
    } );

    it( 'shows a notice when the field names no service', function (): void {
        $html = BookingSlotField::render( '', bookingSlotFormField() );

        expect( $html )->toContain( 'No service is configured' );
    } );

    it( 'passes a field of any other type straight through', function (): void {
        $original = '<input type="text" />';

        $html = BookingSlotField::render( $original, bookingSlotFormField( [ 'type' => 'text' ] ) );

        expect( $html )->toBe( $original );
    } );
} );

describe( 'the booking_slot card preview', function (): void {
    it( 'draws the picker preview for a booking_slot field', function (): void {
        $service = Service::factory()->create( [ 'name' => 'Website Consultation' ] );

        $html = BookingSlotField::preview( '', bookingSlotFormField( [
            'field_config' => [ 'service_slugs' => [ $service->slug ] ],
        ] ) );

        expect( $html )->toContain( 'artisanpack-bookings-slot-field' )
            ->and( $html )->toContain( 'Website Consultation' );
    } );

    it( 'passes a field of any other type straight through', function (): void {
        $html = BookingSlotField::preview( 'CARD', bookingSlotFormField( [ 'type' => 'text' ] ) );

        expect( $html )->toBe( 'CARD' );
    } );
} );

describe( 'the booking_slot settings panel', function (): void {
    it( 'renders the appointment settings with services and field mappings', function (): void {
        Service::factory()->create( [ 'name' => 'Website Consultation' ] );

        $form = bookingSlotForm( [
            [ 'name' => 'full_name', 'label' => 'Your Name', 'type' => 'text' ],
            [ 'name' => 'your_email', 'label' => 'Your Email', 'type' => 'email' ],
        ] );

        $html = BookingSlotField::settings( '', bookingSlotFormField(), $form );

        expect( $html )->toContain( 'Website Consultation' )
            ->and( $html )->toContain( 'Name Form Field' )
            ->and( $html )->toContain( 'Email Form Field' )
            ->and( $html )->toContain( 'Your Name' )
            ->and( $html )->toContain( 'Your Email' );
    } );

    it( 'leaves the booking_slot field itself out of the mapping options', function (): void {
        $form = bookingSlotForm( [
            [ 'name' => 'appointment', 'label' => 'Appointment', 'type' => BookingSlotField::TYPE ],
            [ 'name' => 'a_heading', 'label' => 'Heading', 'type' => 'heading' ],
            [ 'name' => 'full_name', 'label' => 'Your Name', 'type' => 'text' ],
        ] );

        $html = BookingSlotField::settings( '', bookingSlotFormField(), $form );

        // The mapping <option> for the appointment field and the layout heading
        // must not appear; the real question does.
        expect( $html )->toContain( 'value="full_name"' )
            ->and( $html )->not->toContain( 'value="appointment"' )
            ->and( $html )->not->toContain( 'value="a_heading"' );
    } );

    it( 'passes a field of any other type straight through', function (): void {
        $html = BookingSlotField::settings( 'SETTINGS', bookingSlotFormField( [ 'type' => 'text' ] ), bookingSlotForm() );

        expect( $html )->toBe( 'SETTINGS' );
    } );
} );
