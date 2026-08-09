<?php

/**
 * SQLite test database concern.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Concerns;

/**
 * Runs the test against an in-memory SQLite database.
 *
 * This is what the base TestCase already does, so the trait exists to say so
 * at the top of a test file rather than to change anything — the counterpart
 * to `TestsWithMysql`, which does change the driver. Used deliberately, the
 * pair makes "which engine is this test's behaviour true on?" answerable
 * without reading the harness.
 *
 * @since 1.0.0
 */
trait TestsWithSqlite
{
    /**
     * Points the test application at an in-memory SQLite database.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return void
     */
    protected function defineDatabaseConnection( $app ): void
    {
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
     * Kept in step with `defineDatabaseConnection()` above: the base TestCase
     * uses it to notice when the engine changes between tests and let
     * RefreshDatabase migrate again.
     *
     * @since 1.0.0
     *
     * @return string The in-memory SQLite connection name.
     */
    protected function databaseConnectionName(): string
    {
        return 'testbench';
    }
}
