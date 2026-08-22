<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Integrations\Forms\FormBookingListener;
use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\TestsWithSqlite;
use Tests\Fixtures\FakeFormSubmission;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // The booking-window guard needs "now" pinned before the fixed slots
    // {@see bookingStart()} names, or every form booking reads as in the past.
    Carbon::setTestNow( '2026-06-01 00:00:00' );
} );

afterEach( function (): void {
    Carbon::setTestNow();

    removeAllActions( 'ap.bookings.creating' );
    removeAllActions( 'ap.bookings.created' );
    removeAllActions( 'ap.bookings.confirmed' );
} );

/**
 * Encodes a picked slot the way the picker writes it into the submission.
 *
 * @param  string  $serviceSlug  The service the visitor chose.
 * @param  string|null  $providerId  The provider, when one was pinned.
 *
 * @return string The JSON slot value.
 */
function bookingSlotValue( string $serviceSlug, ?string $providerId = null ): string
{
    return (string) json_encode( [
        'service_slug' => $serviceSlug,
        'start'        => bookingStart()->toIso8601String(),
        'provider_id'  => $providerId,
    ] );
}

/**
 * Fires the listener over a submission built from plain value rows.
 *
 * @param  array<int, array{name: string, type: string, value: string|null, config?: array<string, mixed>}>  $values  The rows.
 *
 * @return void
 */
function handleFormSubmission( array $values ): void
{
    $submission = new FakeFormSubmission( $values );

    app( FormBookingListener::class )->handle( (object) [ 'submission' => $submission ] );
}

describe( 'the form booking listener', function (): void {
    it( 'books the slot a booking form submission chose, using the field mappings', function (): void {
        [ $service ] = bookableService();

        $fired = false;
        addAction( 'ap.bookings.created', static function () use ( &$fired ): void {
            $fired = true;
        } );

        handleFormSubmission( [
            [
                'name'   => 'appointment',
                'type'   => 'booking_slot',
                'value'  => bookingSlotValue( $service->slug ),
                'config' => [
                    'name_field'  => 'full_name',
                    'email_field' => 'work_email',
                    'phone_field' => 'mobile',
                ],
            ],
            [ 'name' => 'full_name', 'type' => 'text', 'value' => 'Lee Park' ],
            [ 'name' => 'work_email', 'type' => 'email', 'value' => 'lee@example.test' ],
            [ 'name' => 'mobile', 'type' => 'phone', 'value' => '555-0100' ],
        ] );

        $booking = Booking::query()->first();

        expect( $booking )->not->toBeNull()
            ->and( $booking->service_id )->toBe( $service->id )
            ->and( $booking->customer_name )->toBe( 'Lee Park' )
            ->and( $booking->customer_email )->toBe( 'lee@example.test' )
            ->and( $booking->customer_phone )->toBe( '555-0100' )
            ->and( $booking->start_time->equalTo( bookingStart() ) )->toBeTrue()
            ->and( $fired )->toBeTrue();
    } );

    it( 'records the mapped opt-in answer on the booking intake data', function (): void {
        [ $service ] = bookableService();

        handleFormSubmission( [
            [
                'name'   => 'appointment',
                'type'   => 'booking_slot',
                'value'  => bookingSlotValue( $service->slug ),
                'config' => [ 'optin_field' => 'newsletter' ],
            ],
            [ 'name' => 'name', 'type' => 'text', 'value' => 'Lee Park' ],
            [ 'name' => 'email', 'type' => 'email', 'value' => 'lee@example.test' ],
            [ 'name' => 'newsletter', 'type' => 'checkbox', 'value' => 'yes' ],
        ] );

        expect( Booking::query()->first()?->intake_data )->toBe( [ 'opt_in' => true ] );
    } );

    it( 'records opt-in false when the mapped opt-in field was left unticked', function (): void {
        [ $service ] = bookableService();

        handleFormSubmission( [
            [
                'name'   => 'appointment',
                'type'   => 'booking_slot',
                'value'  => bookingSlotValue( $service->slug ),
                'config' => [ 'optin_field' => 'newsletter' ],
            ],
            [ 'name' => 'name', 'type' => 'text', 'value' => 'Lee Park' ],
            [ 'name' => 'email', 'type' => 'email', 'value' => 'lee@example.test' ],
            [ 'name' => 'newsletter', 'type' => 'checkbox', 'value' => '' ],
        ] );

        expect( Booking::query()->first()?->intake_data )->toBe( [ 'opt_in' => false ] );
    } );

    it( 'records no opt-in key when no opt-in field is mapped', function (): void {
        [ $service ] = bookableService();

        handleFormSubmission( [
            [ 'name' => 'appointment', 'type' => 'booking_slot', 'value' => bookingSlotValue( $service->slug ) ],
            [ 'name' => 'name', 'type' => 'text', 'value' => 'Lee Park' ],
            [ 'name' => 'email', 'type' => 'email', 'value' => 'lee@example.test' ],
        ] );

        expect( Booking::query()->first()?->intake_data )->toBe( [] );
    } );

    it( 'resolves the service from the picked slot rather than a hidden field', function (): void {
        [ $service ] = bookableService();

        handleFormSubmission( [
            [ 'name' => 'appointment', 'type' => 'booking_slot', 'value' => bookingSlotValue( $service->slug ) ],
            [ 'name' => 'email', 'type' => 'email', 'value' => 'lee@example.test' ],
            [ 'name' => 'name', 'type' => 'text', 'value' => 'Lee Park' ],
        ] );

        expect( Booking::query()->first()?->service_id )->toBe( $service->id );
    } );

    it( 'books with the timezone the picker carried in the slot', function (): void {
        [ $service ] = bookableService();

        $value = (string) json_encode( [
            'service_slug' => $service->slug,
            'start'        => bookingStart()->toIso8601String(),
            'provider_id'  => null,
            'timezone'     => 'Europe/London',
        ] );

        handleFormSubmission( [
            [ 'name' => 'appointment', 'type' => 'booking_slot', 'value' => $value ],
            [ 'name' => 'email', 'type' => 'email', 'value' => 'lee@example.test' ],
            [ 'name' => 'name', 'type' => 'text', 'value' => 'Lee Park' ],
        ] );

        expect( Booking::query()->first()?->customer_timezone )->toBe( 'Europe/London' );
    } );

    it( 'falls through to a conventional field when the mapped field is blank', function (): void {
        [ $service ] = bookableService();

        handleFormSubmission( [
            [
                'name'   => 'appointment',
                'type'   => 'booking_slot',
                'value'  => bookingSlotValue( $service->slug ),
                'config' => [ 'name_field' => 'full_name' ],
            ],
            [ 'name' => 'full_name', 'type' => 'text', 'value' => '' ],
            [ 'name' => 'name', 'type' => 'text', 'value' => 'Fallback Name' ],
            [ 'name' => 'email', 'type' => 'email', 'value' => 'lee@example.test' ],
        ] );

        expect( Booking::query()->first()?->customer_name )->toBe( 'Fallback Name' );
    } );

    it( 'falls back to conventional fields when the mappings are unset', function (): void {
        [ $service ] = bookableService();

        handleFormSubmission( [
            [ 'name' => 'appointment', 'type' => 'booking_slot', 'value' => bookingSlotValue( $service->slug ) ],
            [ 'name' => 'your_email', 'type' => 'email', 'value' => 'plain@example.test' ],
            [ 'name' => 'name', 'type' => 'text', 'value' => 'Plain Name' ],
        ] );

        $booking = Booking::query()->first();

        expect( $booking->customer_email )->toBe( 'plain@example.test' )
            ->and( $booking->customer_name )->toBe( 'Plain Name' );
    } );

    it( 'does nothing for a submission with no booking_slot field', function (): void {
        handleFormSubmission( [
            [ 'name' => 'email', 'type' => 'email', 'value' => 'someone@example.test' ],
            [ 'name' => 'message', 'type' => 'textarea', 'value' => 'Hello there' ],
        ] );

        expect( Booking::query()->count() )->toBe( 0 );
    } );

    it( 'logs and books nothing when the slot value is empty', function (): void {
        Log::spy();

        [ $service ] = bookableService();

        handleFormSubmission( [
            [ 'name' => 'appointment', 'type' => 'booking_slot', 'value' => '' ],
            [ 'name' => 'email', 'type' => 'email', 'value' => 'lee@example.test' ],
        ] );

        expect( Booking::query()->count() )->toBe( 0 );

        Log::shouldHaveReceived( 'warning' )->once();
    } );

    it( 'logs and does not throw when the booking cannot be placed', function (): void {
        Log::spy();

        handleFormSubmission( [
            [ 'name' => 'appointment', 'type' => 'booking_slot', 'value' => bookingSlotValue( 'no-such-service' ) ],
            [ 'name' => 'email', 'type' => 'email', 'value' => 'lee@example.test' ],
        ] );

        expect( Booking::query()->count() )->toBe( 0 );

        Log::shouldHaveReceived( 'warning' )->once();
    } );

    it( 'still books a bare instant, taking the service from the fallback field', function (): void {
        [ $service ] = bookableService();

        handleFormSubmission( [
            [ 'name' => 'appointment', 'type' => 'booking_slot', 'value' => bookingStart()->toIso8601String() ],
            [ 'name' => '_booking_service_slug', 'type' => 'hidden', 'value' => $service->slug ],
            [ 'name' => 'email', 'type' => 'email', 'value' => 'lee@example.test' ],
            [ 'name' => 'name', 'type' => 'text', 'value' => 'Lee Park' ],
        ] );

        expect( Booking::query()->first()?->service_id )->toBe( $service->id );
    } );
} );
