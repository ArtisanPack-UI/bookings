<?php

/**
 * Postgres test database concern.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Concerns;

/**
 * Runs the test against a real Postgres server.
 *
 * The counterpart to `TestsWithMysql`. The advisory-lock primitive differs —
 * `pg_advisory_xact_lock` rather than `GET_LOCK` — so anything claiming to
 * prove race-safety has to prove it on both engines an application might
 * actually deploy on, not only the one CI happens to start first. Tests using
 * this trait carry the `postgres` group.
 *
 * As with `TestsWithMysql`, point `DB_DATABASE` at a throwaway database: tests
 * using this trait migrate into whatever it names.
 *
 * @since 1.0.0
 */
trait TestsWithPostgres
{
    /**
     * Points the test application at the Postgres test database.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return void
     */
    protected function defineDatabaseConnection( $app ): void
    {
        $app['config']->set( 'database.default', 'pgsql' );
        $app['config']->set( 'database.connections.pgsql', [
            'driver'   => 'pgsql',
            'host'     => env( 'DB_HOST', '127.0.0.1' ),
            'port'     => env( 'DB_PORT', '5432' ),
            'database' => env( 'DB_DATABASE', 'bookings_test' ),
            'username' => env( 'DB_USERNAME', 'postgres' ),
            'password' => env( 'DB_PASSWORD', '' ),
            'charset'  => 'utf8',
            'prefix'   => '',
            'schema'   => 'public',
        ] );
    }

    /**
     * Determines whether the test needs a database server outside the process.
     *
     * @since 1.0.0
     *
     * @return bool Always true.
     */
    protected function usesExternalDatabase(): bool
    {
        return true;
    }

    /**
     * Gets the name of the connection this test runs against.
     *
     * Kept in step with `defineDatabaseConnection()` above: the base TestCase
     * uses it to notice when the engine changes between tests and let
     * RefreshDatabase migrate again.
     *
     * @since 1.0.0
     *
     * @return string The Postgres connection name.
     */
    protected function databaseConnectionName(): string
    {
        return 'pgsql';
    }
}
