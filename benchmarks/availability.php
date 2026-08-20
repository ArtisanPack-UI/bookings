<?php

/**
 * Availability load benchmark.
 *
 * Answers the availability half of issue #50: how long does resolving a service's
 * slots take, warm and cold, for five providers across ninety days at
 * fifteen-minute intervals. Run it with `composer bench:availability`.
 *
 * Knobs, all environment variables:
 *   BOOKING_BENCH_PROVIDERS        providers offering the service (default 5)
 *   BOOKING_BENCH_DAYS             days in the window (default 90)
 *   BOOKING_BENCH_COLD_ITERATIONS  cold resolves to time (default 20)
 *   BOOKING_BENCH_WARM_ITERATIONS  warm resolves to time (default 100)
 *   BOOKING_BENCH_CACHE            cache store for the warm path (default array)
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Benchmarks\AvailabilityScenario;
use ArtisanPackUI\Bookings\Services\AvailabilityService;

/** @var Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/bootstrap.php';

$providers      = (int) ( getenv( 'BOOKING_BENCH_PROVIDERS' ) ?: 5 );
$days           = (int) ( getenv( 'BOOKING_BENCH_DAYS' ) ?: 90 );
$coldIterations = (int) ( getenv( 'BOOKING_BENCH_COLD_ITERATIONS' ) ?: 20 );
$warmIterations = (int) ( getenv( 'BOOKING_BENCH_WARM_ITERATIONS' ) ?: 100 );

$scenario = new AvailabilityScenario( $app->make( AvailabilityService::class ) );
$service  = $scenario->seed( $providers );
$result   = $scenario->measure( $service, $days, $coldIterations, $warmIterations );

$slotInterval = (int) config( 'artisanpack.bookings.slot_interval', 15 );

echo "Availability resolve — {$result['providers']} providers × {$result['days']} days × {$slotInterval}-min intervals\n";
echo str_repeat( '-', 64 ) . "\n";
echo 'Slots resolved across the window: ' . $result['slots'] . "\n";
echo 'Cache store: ' . config( 'cache.default' ) . "\n\n";

printf( "%-6s %8s %8s %8s %8s %8s %8s %8s\n", 'state', 'n', 'min', 'mean', 'p50', 'p95', 'p99', 'max' );

foreach ( [ 'cold', 'warm' ] as $state ) {
    $s = $result[ $state ];

    printf(
        "%-6s %8d %8.1f %8.1f %8.1f %8.1f %8.1f %8.1f\n",
        $state,
        $s['count'],
        $s['min'],
        $s['mean'],
        $s['p50'],
        $s['p95'],
        $s['p99'],
        $s['max'],
    );
}

echo "\nTimings in milliseconds. Target: warm p95 < 200ms.\n";
