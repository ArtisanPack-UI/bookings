<?php

declare( strict_types=1 );

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithPostgres;

uses( TestsWithPostgres::class, RefreshDatabase::class )->group( 'postgres' );

// Postgres is the strictest of the three about type comparisons, and the only
// one that aborts a whole transaction after a failed statement — which is the
// behaviour NotificationLog::logSend()'s savepoint exists to survive.
defineEngineSensitiveModelTests();
