<?php

declare( strict_types=1 );

use Illuminate\Support\Facades\DB;
use Tests\Concerns\TestsWithPostgres;

uses( TestsWithPostgres::class )->group( 'postgres' );

it( 'holds a session advisory lock against a second connection', function (): void {
    // Postgres names the same primitive differently — pg_try_advisory_lock
    // rather than GET_LOCK — so race-safety has to be proven on both engines
    // an application might deploy on, not only whichever CI starts first.
    config()->set( 'database.connections.pgsql_contender', config( 'database.connections.pgsql' ) );

    $holder    = DB::connection( 'pgsql' );
    $contender = DB::connection( 'pgsql_contender' );
    $lockKey   = random_int( 1, 2_000_000_000 );

    try {
        // Cast in SQL rather than asserting on the boolean PDO hands back:
        // pdo_pgsql has returned Postgres booleans as both real bools and the
        // strings 't'/'f' depending on version, and 'f' is truthy in PHP — an
        // assertion that reads the raw value can fail on a lock that worked.
        expect( (int) $holder->selectOne( 'select pg_try_advisory_lock(?)::int as acquired', [ $lockKey ] )->acquired )
            ->toBe( 1 );

        expect( (int) $contender->selectOne( 'select pg_try_advisory_lock(?)::int as acquired', [ $lockKey ] )->acquired )
            ->toBe( 0 );
    } finally {
        $holder->selectOne( 'select pg_advisory_unlock(?) as released', [ $lockKey ] );
        $contender->disconnect();
    }
} );

it( 'lets the next waiter take the lock once it is released', function (): void {
    config()->set( 'database.connections.pgsql_contender', config( 'database.connections.pgsql' ) );

    $holder    = DB::connection( 'pgsql' );
    $contender = DB::connection( 'pgsql_contender' );
    $lockKey   = random_int( 1, 2_000_000_000 );

    try {
        // The holder's half is asserted rather than assumed: a lock that was
        // never taken would let the contender succeed against a free name and
        // this test would prove nothing.
        expect( (int) $holder->selectOne( 'select pg_try_advisory_lock(?)::int as acquired', [ $lockKey ] )->acquired )
            ->toBe( 1 );
        expect( (int) $holder->selectOne( 'select pg_advisory_unlock(?)::int as released', [ $lockKey ] )->released )
            ->toBe( 1 );

        expect( (int) $contender->selectOne( 'select pg_try_advisory_lock(?)::int as acquired', [ $lockKey ] )->acquired )
            ->toBe( 1 );
    } finally {
        $contender->selectOne( 'select pg_advisory_unlock(?) as released', [ $lockKey ] );
        $contender->disconnect();
    }
} );
