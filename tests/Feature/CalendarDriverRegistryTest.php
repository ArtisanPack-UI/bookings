<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Calendar\CalendarDriverRegistry;
use ArtisanPackUI\Bookings\Contracts\CalendarDriverRegistry as CalendarDriverRegistryContract;
use ArtisanPackUI\Bookings\Contracts\CalendarSyncDriver;
use ArtisanPackUI\Bookings\Drivers\Calendar\IcalFeedDriver;
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Support\TimeRange;

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.calendarSync.providers' );
} );

/**
 * Builds a stand-in driver reporting the given calendar system.
 *
 * @param  CalendarDriver  $driver  The system the driver claims.
 *
 * @return CalendarSyncDriver The stand-in.
 */
function fakeCalendarDriver( CalendarDriver $driver ): CalendarSyncDriver
{
    return new class( $driver ) implements CalendarSyncDriver {
        public function __construct( private CalendarDriver $driver )
        {
        }

        public function driver(): CalendarDriver
        {
            return $this->driver;
        }

        public function createEvent( CalendarConnection $connection, Booking $booking ): string
        {
            return 'created';
        }

        public function updateEvent( CalendarConnection $connection, Booking $booking, string $externalEventId ): string
        {
            return 'updated';
        }

        public function deleteEvent( CalendarConnection $connection, string $externalEventId ): void
        {
        }

        public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array
        {
            return [];
        }
    };
}

it( 'binds the registry as a singleton', function (): void {
    expect( app( CalendarDriverRegistryContract::class ) )
        ->toBeInstanceOf( CalendarDriverRegistry::class )
        ->toBe( app( CalendarDriverRegistryContract::class ) );
} );

it( 'resolves the same instance through the concrete class', function (): void {
    // Without the alias the container auto-builds a second registry for the
    // concrete class — so a consumer type-hinting the shipped implementation
    // would silently lose every PHP-side registration.
    $registry = app( CalendarDriverRegistryContract::class );
    $registry->register( fakeCalendarDriver( CalendarDriver::Google ) );

    expect( app( CalendarDriverRegistry::class ) )->toBe( $registry )
        ->and( app( CalendarDriverRegistry::class )->has( 'google' ) )->toBeTrue();
} );

it( 'ships the built-in read-only iCal driver', function (): void {
    $registry = app( CalendarDriverRegistryContract::class );

    expect( $registry->has( 'ical' ) )->toBeTrue()
        ->and( $registry->get( 'ical' ) )->toBeInstanceOf( IcalFeedDriver::class )
        ->and( array_keys( $registry->all() ) )->toBe( [ 'ical' ] );
} );

it( 'answers for a driver that is not registered', function (): void {
    $registry = app( CalendarDriverRegistryContract::class );

    expect( $registry->has( 'google' ) )->toBeFalse()
        ->and( $registry->get( 'google' ) )->toBeNull();
} );

it( 'registers a driver in PHP', function (): void {
    $registry = app( CalendarDriverRegistryContract::class );

    $registry->register( fakeCalendarDriver( CalendarDriver::Microsoft ) );

    expect( $registry->has( 'microsoft' ) )->toBeTrue()
        ->and( $registry->get( 'microsoft' )->driver() )->toBe( CalendarDriver::Microsoft );
} );

describe( 'the ap.bookings.calendarSync.providers filter', function (): void {
    it( 'lets a consumer add a driver', function (): void {
        addFilter( 'ap.bookings.calendarSync.providers', function ( array $drivers ): array {
            $drivers[] = fakeCalendarDriver( CalendarDriver::Google );

            return $drivers;
        } );

        $registry = app( CalendarDriverRegistryContract::class );

        expect( $registry->has( 'google' ) )->toBeTrue()
            ->and( $registry->all() )->toHaveCount( 2 );
    } );

    it( 'lets a consumer replace the built-in driver', function (): void {
        $replacement = fakeCalendarDriver( CalendarDriver::Ical );

        addFilter( 'ap.bookings.calendarSync.providers', function ( array $drivers ) use ( $replacement ): array {
            $drivers['ical'] = $replacement;

            return $drivers;
        } );

        $registry = app( CalendarDriverRegistryContract::class );

        expect( $registry->get( 'ical' ) )->toBe( $replacement )
            ->and( $registry->all() )->toHaveCount( 1 );
    } );

    it( 'keys an appended driver by the value the driver reports', function (): void {
        addFilter( 'ap.bookings.calendarSync.providers', function ( array $drivers ): array {
            $drivers['a_key_that_disagrees'] = fakeCalendarDriver( CalendarDriver::Apple );

            return $drivers;
        } );

        $registry = app( CalendarDriverRegistryContract::class );

        expect( $registry->has( 'apple' ) )->toBeTrue()
            ->and( $registry->has( 'a_key_that_disagrees' ) )->toBeFalse();
    } );

    it( 'runs on every read, so a late subscriber is still heard', function (): void {
        $registry = app( CalendarDriverRegistryContract::class );

        expect( $registry->has( 'google' ) )->toBeFalse();

        addFilter( 'ap.bookings.calendarSync.providers', function ( array $drivers ): array {
            $drivers[] = fakeCalendarDriver( CalendarDriver::Google );

            return $drivers;
        } );

        expect( $registry->has( 'google' ) )->toBeTrue();
    } );

    it( 'refuses a filtered value that is not an array', function (): void {
        addFilter( 'ap.bookings.calendarSync.providers', fn (): string => 'nope' );

        app( CalendarDriverRegistryContract::class )->all();
    } )->throws( UnexpectedValueException::class, 'must return an array' );

    it( 'refuses an entry that is not a calendar sync driver', function (): void {
        addFilter( 'ap.bookings.calendarSync.providers', function ( array $drivers ): array {
            $drivers[] = 'not a driver';

            return $drivers;
        } );

        app( CalendarDriverRegistryContract::class )->all();
    } )->throws( UnexpectedValueException::class, CalendarSyncDriver::class );
} );
