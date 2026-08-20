<?php

/**
 * Benchmark application bootstrap.
 *
 * Boots a Testbench application registered with the same providers the test suite
 * uses, pointed at an in-memory SQLite database, and migrated — so a benchmark
 * script can `$app = require __DIR__ . '/bootstrap.php';` and start seeding.
 *
 * The cache store defaults to `array`; export `BOOKING_BENCH_CACHE=redis` (with a
 * configured redis) to measure warm availability against the store a production
 * install would actually use, which is where the warm p95 target is meaningful.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Providers\BookingsServiceProvider;
use ArtisanPackUI\Core\CoreServiceProvider;
use ArtisanPackUI\Hooks\Providers\HooksServiceProvider;
use ArtisanPackUI\Security\SecurityServiceProvider;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require __DIR__ . '/../vendor/autoload.php';

// A soak resolves the same window thousands of times and, on the array cache,
// keeps every recomputed day in memory — headroom a benchmark needs and a normal
// request never would. Override with BOOKING_BENCH_MEMORY_LIMIT.
ini_set( 'memory_limit', getenv( 'BOOKING_BENCH_MEMORY_LIMIT' ) ?: '1024M' );

// Livewire renders the widget and admin screens, and the package registers its
// components only where Livewire is present. Registered here on the same
// condition the test suite uses, so the boot path the package takes matches.
$providers = [
    CoreServiceProvider::class,
    HooksServiceProvider::class,
    SecurityServiceProvider::class,
];

if ( class_exists( LivewireServiceProvider::class ) ) {
    $providers[] = LivewireServiceProvider::class;
}

$providers[] = BookingsServiceProvider::class;

$app = Application::create(
    options: [
        'extra' => [
            'providers' => $providers,
            // The package is its own root here rather than an installed
            // dependency, so nothing needs discovering — the providers above are
            // the whole graph.
            'dont-discover' => [ '*' ],
            // Loaded before the providers boot, the same stage the test suite
            // sets its environment in: the encryption key security boots
            // against, an array cache unless a store is asked for, a synchronous
            // queue so a dispatched sync job runs inline, and Testbench's default
            // in-memory SQLite database everything migrates into.
            'env' => [
                'APP_KEY="base64:' . base64_encode( random_bytes( 32 ) ) . '"',
                'CACHE_STORE=' . ( getenv( 'BOOKING_BENCH_CACHE' ) ?: 'array' ),
                'QUEUE_CONNECTION=sync',
            ],
        ],
    ],
);

// Package models resolve their factories out of the package namespace rather than
// the application's App\Models convention, exactly as the test suite arranges.
Factory::guessFactoryNamesUsing( static function ( string $modelName ): string {
    return 'ArtisanPackUI\\Bookings\\Database\\Factories\\' . class_basename( $modelName ) . 'Factory';
} );

$app->make( ConsoleKernel::class )->call( 'migrate', [ '--force' => true ] );

return $app;
