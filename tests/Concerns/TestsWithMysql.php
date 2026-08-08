<?php

/**
 * MySQL test database concern.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Concerns;

use function env;

/**
 * Runs the test against a real MySQL server.
 *
 * Booking creation has to be race-safe: two customers taking the last slot at
 * the same instant must not both get it. The guard is a named advisory lock —
 * MySQL's `GET_LOCK`, Postgres' `pg_advisory_xact_lock` — and sqlite has no
 * equivalent, so a test that proves the guard holds cannot run in memory. Such
 * tests use this trait and carry the `mysql` group.
 *
 * The connection is read from the usual `DB_*` environment variables so CI can
 * point it at a service container and a developer at whatever they have
 * running. Point `DB_DATABASE` at a throwaway schema: tests using this trait
 * migrate into whatever it names, and the default `bookings_test` exists so
 * that a forgotten variable cannot land on a database somebody cares about.
 * When the server is unreachable the base TestCase skips the test —
 * except where `BOOKINGS_REQUIRE_EXTERNAL_DB=1`, which is what CI sets so a
 * missing server fails the build rather than quietly reducing coverage.
 *
 * @since 1.0.0
 */
trait TestsWithMysql
{
    /**
     * Points the test application at the MySQL test database.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return void
     */
    protected function defineDatabaseConnection( $app ): void
    {
        $app['config']->set( 'database.default', 'mysql' );
        $app['config']->set( 'database.connections.mysql', [
            'driver'    => 'mysql',
            'host'      => env( 'DB_HOST', '127.0.0.1' ),
            'port'      => env( 'DB_PORT', '3306' ),
            'database'  => env( 'DB_DATABASE', 'bookings_test' ),
            'username'  => env( 'DB_USERNAME', 'root' ),
            'password'  => env( 'DB_PASSWORD', '' ),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
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
}
