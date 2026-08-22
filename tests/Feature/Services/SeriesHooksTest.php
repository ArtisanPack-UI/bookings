<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\SeriesEditScope;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * The hooks a series edit is required to fire, so a rename cannot pass silently.
 *
 * @var array<int, string>
 */
const SERIES_HOOKS = [
    'ap.bookings.series.editApplying',
];

/**
 * The booking hooks a materialisation is required to fire once per occurrence.
 *
 * @var array<int, string>
 */
const SERIES_OCCURRENCE_HOOKS = [
    'ap.bookings.created',
    'ap.bookings.confirmed',
    'ap.bookings.cancelled',
];

beforeEach( function (): void {
    $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );
} );

afterEach( function (): void {
    $this->travelBack();

    foreach ( [ ...SERIES_HOOKS, ...SERIES_OCCURRENCE_HOOKS ] as $hook ) {
        removeAllActions( $hook );
    }
} );

describe( 'ap.bookings.series.editApplying', function (): void {
    it( 'hands a subscriber the series, the scope, and the changes', function (): void {
        $seen = [];

        addAction( 'ap.bookings.series.editApplying', function ( mixed ...$args ) use ( &$seen ): void {
            $seen[] = $args;
        } );

        [ $service ] = bookableService();

        $series  = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );
        $changes = [ 'notes' => 'Bring the draft.' ];

        seriesService()->edit(
            $series,
            SeriesEditScope::This,
            $changes,
            $series->occurrences()->first(),
            BookingActor::Admin,
        );

        expect( $seen )->toHaveCount( 1 )
            ->and( $seen[0][0] )->toBeInstanceOf( BookingSeries::class )
            ->and( $seen[0][0]->is( $series ) )->toBeTrue()
            // A string rather than the enum, so a subscriber can compare against
            // the same value the SeriesEdited event and the admin form carry.
            ->and( $seen[0][1] )->toBe( 'this' )
            ->and( $seen[0][2] )->toBe( $changes );
    } );

    it( 'fires once for each of the three scopes, before the edit lands', function (): void {
        $scopes = [];

        addAction(
            'ap.bookings.series.editApplying',
            function ( BookingSeries $series, string $scope ) use ( &$scopes ): void {
                // Read back inside the callback: the hook is "applying", so the
                // rule must still be the old one when a subscriber sees it.
                $scopes[ $scope ] = (string) $series->fresh()->rrule;
            },
        );

        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => 'FREQ=WEEKLY;COUNT=4',
        ] ) );

        seriesService()->edit( $series, SeriesEditScope::This, [], $series->occurrences()->first() );
        seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [],
            $series->occurrences()->skip( 3 )->first(),
        );
        seriesService()->edit( $series, SeriesEditScope::All, [ 'rrule' => 'FREQ=WEEKLY;COUNT=2' ] );

        expect( $scopes )->toBe( [
            'this'               => 'FREQ=WEEKLY;COUNT=4',
            'this_and_following' => 'FREQ=WEEKLY;COUNT=4',
            'all'                => 'FREQ=WEEKLY;COUNT=4',
        ] );
    } );
} );

describe( 'occurrence hooks', function (): void {
    it( 'fires the booking hooks once per occurrence, not once per occurrence squared', function (): void {
        // The failure this exists to catch: a materialisation that re-fires the
        // whole run's hooks on every occurrence. A series of twelve would send
        // seventy-eight confirmation emails and nobody would notice until the
        // mail bill arrived.
        $counts = [];

        foreach ( SERIES_OCCURRENCE_HOOKS as $hook ) {
            addAction( $hook, function () use ( &$counts, $hook ): void {
                $counts[ $hook ] = ( $counts[ $hook ] ?? 0 ) + 1;
            } );
        }

        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => 'FREQ=WEEKLY;COUNT=4',
        ] ) );

        expect( $series->occurrences()->count() )->toBe( 4 )
            ->and( $counts['ap.bookings.created'] )->toBe( 4 )
            ->and( $counts['ap.bookings.confirmed'] )->toBe( 4 )
            ->and( $counts )->not->toHaveKey( 'ap.bookings.cancelled' );
    } );

    it( 'fires a cancellation for each occurrence a split discards, and no more', function (): void {
        $cancelled = 0;

        addAction( 'ap.bookings.cancelled', function () use ( &$cancelled ): void {
            $cancelled++;
        } );

        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => 'FREQ=WEEKLY;COUNT=4',
        ] ) );

        seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [],
            $series->occurrences()->skip( 2 )->first(),
        );

        expect( $cancelled )->toBe( 2 );
    } );
} );
