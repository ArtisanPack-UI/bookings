<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * The hooks this ticket is required to fire, so a rename cannot pass silently.
 *
 * @var array<int, string>
 */
const BOOKING_LIFECYCLE_HOOKS = [
    'ap.bookings.creating',
    'ap.bookings.created',
    'ap.bookings.confirmed',
    'ap.bookings.rescheduling',
    'ap.bookings.rescheduled',
    'ap.bookings.cancelling',
    'ap.bookings.cancelled',
    'ap.bookings.completed',
    'ap.bookings.noShow',
];

afterEach( function (): void {
    foreach ( BOOKING_LIFECYCLE_HOOKS as $hook ) {
        removeAllActions( $hook );
    }

    removeAllFilters( 'ap.bookings.availableProviders' );
    removeAllFilters( 'ap.bookings.roundRobin.selectProvider' );
} );

describe( 'lifecycle actions', function (): void {
    it( 'hands a subscriber the payload it was promised, once per transition', function (): void {
        $seen = [];

        foreach ( BOOKING_LIFECYCLE_HOOKS as $hook ) {
            addAction( $hook, function ( mixed ...$args ) use ( &$seen, $hook ): void {
                $seen[ $hook ][] = $args;
            } );
        }

        [ $service ] = bookableService();

        $booking = bookingService()->create( bookingCustomer( [
            'service'     => $service,
            'start_time'  => bookingStart( '10:00' ),
            'intake_data' => [],
        ] ) );

        expect( $seen['ap.bookings.creating'] )->toHaveCount( 1 )
            ->and( $seen['ap.bookings.creating'][0][0] )->toBeArray()
            ->and( $seen['ap.bookings.creating'][0][0]['service_id'] )->toBe( $service->id )
            ->and( $seen['ap.bookings.creating'][0][1] )->toBeNull()
            ->and( $seen['ap.bookings.created'] )->toHaveCount( 1 )
            ->and( $seen['ap.bookings.created'][0][0] )->toBeInstanceOf( Booking::class )
            ->and( $seen['ap.bookings.confirmed'] )->toHaveCount( 1 );

        bookingService()->reschedule( $booking, bookingStart( '14:00' ) );

        expect( $seen['ap.bookings.rescheduling'] )->toHaveCount( 1 )
            ->and( $seen['ap.bookings.rescheduling'][0][1] )->toBeInstanceOf( CarbonInterface::class )
            ->and( $seen['ap.bookings.rescheduling'][0][1]->equalTo( bookingStart( '14:00' ) ) )->toBeTrue()
            ->and( $seen['ap.bookings.rescheduled'] )->toHaveCount( 1 )
            ->and( $seen['ap.bookings.rescheduled'][0][1]->equalTo( bookingStart( '10:00' ) ) )->toBeTrue();

        bookingService()->cancel( $booking, BookingActor::Customer, 'Diary clash.' );

        expect( $seen['ap.bookings.cancelling'] )->toHaveCount( 1 )
            ->and( $seen['ap.bookings.cancelling'][0][1] )->toBe( 'Diary clash.' )
            ->and( $seen['ap.bookings.cancelled'] )->toHaveCount( 1 );

        expect( $seen )->not->toHaveKey( 'ap.bookings.completed' )
            ->and( $seen )->not->toHaveKey( 'ap.bookings.noShow' );
    } );

    it( 'fires completion and no-show on the bookings that reach them', function (): void {
        $completed = 0;
        $noShow    = 0;

        addAction( 'ap.bookings.completed', function () use ( &$completed ): void {
            $completed++;
        } );
        addAction( 'ap.bookings.noShow', function () use ( &$noShow ): void {
            $noShow++;
        } );

        [ $service ] = bookableService( 2 );

        $first = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '10:00' ),
        ] ) );
        $second = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '10:00' ),
        ] ) );

        bookingService()->complete( $first );
        bookingService()->markNoShow( $second );

        expect( $completed )->toBe( 1 )
            ->and( $noShow )->toBe( 1 );
    } );

    it( 'gives an empty reason to a cancellation nobody explained', function (): void {
        // The payload is typed as a string in the spec, so a subscriber that
        // does no null handling has to keep working when there is no reason.
        $reason = null;

        addAction( 'ap.bookings.cancelling', function ( Booking $booking, string $given ) use ( &$reason ): void {
            $reason = $given;
        } );

        [ $service ] = bookableService();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        bookingService()->cancel( $booking, BookingActor::Admin );

        expect( $reason )->toBe( '' );
    } );
} );

describe( 'ap.bookings.availableProviders', function (): void {
    it( 'hands a subscriber the free candidates, the service, and the instant', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $seen = null;

        addFilter(
            'ap.bookings.availableProviders',
            function ( array $candidates, Service $forService, CarbonInterface $start ) use ( &$seen ): array {
                $seen = [ $candidates, $forService, $start ];

                return $candidates;
            },
        );

        bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $seen[0] )->toHaveCount( 2 )
            ->and( $seen[0][0] )->toBeInstanceOf( ServiceProvider::class )
            ->and( $seen[1]->id )->toBe( $service->id )
            ->and( $seen[2]->equalTo( bookingStart() ) )->toBeTrue();
    } );

    it( 'honours a subscriber that takes a provider out of the running', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $barred = (int) $providers[0]->getKey();

        addFilter(
            'ap.bookings.availableProviders',
            static fn ( array $candidates ): array => array_values( array_filter(
                $candidates,
                static fn ( ServiceProvider $provider ): bool => (int) $provider->getKey() !== $barred,
            ) ),
        );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $booking->provider_id )->toBe( $providers[1]->id );
    } );

    it( 'refuses a subscriber that returns something other than providers', function (): void {
        [ $service ] = bookableService();

        addFilter( 'ap.bookings.availableProviders', static fn (): array => [ 'not a provider' ] );

        expect( fn () => bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) ) )->toThrow( UnexpectedValueException::class );
    } );
} );

describe( 'ap.bookings.roundRobin.selectProvider', function (): void {
    it( 'lets a subscriber overrule the rota', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        // The rota would pick the first — both are unassigned and tie on weight,
        // so the id breaks it.
        $preferred = $providers[1];

        addFilter(
            'ap.bookings.roundRobin.selectProvider',
            static fn ( ?ServiceProvider $selected, array $candidates, Booking $draft ): ?ServiceProvider => $preferred,
        );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $booking->provider_id )->toBe( $preferred->id );
    } );

    it( 'hands the subscriber the rota\'s answer and the booking about to be written', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $seen = null;

        addFilter(
            'ap.bookings.roundRobin.selectProvider',
            function ( ?ServiceProvider $selected, array $candidates, Booking $draft ) use ( &$seen ): ?ServiceProvider {
                $seen = [ $selected, $candidates, $draft ];

                return $selected;
            },
        );

        bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $seen[0] )->toBeInstanceOf( ServiceProvider::class )
            ->and( $seen[1] )->toHaveCount( 2 )
            ->and( $seen[2] )->toBeInstanceOf( Booking::class )
            ->and( $seen[2]->exists )->toBeFalse()
            ->and( $seen[2]->customer_email )->toBe( 'sam@example.test' );
    } );

    it( 'reads null as no opinion rather than as nobody', function (): void {
        // A subscriber that only cares about some services has to be able to
        // stay out of the way of the rest; the alternative reading would make an
        // unconditional guard clause turn every other booking unbookable.
        [ $service, $providers ] = bookableService( 2 );

        addFilter( 'ap.bookings.roundRobin.selectProvider', static fn (): ?ServiceProvider => null );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $booking->provider_id )->toBe( $providers[0]->id );
    } );

    it( 'refuses a subscriber that picks somebody who was not a candidate', function (): void {
        [ $service ] = bookableService( 2 );

        $stranger = ServiceProvider::factory()->create();

        addFilter(
            'ap.bookings.roundRobin.selectProvider',
            static fn (): ServiceProvider => $stranger,
        );

        expect( fn () => bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) ) )->toThrow( UnexpectedValueException::class );
    } );

    it( 'stays quiet when the customer named the provider themselves', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $fired = 0;

        addFilter(
            'ap.bookings.roundRobin.selectProvider',
            function ( ?ServiceProvider $selected ) use ( &$fired ): ?ServiceProvider {
                $fired++;

                return $selected;
            },
        );

        bookingService()->create( bookingCustomer( [
            'service'     => $service,
            'provider_id' => $providers[0]->getKey(),
            'start_time'  => bookingStart(),
        ] ) );

        expect( $fired )->toBe( 0 );
    } );
} );
