<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarBusyBlock;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            if ( 'bookings' === $criteria['subject'] ) {
                $seen = $criteria;
            }

            return $query;
        } );

        availability()->resolve( $this->service, $this->provider, $this->window );

        expect( $seen )->toMatchArray( [
            'subject'     => 'bookings',
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

        // Another provider's cancelled booking, at a different hour, to prove the
        // widening clause stays confined to the provider being resolved rather
        // than reaching across everyone.
        Booking::factory()
            ->for( $this->service )
            ->for( ServiceProvider::factory()->create(), 'provider' )
            ->cancelled()
            ->startingAt( CarbonImmutable::parse( AVAILABILITY_HOOK_MONDAY . ' 14:00', $this->timezone )->utc(), 60 )
            ->create();

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->toContain( '11:00' )
            ->toContain( '14:00' );

        // Guarded on the subject: the same hook now shapes the busy-block query,
        // which has no `provider_id` column, so an unguarded clause would build
        // SQL against a column that is not there. Grouped and scoped to this
        // provider and the requested window — not re-applying active(), since the
        // point is to count the cancelled booking the base query left out. A bare
        // orWhere would reach the other provider and other days.
        addFilter( 'ap.bookings.availabilityQuery', static function ( Builder $query, array $criteria ): Builder {
            if ( 'bookings' !== $criteria['subject'] ) {
                return $query;
            }

            return $query->orWhere( static function ( Builder $alternative ) use ( $criteria ): void {
                $alternative->where( 'provider_id', $criteria['provider_id'] )
                    ->where( 'start_time', '<', CarbonImmutable::parse( $criteria['until'] ) )
                    ->where( 'end_time', '>', CarbonImmutable::parse( $criteria['from'] ) );
            } );
        } );

        // The day above was cached before the filter existed, so it has to be
        // dropped for the filter to be reachable at all — and that dropping it
        // then changes the answer is what proves the filter runs inside the
        // computation rather than on the way back out of the cache.
        availability()->invalidateProvider( $this->provider->id );

        // 11:00 falls to this provider's now-counted cancelled booking; 14:00
        // survives, because the other provider's booking stayed out of scope.
        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->not->toContain( '11:00' )
            ->toContain( '14:00' );
    } );

    it( 'refuses a subscriber that returns something other than a query', function (): void {
        addFilter( 'ap.bookings.availabilityQuery', static fn (): string => 'nope' );

        expect( fn () => availability()->resolve( $this->service, $this->provider, $this->window ) )
            ->toThrow( UnexpectedValueException::class );
    } );

    it( 'shapes the busy-block query too, marked by its subject', function (): void {
        // Fired even though the provider has no calendar connection at all: the
        // seam a custom busy-time source injects through does not require one.
        $seen = null;

        addFilter( 'ap.bookings.availabilityQuery', function ( Builder $query, array $criteria ) use ( &$seen ): Builder {
            if ( 'busy_blocks' === $criteria['subject'] ) {
                $seen = $criteria;
            }

            return $query;
        } );

        availability()->resolve( $this->service, $this->provider, $this->window );

        expect( $seen )->toMatchArray( [
            'subject'        => 'busy_blocks',
            'provider_id'    => $this->provider->id,
            'date'           => AVAILABILITY_HOOK_MONDAY,
            'timezone'       => $this->timezone,
            'connection_ids' => [],
        ] );
    } );

    it( 'honours a subscriber that injects busy time from a custom source', function (): void {
        // Two one-way connections: their blocks are ignored by the built-in
        // subtraction, so each stands in for a source availability would never
        // consult on its own. The subscriber folds one of them back in — the
        // other is here to prove the scoped clause reaches only what it names.
        $injected = CalendarConnection::factory()->for( $this->provider, 'provider' )->create();
        $other    = CalendarConnection::factory()->for( $this->provider, 'provider' )->create();

        CalendarBusyBlock::factory()->for( $injected, 'connection' )->spanning(
            CarbonImmutable::parse( AVAILABILITY_HOOK_MONDAY . ' 11:00', $this->timezone )->utc(),
            CarbonImmutable::parse( AVAILABILITY_HOOK_MONDAY . ' 12:00', $this->timezone )->utc(),
        )->create();

        // A block on the injected connection but a day outside the window — the
        // scoped clause must leave it be, so it can never suppress a slot here.
        CalendarBusyBlock::factory()->for( $injected, 'connection' )->spanning(
            CarbonImmutable::parse( '2026-06-02 11:00', $this->timezone )->utc(),
            CarbonImmutable::parse( '2026-06-02 12:00', $this->timezone )->utc(),
        )->create();

        // A block the subscriber never names, at a different hour, to prove the
        // clause does not simply pull every one-way connection's busy time in.
        CalendarBusyBlock::factory()->for( $other, 'connection' )->spanning(
            CarbonImmutable::parse( AVAILABILITY_HOOK_MONDAY . ' 14:00', $this->timezone )->utc(),
            CarbonImmutable::parse( AVAILABILITY_HOOK_MONDAY . ' 15:00', $this->timezone )->utc(),
        )->create();

        // Left alone, none of them do anything — every connection only pushes.
        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->toContain( '11:00' )
            ->toContain( '14:00' );

        addFilter( 'ap.bookings.availabilityQuery', static function ( Builder $query, array $criteria ) use ( $injected ): Builder {
            if ( 'busy_blocks' !== $criteria['subject'] ) {
                return $query;
            }

            // A grouped alternative confined to the named connection and the
            // window the criteria carry — the shape the docblock asks a widening
            // subscriber for, reusing the model's own overlap scope so a bare
            // `orWhere` can escape neither the connection nor the window.
            return $query->orWhere( static function ( Builder $alternative ) use ( $injected, $criteria ): void {
                $alternative->overlapping( $injected->id, $criteria['from'], $criteria['until'] );
            } );
        } );

        // Recompute so the filter is reachable: the day above was cached before it
        // existed, and that the injected block only bites after invalidation is
        // what proves the filter runs inside the computation.
        availability()->invalidateProvider( $this->provider->id );

        expect( localStarts( availability()->resolve( $this->service, $this->provider, $this->window ), $this->timezone ) )
            ->not->toContain( '11:00' )
            ->toContain( '14:00' );
    } );

    it( 'refuses a busy-block subscriber that returns something other than a query', function (): void {
        addFilter( 'ap.bookings.availabilityQuery', static fn ( Builder $query, array $criteria ): mixed => 'busy_blocks' === $criteria['subject'] ? 'nope' : $query );

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

    it( 'blames the service, not the hook, for a non-positive base duration', function (): void {
        // A zero-length service reached the filter as 0 and failed as though a
        // subscriber had returned it — a misdirection that sent an operator
        // hunting a hook that never ran. The message now names the service.
        DB::table( 'services' )->where( 'id', $this->service->getKey() )->update( [ 'duration' => 0 ] );

        expect( fn () => availability()->resolve( $this->service->fresh(), $this->provider, $this->window ) )
            ->toThrow( UnexpectedValueException::class, 'bookable service must be at least one minute long' );
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
