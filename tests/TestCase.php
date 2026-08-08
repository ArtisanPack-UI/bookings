<?php

declare( strict_types=1 );

namespace Tests;

use ArtisanPackUI\Bookings\Providers\BookingsServiceProvider;
use ArtisanPackUI\Core\CoreServiceProvider;
use ArtisanPackUI\Hooks\Providers\HooksServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Throwable;

use function class_basename;
use function env;
use function filter_var;
use function sprintf;

use const FILTER_VALIDATE_BOOLEAN;

/**
 * Base Test Case
 *
 * Provides base functionality for all package tests.
 *
 * @since   1.0.0
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Package models resolve their factories out of the package namespace
        // rather than the application's App\Models convention, so the test
        // application has to be told where to look.
        Factory::guessFactoryNamesUsing( static function ( string $modelName ): string {
            return 'ArtisanPackUI\\Bookings\\Database\\Factories\\' . class_basename( $modelName ) . 'Factory';
        } );

        if ( $this->usesExternalDatabase() ) {
            $this->skipUnlessExternalDatabaseIsReachable();
        }
    }

    /**
     * Gets package providers.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return array<int, class-string> Array of service provider class names.
     */
    protected function getPackageProviders( $app ): array
    {
        // Core owns the shared site context this package scopes its queries by,
        // and hooks backs core's default resolver — both are hard dependencies
        // rather than suggestions, so the test application registers them the
        // same way a host application would.
        return [
            CoreServiceProvider::class,
            HooksServiceProvider::class,
            BookingsServiceProvider::class,
        ];
    }

    /**
     * Defines environment setup.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     */
    protected function defineEnvironment( $app ): void
    {
        // Setup app key for encryption
        $app['config']->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );

        $this->defineDatabaseConnection( $app );
    }

    /**
     * Points the test application at a database connection.
     *
     * Overridden by the TestsWithMysql and TestsWithPostgres concerns, which is
     * how the handful of tests that need a real server — row locks, advisory
     * locks, anything whose race-safety sqlite cannot express — pick a driver
     * without every other test paying for one.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return void
     */
    protected function defineDatabaseConnection( $app ): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set( 'database.default', 'testbench' );
        $app['config']->set( 'database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ] );
    }

    /**
     * Determines whether the test needs a database server outside the process.
     *
     * @since 1.0.0
     *
     * @return bool True when the test cannot run against sqlite in memory.
     */
    protected function usesExternalDatabase(): bool
    {
        return false;
    }

    /**
     * Skips — or fails — a test whose database server is not reachable.
     *
     * A developer without MySQL running should still get a green suite, so an
     * unreachable server skips by default. CI must not get the same courtesy:
     * a skipped lock test reads as "race-safety verified" while verifying
     * nothing. Set BOOKINGS_REQUIRE_EXTERNAL_DB=1 there and the same test fails
     * loudly instead.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function skipUnlessExternalDatabaseIsReachable(): void
    {
        $connection = DB::connection();

        try {
            $connection->getPdo();
        } catch ( Throwable $unreachable ) {
            $message = sprintf(
                'The "%s" database connection is not reachable: %s',
                $connection->getName(),
                $unreachable->getMessage(),
            );

            if ( filter_var( env( 'BOOKINGS_REQUIRE_EXTERNAL_DB', false ), FILTER_VALIDATE_BOOLEAN ) ) {
                self::fail( $message );
            }

            self::markTestSkipped( $message );
        }
    }
}
