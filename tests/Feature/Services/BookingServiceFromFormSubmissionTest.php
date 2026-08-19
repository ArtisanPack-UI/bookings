<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

afterEach( function (): void {
    removeAllActions( 'ap.bookings.creating' );
    removeAllActions( 'ap.bookings.created' );
    removeAllActions( 'ap.bookings.confirmed' );
} );

describe( 'createFromFormSubmission', function (): void {
    it( 'books the service a slug names, mapping the customer through', function (): void {
        [ $service, $providers ] = bookableService();

        $booking = bookingService()->createFromFormSubmission( [
            'service_slug'   => $service->slug,
            'provider_id'    => $providers[0]->getKey(),
            'start_time'     => bookingStart()->toIso8601String(),
            'customer_name'  => 'Dana Lopez',
            'customer_email' => 'dana@example.test',
            'customer_phone' => '555-0100',
        ] );

        expect( $booking )->toBeInstanceOf( Booking::class )
            ->and( $booking->service_id )->toBe( $service->id )
            ->and( $booking->provider_id )->toBe( $providers[0]->id )
            ->and( $booking->customer_name )->toBe( 'Dana Lopez' )
            ->and( $booking->customer_email )->toBe( 'dana@example.test' )
            ->and( $booking->status )->toBe( BookingStatus::Confirmed )
            ->and( $booking->start_time->equalTo( bookingStart() ) )->toBeTrue();
    } );

    it( 'routes through create so the booking lifecycle hooks fire', function (): void {
        [ $service ] = bookableService();

        $fired = [];

        addAction( 'ap.bookings.creating', static function () use ( &$fired ): void {
            $fired[] = 'creating';
        } );
        addAction( 'ap.bookings.created', static function () use ( &$fired ): void {
            $fired[] = 'created';
        } );
        addAction( 'ap.bookings.confirmed', static function () use ( &$fired ): void {
            $fired[] = 'confirmed';
        } );

        bookingService()->createFromFormSubmission( bookingCustomer( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) );

        expect( $fired )->toBe( [ 'creating', 'created', 'confirmed' ] );
    } );

    it( 'assigns a provider when the submission named none', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $booking = bookingService()->createFromFormSubmission( bookingCustomer( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) );

        expect( $booking->provider_id )->toBeIn( [ $providers[0]->id, $providers[1]->id ] );
    } );

    it( 'does not enforce the service intake schema on a form booking', function (): void {
        // The form is the intake: the service's own schema describes the widget's
        // questions, which a form visitor was never shown. A required intake field
        // the form does not collect must not reject the booking.
        [ $service ] = bookableService();
        $service->forceFill( [
            'intake_schema'         => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'textarea', 'label' => 'Goal', 'required' => true ],
                ],
            ],
            'intake_schema_version' => 1,
        ] )->save();

        $booking = bookingService()->createFromFormSubmission( bookingCustomer( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) );

        expect( $booking->status )->toBe( BookingStatus::Confirmed );
    } );

    it( 'still enforces the intake schema on a direct create by default', function (): void {
        [ $service ] = bookableService();
        $service->forceFill( [
            'intake_schema'         => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'textarea', 'label' => 'Goal', 'required' => true ],
                ],
            ],
            'intake_schema_version' => 1,
        ] )->save();

        expect( static fn (): Booking => bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) ) )->toThrow( ArtisanPackUI\Bookings\Exceptions\IntakeValidationException::class );
    } );

    it( 'refuses a submission that names no service slug', function (): void {
        expect( static fn (): Booking => bookingService()->createFromFormSubmission( [
            'start_time' => bookingStart()->toIso8601String(),
        ] ) )->toThrow( InvalidArgumentException::class, 'must name a service slug' );
    } );

    it( 'refuses a slug no active service answers to', function (): void {
        [ $service ] = bookableService();
        $service->forceFill( [ 'is_active' => false ] )->save();

        expect( static fn (): Booking => bookingService()->createFromFormSubmission( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) )->toThrow( InvalidArgumentException::class, 'No active service' );
    } );
} );
