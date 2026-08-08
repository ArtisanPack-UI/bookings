<?php

declare( strict_types=1 );

use Illuminate\Support\Facades\DB;
use Tests\Concerns\TestsWithMysql;

uses( TestsWithMysql::class )->group( 'mysql' );

it( 'holds a named lock against a second connection', function (): void {
    // The race the booking domain has to win: two requests taking the last
    // slot at the same instant. The guard is a named advisory lock, and sqlite
    // has no equivalent — this is the harness proving the primitive is
    // available and exclusive before anything depends on it.
    config()->set( 'database.connections.mysql_contender', config( 'database.connections.mysql' ) );

    $holder    = DB::connection( 'mysql' );
    $contender = DB::connection( 'mysql_contender' );
    $lockName  = 'bookings:harness:' . uniqid();

    try {
        expect( (int) $holder->selectOne( 'select get_lock(?, 0) as acquired', [ $lockName ] )->acquired )
            ->toBe( 1 );

        // Zero timeout: the second connection must be refused immediately
        // rather than waiting, which is what makes the lock a usable guard on
        // a request path.
        expect( (int) $contender->selectOne( 'select get_lock(?, 0) as acquired', [ $lockName ] )->acquired )
            ->toBe( 0 );
    } finally {
        $holder->selectOne( 'select release_lock(?) as released', [ $lockName ] );
        $contender->disconnect();
    }
} );

it( 'lets the next waiter take the lock once it is released', function (): void {
    config()->set( 'database.connections.mysql_contender', config( 'database.connections.mysql' ) );

    $holder    = DB::connection( 'mysql' );
    $contender = DB::connection( 'mysql_contender' );
    $lockName  = 'bookings:harness:' . uniqid();

    try {
        // Assert the holder's half of the lifecycle rather than assuming it:
        // if GET_LOCK quietly failed, the contender would be taking a name
        // nobody ever held and this test would pass without proving anything.
        expect( (int) $holder->selectOne( 'select get_lock(?, 0) as acquired', [ $lockName ] )->acquired )
            ->toBe( 1 );
        expect( (int) $holder->selectOne( 'select release_lock(?) as released', [ $lockName ] )->released )
            ->toBe( 1 );

        expect( (int) $contender->selectOne( 'select get_lock(?, 0) as acquired', [ $lockName ] )->acquired )
            ->toBe( 1 );
    } finally {
        $contender->selectOne( 'select release_lock(?) as released', [ $lockName ] );
        $contender->disconnect();
    }
} );
