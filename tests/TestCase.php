<?php

declare( strict_types=1 );

namespace Tests;

use ArtisanPackUI\Bookings\Providers\BookingsServiceProvider;
use ArtisanPackUI\Core\CoreServiceProvider;
use ArtisanPackUI\Hooks\Providers\HooksServiceProvider;
use ArtisanPackUI\Security\SecurityServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Throwable;

use function class_basename;
use function config;
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
     * The database connection the previous test ran against.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    private static ?string $lastDatabaseConnection = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        // Before parent::setUp(), because that is what triggers RefreshDatabase.
        $this->forgetMigrationStateOnEngineChange();

        parent::setUp();

        // Package models resolve their factories out of the package namespace
        // rather than the application's App\Models convention, so the test
        // application has to be told where to look.
        Factory::guessFactoryNamesUsing( static function ( string $modelName ): string {
            return 'ArtisanPackUI\\Bookings\\Database\\Factories\\' . class_basename( $modelName ) . 'Factory';
        } );

        // The two have to agree, and nothing else makes them. A connection
        // renamed in `defineDatabaseConnection()` but not here would put the
        // engine-change check permanently to sleep and bring back the
        // unmigrated-database failure it exists to prevent — which surfaces
        // several tests away from the rename, as "no such table".
        self::assertSame(
            $this->databaseConnectionName(),
            config( 'database.default' ),
            'databaseConnectionName() has drifted from defineDatabaseConnection().',
        );

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
        // hooks backs core's default resolver, and security binds the sanitizers
        // every public request is put through — all three are hard dependencies
        // rather than suggestions, so the test application registers them the
        // same way a host application would.
        return [
            CoreServiceProvider::class,
            HooksServiceProvider::class,
            SecurityServiceProvider::class,
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
     * Gets the name of the connection this test runs against.
     *
     * Overridden by the TestsWith* concerns alongside `defineDatabaseConnection()`,
     * and kept in step with it. Only used to notice when one test's engine is not
     * the previous one's — see `forgetMigrationStateOnEngineChange()`.
     *
     * @since 1.0.0
     *
     * @return string The database connection name.
     */
    protected function databaseConnectionName(): string
    {
        return 'testbench';
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

    /**
     * Makes RefreshDatabase migrate again when the engine changes under it.
     *
     * `RefreshDatabaseState::$migrated` is one global flag for the whole run,
     * not one per connection. The first test to migrate sets it, and every test
     * after that skips migrating — which is exactly right while the suite stays
     * on one engine, and silently wrong the moment it does not. A MySQL file
     * running before a sqlite one leaves the flag set, so the sqlite test gets a
     * fresh in-memory database that nothing ever migrated into, and fails with
     * "no such table" somewhere far from the cause.
     *
     * The suite only escapes this today because the files happen to sort into a
     * lucky order. Rather than depend on that, the flag is cleared whenever the
     * engine differs from the previous test's, so each engine migrates once —
     * and MySQL is not re-migrated between every test of its own.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function forgetMigrationStateOnEngineChange(): void
    {
        $connection = $this->databaseConnectionName();

        if ( self::$lastDatabaseConnection === $connection ) {
            return;
        }

        self::$lastDatabaseConnection              = $connection;
        RefreshDatabaseState::$migrated            = false;
        RefreshDatabaseState::$inMemoryConnections = [];
    }
}
