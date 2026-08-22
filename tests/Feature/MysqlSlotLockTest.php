<?php

declare( strict_types=1 );

use Tests\Concerns\TestsWithMysql;

uses( TestsWithMysql::class )->group( 'mysql' );

// MySQL has only session-scoped GET_LOCK, so the release is explicit and lives
// in a finally rather than being handed back by the server at commit. That is
// the half a leak hides in, and it is why these run here as well as on Postgres.
defineSlotLockTests();
