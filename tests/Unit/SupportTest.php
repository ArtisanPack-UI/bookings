<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;

/**
 * Builds a range from two `H:i` clock faces on a fixed day, in UTC.
 */
function utcRange( string $start, string $end ): TimeRange
{
    return new TimeRange(
        CarbonImmutable::parse( '2026-04-06 ' . $start, 'UTC' ),
        CarbonImmutable::parse( '2026-04-06 ' . $end, 'UTC' ),
    );
}

describe( 'TimeRange', function (): void {
    it( 'normalises its endpoints to immutable instants', function (): void {
        $start = Carbon\Carbon::parse( '2026-04-06 09:00:00', 'UTC' );
        $range = new TimeRange( $start, $start->copy()->addHour() );

        $start->addYear();

        expect( $range->start )->toBeInstanceOf( CarbonImmutable::class )
            ->and( $range->start->year )->toBe( 2026 );
    } );

    it( 'measures its length in minutes', function (): void {
        expect( utcRange( '09:00', '10:30' )->minutes() )->toBe( 90 );
    } );

    it( 'rounds a part-minute length down', function (): void {
        $range = new TimeRange(
            CarbonImmutable::parse( '2026-04-06 09:00:00', 'UTC' ),
            CarbonImmutable::parse( '2026-04-06 09:30:30', 'UTC' ),
        );

        expect( $range->minutes() )->toBe( 30 );
    } );

    it( 'refuses a range that ends before it starts', function (): void {
        utcRange( '10:00', '09:00' );
    } )->throws( InvalidArgumentException::class );

    it( 'refuses a range of no length', function (): void {
        utcRange( '10:00', '10:00' );
    } )->throws( InvalidArgumentException::class );

    it( 'reports an overlap', function ( string $otherStart, string $otherEnd, bool $expected ): void {
        expect( utcRange( '09:00', '10:00' )->overlaps( utcRange( $otherStart, $otherEnd ) ) )->toBe( $expected );
    } )->with( [
        'identical'          => [ '09:00', '10:00', true ],
        'contained'          => [ '09:15', '09:45', true ],
        'straddling the end' => [ '09:30', '10:30', true ],
        'butted up after'    => [ '10:00', '11:00', false ],
        'butted up before'   => [ '08:00', '09:00', false ],
        'well clear'         => [ '14:00', '15:00', false ],
    ] );

    it( 'compares equal to a range over the same instants', function (): void {
        expect( utcRange( '09:00', '10:00' )->equals( utcRange( '09:00', '10:00' ) ) )->toBeTrue()
            ->and( utcRange( '09:00', '10:00' )->equals( utcRange( '09:00', '10:30' ) ) )->toBeFalse();
    } );

    it( 'compares equal across zones naming the same instant', function (): void {
        $utc     = utcRange( '14:00', '15:00' );
        $chicago = new TimeRange(
            CarbonImmutable::parse( '2026-04-06 09:00:00', 'America/Chicago' ),
            CarbonImmutable::parse( '2026-04-06 10:00:00', 'America/Chicago' ),
        );

        expect( $utc->equals( $chicago ) )->toBeTrue();
    } );

    it( 'serialises to ISO 8601', function (): void {
        expect( utcRange( '09:00', '10:00' )->toArray() )->toBe( [
            'start' => '2026-04-06T09:00:00+00:00',
            'end'   => '2026-04-06T10:00:00+00:00',
        ] );
    } );
} );

describe( 'Slot', function (): void {
    it( 'is unassigned by default', function (): void {
        expect( ( new Slot( utcRange( '09:00', '09:30' ) ) )->providerId )->toBeNull();
    } );

    it( 'assigns a provider without changing the original', function (): void {
        $unassigned = new Slot( utcRange( '09:00', '09:30' ) );
        $assigned   = $unassigned->forProvider( 7 );

        expect( $assigned->providerId )->toBe( 7 )
            ->and( $unassigned->providerId )->toBeNull()
            ->and( $assigned->period->equals( $unassigned->period ) )->toBeTrue();
    } );

    it( 'serialises with its provider', function (): void {
        expect( ( new Slot( utcRange( '09:00', '09:30' ), 7 ) )->jsonSerialize() )->toBe( [
            'start'       => '2026-04-06T09:00:00+00:00',
            'end'         => '2026-04-06T09:30:00+00:00',
            'provider_id' => 7,
        ] );
    } );
} );
