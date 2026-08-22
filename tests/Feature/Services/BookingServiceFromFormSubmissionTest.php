<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // The bookable slots {@see bookingStart()} names sit on a fixed date, so the
    // booking-window guard needs "now" pinned before them or every slot reads as
    // in the past. Frozen to the start of that day, well ahead of the 10:00-local
    // slot the happy-path cases use.
    Carbon::setTestNow( '2026-06-01 00:00:00' );
} );

afterEach( function (): void {
    Carbon::setTestNow();

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

    it( 'refuses a slot in the past however free the provider is', function (): void {
        // Availability ignores "now", so a stale or tampered submission naming a
        // slot already gone would otherwise be booked. Now is moved past the slot.
        [ $service ] = bookableService();

        Carbon::setTestNow( bookingStart()->addHour() );

        expect( static fn (): Booking => bookingService()->createFromFormSubmission( bookingCustomer( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) ) )->toThrow( SlotUnavailableException::class );
    } );

    it( 'refuses a slot inside the minimum-notice window', function (): void {
        [ $service ] = bookableService();

        // The slot is 15 hours ahead; a 24-hour minimum notice puts it inside the
        // window the widget and HTTP API would both refuse.
        config()->set( 'artisanpack.bookings.booking_window.min_advance_minutes', 24 * 60 );

        expect( static fn (): Booking => bookingService()->createFromFormSubmission( bookingCustomer( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) ) )->toThrow( SlotUnavailableException::class );
    } );
} );

describe( 'formSubmissionIsBookable', function (): void {
    it( 'is true for a slot a provider is still free to take', function (): void {
        [ $service ] = bookableService();

        expect( bookingService()->formSubmissionIsBookable( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) )->toBeTrue();
    } );

    it( 'is false once the slot has been taken', function (): void {
        [ $service ] = bookableService();

        bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( bookingService()->formSubmissionIsBookable( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) )->toBeFalse();
    } );

    it( 'is false for a slot outside every provider\'s working window', function (): void {
        [ $service ] = bookableService();

        expect( bookingService()->formSubmissionIsBookable( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart( '03:00' )->toIso8601String(),
        ] ) )->toBeFalse();
    } );

    it( 'is false for a slot in the past even when the provider is free', function (): void {
        [ $service ] = bookableService();

        Carbon::setTestNow( bookingStart()->addHour() );

        expect( bookingService()->formSubmissionIsBookable( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) )->toBeFalse();
    } );

    it( 'is false for a slot inside the minimum-notice window', function (): void {
        [ $service ] = bookableService();

        config()->set( 'artisanpack.bookings.booking_window.min_advance_minutes', 24 * 60 );

        expect( bookingService()->formSubmissionIsBookable( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) )->toBeFalse();
    } );

    it( 'is false when the slot pins a provider who does not offer the service', function (): void {
        [ $service ]  = bookableService();
        $stranger     = ServiceProvider::factory()->create();

        expect( bookingService()->formSubmissionIsBookable( [
            'service_slug' => $service->slug,
            'provider_id'  => $stranger->getKey(),
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) )->toBeFalse();
    } );

    it( 'is false for a submission that names no service slug', function (): void {
        expect( bookingService()->formSubmissionIsBookable( [
            'start_time' => bookingStart()->toIso8601String(),
        ] ) )->toBeFalse();
    } );

    it( 'is false for a slug no active service answers to', function (): void {
        [ $service ] = bookableService();
        $service->forceFill( [ 'is_active' => false ] )->save();

        expect( bookingService()->formSubmissionIsBookable( [
            'service_slug' => $service->slug,
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) )->toBeFalse();
    } );

    it( 'is false for a submission with no parseable start time', function (): void {
        [ $service ] = bookableService();

        expect( bookingService()->formSubmissionIsBookable( [
            'service_slug' => $service->slug,
        ] ) )->toBeFalse();
    } );

    it( 'is false for a non-string service slug rather than coercing it', function (): void {
        expect( bookingService()->formSubmissionIsBookable( [
            'service_slug' => [ 'discovery-call' ],
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) )->toBeFalse();
    } );

    it( 'is false for an array-valued start rather than fataling in Carbon', function (): void {
        [ $service ] = bookableService();

        expect( bookingService()->formSubmissionIsBookable( [
            'service_slug' => $service->slug,
            'start_time'   => [ bookingStart()->toIso8601String() ],
        ] ) )->toBeFalse();
    } );

    it( 'is false for an array-valued provider rather than coercing it to 1', function (): void {
        [ $service ] = bookableService();

        expect( bookingService()->formSubmissionIsBookable( [
            'service_slug' => $service->slug,
            'provider_id'  => [ 1, 2 ],
            'start_time'   => bookingStart()->toIso8601String(),
        ] ) )->toBeFalse();
    } );
} );
