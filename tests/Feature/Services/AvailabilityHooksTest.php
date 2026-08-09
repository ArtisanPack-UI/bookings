<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

const AVAILABILITY_HOOK_MONDAY = '2026-06-01';

beforeEach( function (): void {
    config()->set( 'artisanpack.bookings.slot_interval', 60 );

    $this->timezone = 'America/Chicago';
    $this->service  = Service::factory()->create( [
        'duration'      => 60,
        'buffer_before' => 0,
        'buffer_after'  => 0,
    ] );
    $this->provider = bookingsSchedule(
        $this->service,
        ServiceProvider::factory()->inTimezone( $this->timezone )->create(),
    );
    $this->window = localDayWindow( AVAILABILITY_HOOK_MONDAY, $this->timezone );
} );

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.availabilityQuery' );
    removeAllFilters( 'ap.bookings.availableSlots' );
    removeAllFilters( 'ap.bookings.slotBookable' );
    removeAllFilters( 'ap.bookings.slotDuration' );
} );

describe( 'ap.bookings.availabilityQuery', function (): void {
    it( 'hands a subscriber the query and the criteria behind it', function (): void {
        $seen = null;

        addFilter( 'ap.bookings.availabilityQuery', function ( Builder $query, array $criteria ) use ( &$seen ): Builder {
            $seen = $criteria;

            return $query;
        } );

        availability()->resolve( $this->service, $this->provider, $this->window );

        expect( $seen )->toMatchArray( [
            'service_id'  => $this->service->id,
            'provider_id' => $this->provider->id,
            'date'        => AVAILABILITY_HOOK_MONDAY,
            'timezone'    => $this->timezone,
        ] );
    } );

    it( 'honours a subscriber that widens what counts as taken', function (): void {
        // A cancelled booking holds nothing, until a plugin decides otherwise.
        Booking::factory()
            ->for( $this->service )
            ->for( $this->provider, 'provider' )
            ->cancelled()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_HOOK_MONDAY . ' 11:00', $this->timezone )->utc(), 60 )
            ->create();

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->toContain( '11:00' );

        addFilter(
            'ap.bookings.availabilityQuery',
            static fn ( Builder $query ): Builder => $query->orWhere( 'provider_id', '>', 0 ),
        );

        // The stamp has not moved, so this also proves the filter runs inside
        // the computation rather than on the way back out of the cache.
        availability()->invalidateProvider( $this->provider->id );

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->not->toContain( '11:00' );
    } );

    it( 'refuses a subscriber that returns something other than a query', function (): void {
        addFilter( 'ap.bookings.availabilityQuery', static fn (): string => 'nope' );

        expect( fn () => availability()->resolve( $this->service, $this->provider, $this->window ) )
            ->toThrow( UnexpectedValueException::class );
    } );
} );

describe( 'ap.bookings.slotDuration', function (): void {
    it( 'honours the length a subscriber returns', function (): void {
        addFilter( 'ap.bookings.slotDuration', static function ( int $minutes, Service $service, ServiceProvider $provider ): int {
            expect( $minutes )->toBe( 60 )
                ->and( $service )->toBeInstanceOf( Service::class )
                ->and( $provider )->toBeInstanceOf( ServiceProvider::class );

            return 120;
        } );

        $slots = availability()->resolve( $this->service, $this->provider, $this->window );

        expect( $slots[ 0 ]->period->minutes() )->toBe( 120 )
            ->and( localStarts( $slots, $this->timezone ) )->toBe( [ '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00' ] );
    } );

    it( 'refuses a subscriber that returns something that is not a duration', function (): void {
        addFilter( 'ap.bookings.slotDuration', static fn (): int => 0 );

        expect( fn () => availability()->resolve( $this->service, $this->provider, $this->window ) )
            ->toThrow( UnexpectedValueException::class );
    } );
} );

describe( 'ap.bookings.slotBookable', function (): void {
    it( 'drops the slots a subscriber vetoes', function (): void {
        addFilter( 'ap.bookings.slotBookable', static function ( bool $bookable, Slot $slot ): bool {
            return $bookable && (int) $slot->period->start->setTimezone( 'America/Chicago' )->format( 'H' ) < 12;
        } );

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->toBe( [ '09:00', '10:00', '11:00' ] );
    } );

    it( 'is told who is asking', function (): void {
        $seen = 'never called';

        addFilter( 'ap.bookings.slotBookable', static function ( bool $bookable, Slot $slot, $customer ) use ( &$seen ): bool {
            $seen = $customer;

            return $bookable;
        } );

        availability()->resolve( $this->service, $this->provider, $this->window );

        expect( $seen )->toBeNull();
    } );

    it( 'refuses a subscriber that returns something other than a boolean', function (): void {
        addFilter( 'ap.bookings.slotBookable', static fn (): string => 'maybe' );

        expect( fn () => availability()->resolve( $this->service, $this->provider, $this->window ) )
            ->toThrow( UnexpectedValueException::class );
    } );
} );

describe( 'ap.bookings.availableSlots', function (): void {
    it( 'hands a subscriber the slots, the provider, and the window', function (): void {
        $seen = [];

        addFilter( 'ap.bookings.availableSlots', function ( array $slots, ServiceProvider $provider, CarbonPeriod $window ) use ( &$seen ): array {
            $seen = [ 'count' => count( $slots ), 'provider' => $provider->id, 'start' => $window->getStartDate()->utc()->format( 'Y-m-d H:i' ) ];

            return $slots;
        } );

        availability()->resolve( $this->service, $this->provider, $this->window );

        expect( $seen )->toBe( [
            'count'    => 8,
            'provider' => $this->provider->id,
            'start'    => '2026-06-01 05:00',
        ] );
    } );

    it( 'honours a subscriber that adds a slot, in the right order', function (): void {
        addFilter( 'ap.bookings.availableSlots', function ( array $slots ): array {
            $slots[] = new Slot(
                new TimeRange(
                    CarbonImmutable::parse( AVAILABILITY_HOOK_MONDAY . ' 08:00', $this->timezone )->utc(),
                    CarbonImmutable::parse( AVAILABILITY_HOOK_MONDAY . ' 09:00', $this->timezone )->utc(),
                ),
                $this->provider->id,
            );

            return $slots;
        } );

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone )[ 0 ] )
            ->toBe( '08:00' );
    } );

    it( 'refuses a subscriber that returns something other than slots', function (): void {
        addFilter( 'ap.bookings.availableSlots', static fn (): array => [ 'nine o\'clock' ] );

        expect( fn () => availability()->resolve( $this->service, $this->provider, $this->window ) )
            ->toThrow( UnexpectedValueException::class );
    } );
} );

describe( 'filters and the shared cache', function (): void {
    it( 'keeps a per-customer filter out of the entry the next customer reads', function (): void {
        // The failure this guards against: one visitor's plugin narrows their
        // slots, the narrowed list lands in the entry keyed by service, provider,
        // and date, and everybody after them is quietly told the same thing.
        $removeAfternoons = static function ( array $slots ): array {
            return array_values( array_filter(
                $slots,
                static fn ( Slot $slot ): bool => (int) $slot->period->start->setTimezone( 'America/Chicago' )->format( 'H' ) < 12,
            ) );
        };

        addFilter( 'ap.bookings.availableSlots', $removeAfternoons );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 3 );

        removeFilter( 'ap.bookings.availableSlots', $removeAfternoons );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 8 );
    } );

    it( 'keeps a per-customer slot veto out of the shared entry too', function (): void {
        $veto = static fn (): bool => false;

        addFilter( 'ap.bookings.slotBookable', $veto );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toBe( [] );

        removeFilter( 'ap.bookings.slotBookable', $veto );

        expect( availability()->resolve( $this->service, $this->provider, $this->window ) )->toHaveCount( 8 );
    } );
} );
