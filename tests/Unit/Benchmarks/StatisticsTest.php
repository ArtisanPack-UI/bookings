<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Benchmarks\Statistics;

it( 'summarises a set of samples', function (): void {
    $summary = Statistics::summarize( [ 5.0, 1.0, 3.0, 2.0, 4.0 ] );

    expect( $summary['count'] )->toBe( 5 )
        ->and( $summary['min'] )->toBe( 1.0 )
        ->and( $summary['max'] )->toBe( 5.0 )
        ->and( $summary['mean'] )->toBe( 3.0 );
} );

it( 'reads percentiles by nearest rank', function (): void {
    // 1..100, so the pth percentile is exactly p by nearest rank.
    $samples = range( 1, 100 );

    expect( Statistics::percentile( $samples, 50 ) )->toBe( 50.0 )
        ->and( Statistics::percentile( $samples, 95 ) )->toBe( 95.0 )
        ->and( Statistics::percentile( $samples, 99 ) )->toBe( 99.0 );
} );

it( 'clamps the 100th percentile to the last sample', function (): void {
    expect( Statistics::percentile( [ 1, 2, 3, 4 ], 100 ) )->toBe( 4.0 );
} );

it( 'handles a single sample', function (): void {
    $summary = Statistics::summarize( [ 42.0 ] );

    expect( $summary['p95'] )->toBe( 42.0 )
        ->and( $summary['min'] )->toBe( 42.0 )
        ->and( $summary['max'] )->toBe( 42.0 );
} );

it( 'refuses to summarise nothing', function (): void {
    Statistics::summarize( [] );
} )->throws( InvalidArgumentException::class );
