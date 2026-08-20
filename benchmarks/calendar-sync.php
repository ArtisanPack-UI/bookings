<?php

/**
 * Calendar-sync throughput benchmark.
 *
 * Answers the throughput half of issue #50: how fast one worker drains the sync
 * queue with Google faked in-process. Run it with `composer bench:calendar-sync`.
 *
 * Each booking is pushed through the real SyncBookingToCalendars job, so the
 * number reported is the package's own per-push cost — the orchestrator, the
 * ledger write, and the driver call — not a shortcut around them. Real queue
 * backpressure is then a function of the queue driver and worker count layered on
 * top of this ceiling.
 *
 * Knobs, all environment variables:
 *   BOOKING_BENCH_PROVIDERS            providers, each with one connection (default 5)
 *   BOOKING_BENCH_BOOKINGS_PER_PROVIDER bookings per provider to push (default 200)
 *   BOOKING_BENCH_SYNC_LATENCY_MS      simulated per-call calendar latency (default 0)
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Benchmarks\CalendarSyncScenario;
use ArtisanPackUI\Bookings\Benchmarks\FakeGoogleCalendarDriver;
use ArtisanPackUI\Bookings\Contracts\CalendarDriverRegistry;

/** @var Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/bootstrap.php';

$providers           = (int) ( getenv( 'BOOKING_BENCH_PROVIDERS' ) ?: 5 );
$bookingsPerProvider = (int) ( getenv( 'BOOKING_BENCH_BOOKINGS_PER_PROVIDER' ) ?: 200 );
$latencyMs           = (float) ( getenv( 'BOOKING_BENCH_SYNC_LATENCY_MS' ) ?: 0 );

$scenario = new CalendarSyncScenario( $app->make( CalendarDriverRegistry::class ) );
$scenario->useDriver( new FakeGoogleCalendarDriver( (int) round( $latencyMs * 1000 ) ) );

$pairs  = $scenario->seed( $providers, $bookingsPerProvider );
$result = $scenario->measure( $pairs );

$latency = $result['latency'];

echo "Calendar sync — {$result['pushes']} pushes across {$providers} providers (Google faked)\n";
echo str_repeat( '-', 64 ) . "\n";
printf( "Simulated calendar latency: %.1f ms/call\n", $latencyMs );
printf( "Wall time:  %.3f s\n", $result['seconds'] );
printf( "Throughput: %.1f pushes/sec\n\n", $result['throughput'] );

printf( "%-8s %8s %8s %8s %8s %8s %8s\n", 'metric', 'min', 'mean', 'p50', 'p95', 'p99', 'max' );
printf(
    "%-8s %8.2f %8.2f %8.2f %8.2f %8.2f %8.2f\n",
    'latency',
    $latency['min'],
    $latency['mean'],
    $latency['p50'],
    $latency['p95'],
    $latency['p99'],
    $latency['max'],
);

echo "\nDriver calls: " . json_encode( $result['driver'] ) . "\n";
echo 'Events on the fake calendar: ' . $result['events'] . "\n";
echo "\nPer-push latency in milliseconds; throughput in pushes per second.\n";
