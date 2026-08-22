<?php

declare( strict_types=1 );

use Tests\Concerns\TestsWithPostgres;

uses( TestsWithPostgres::class )->group( 'postgres' );

// Postgres releases a transaction-scoped advisory lock itself at commit, so the
// thing to prove here is the opposite of MySQL's: that the lock is genuinely
// held for the whole transaction rather than dropped the moment the statement
// taking it finished.
defineSlotLockTests();
