<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Exceptions\SlotLockTimeoutException;
use ArtisanPackUI\Bookings\Services\ProviderSlotLock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TestsWithSqlite;
use Tests\Fixtures\LocklessStore;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * Gets the lock under test.
 *
 * @return ProviderSlotLock The lock.
 */
function slotLock(): ProviderSlotLock
{
    return app( ProviderSlotLock::class );
}

describe( 'lock identity', function (): void {
    it( 'gives two slots on one provider-day the same lock', function (): void {
        // The property the whole guard rests on. Two overlapping slots start at
        // different instants, so an instant-keyed lock would hand them different
        // locks — and the unique index, keyed on the start time, would then
        // accept both. Bucketing by the day is what makes overlap impossible to
        // race, because a day is wider than anything booked inside it.
        $nine    = CarbonImmutable::parse( '2026-06-01 09:00', 'America/Chicago' );
        $quarter = $nine->addMinutes( 15 );

        expect( slotLock()->lockName( 7, $nine, 'America/Chicago' ) )
            ->toBe( slotLock()->lockName( 7, $quarter, 'America/Chicago' ) )
            ->and( slotLock()->lockKey( 7, $nine, 'America/Chicago' ) )
            ->toBe( slotLock()->lockKey( 7, $quarter, 'America/Chicago' ) );
    } );

    it( 'reads the day in the provider\'s zone, not the caller\'s', function (): void {
        // Two customers holding the same moment from different zones are
        // contending for the same provider-day, and a bucket read off the
        // caller's clock face would give them different locks and let both
        // through.
        $chicago = CarbonImmutable::parse( '2026-06-01 10:00', 'America/Chicago' );
        $london  = $chicago->setTimezone( 'Europe/London' );

        expect( slotLock()->lockName( 7, $chicago, 'America/Chicago' ) )
            ->toBe( slotLock()->lockName( 7, $london, 'America/Chicago' ) );

        // And an instant late enough to be tomorrow in UTC is still today for a
        // provider in Chicago, which is the case reading the day in UTC gets
        // wrong — it would split one working evening across two locks.
        $evening = CarbonImmutable::parse( '2026-06-01 20:00', 'America/Chicago' );

        expect( $evening->utc()->toDateString() )->toBe( '2026-06-02' )
            ->and( slotLock()->lockName( 7, $evening, 'America/Chicago' ) )
            ->toBe( slotLock()->lockName( 7, $chicago, 'America/Chicago' ) );
    } );

    it( 'gives different providers and different days different locks', function (): void {
        $start = CarbonImmutable::parse( '2026-06-01 10:00', 'UTC' );

        expect( slotLock()->lockName( 7, $start, 'UTC' ) )->not->toBe( slotLock()->lockName( 8, $start, 'UTC' ) )
            ->and( slotLock()->lockName( 7, $start, 'UTC' ) )
            ->not->toBe( slotLock()->lockName( 7, $start->addDay(), 'UTC' ) )
            ->and( slotLock()->lockKey( 7, $start, 'UTC' ) )->not->toBe( slotLock()->lockKey( 8, $start, 'UTC' ) );
    } );

    it( 'keeps the name inside the limit MySQL enforces on one', function (): void {
        // MySQL caps a lock name at 64 characters and misbehaves past it rather
        // than complaining, so the name has to be fixed-width by construction
        // instead of by whatever the ids happen to be today.
        expect( strlen( slotLock()->lockName( PHP_INT_MAX, CarbonImmutable::now(), 'UTC' ) ) )
            ->toBeLessThanOrEqual( 64 );
    } );

    it( 'keeps the Postgres key positive and inside a bigint', function (): void {
        $key = slotLock()->lockKey( PHP_INT_MAX, CarbonImmutable::now(), 'UTC' );

        expect( $key )->toBeGreaterThan( 0 )
            ->and( $key )->toBeLessThan( 2 ** 63 );
    } );
} );

describe( 'holding the lock', function (): void {
    it( 'runs the callback inside a transaction and hands its answer back', function (): void {
        // The transaction is part of the contract, not a convenience: reading
        // availability and writing the booking have to be one indivisible step.
        $levelInside = null;

        $answer = slotLock()->withSlotLock( 7, CarbonImmutable::now(), 'UTC', function () use ( &$levelInside ): string {
            $levelInside = DB::connection()->transactionLevel();

            return 'booked';
        } );

        expect( $answer )->toBe( 'booked' )
            ->and( $levelInside )->toBeGreaterThan( DB::connection()->transactionLevel() );
    } );

    it( 'rolls the transaction back when the callback throws', function (): void {
        $start = CarbonImmutable::now();

        expect( fn () => slotLock()->withSlotLock( 7, $start, 'UTC', static function (): void {
            DB::table( 'services' )->insert( [
                'name'                  => 'Rolled back',
                'slug'                  => 'rolled-back',
                'duration'              => 30,
                'intake_schema_version' => 1,
                'created_at'            => now(),
                'updated_at'            => now(),
            ] );

            throw new RuntimeException( 'Something went wrong mid-booking.' );
        } ) )->toThrow( RuntimeException::class );

        expect( DB::table( 'services' )->where( 'slug', 'rolled-back' )->exists() )->toBeFalse();
    } );

    it( 'releases the lock so the next caller can take it', function (): void {
        $start = CarbonImmutable::now();

        slotLock()->withSlotLock( 7, $start, 'UTC', static fn (): bool => true );

        expect( slotLock()->withSlotLock( 7, $start, 'UTC', static fn (): string => 'again' ) )->toBe( 'again' );
    } );

    it( 'gives up rather than queueing forever behind a lock somebody else holds', function (): void {
        // The sqlite path stands the cache lock in for an advisory lock, so
        // taking that lock by hand is the same contention a second application
        // server would create.
        config()->set( 'artisanpack.bookings.lock.wait_seconds', 1 );

        $start = CarbonImmutable::now();
        $held  = Cache::lock( slotLock()->lockName( 7, $start, 'UTC' ), 60 );

        expect( $held->get() )->toBeTrue();

        try {
            expect( fn () => slotLock()->withSlotLock( 7, $start, 'UTC', static fn (): bool => true ) )
                ->toThrow( SlotLockTimeoutException::class );
        } finally {
            $held->forceRelease();
        }
    } );

    it( 'refuses to pretend it locked anything when the cache store cannot', function (): void {
        // Silently running unserialised would be the worst available outcome:
        // every booking would look fine right up until two of them landed in the
        // same slot on a busy afternoon.
        config()->set( 'cache.stores.lockless', [ 'driver' => 'lockless' ] );
        config()->set( 'artisanpack.bookings.lock.store', 'lockless' );

        Cache::extend( 'lockless', fn (): mixed => Cache::repository( new LocklessStore() ) );

        expect( fn () => slotLock()->withSlotLock( 7, CarbonImmutable::now(), 'UTC', static fn (): bool => true ) )
            ->toThrow( RuntimeException::class );
    } );
} );
