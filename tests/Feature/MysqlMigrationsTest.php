<?php

declare( strict_types=1 );

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsBookingSchema;
use Tests\Concerns\TestsWithMysql;

uses( TestsWithMysql::class, AssertsBookingSchema::class, RefreshDatabase::class )->group( 'mysql' );

// MySQL has no partial indexes, so the slot guard is emulated with a generated
// column. That is a different mechanism reaching for the same rule, which is
// exactly the kind of thing that works until it quietly does not — so it gets
// held to the same assertions as the engines that index a WHERE clause.
defineBookingMigrationTests();
