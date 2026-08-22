<?php

declare( strict_types=1 );

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsBookingSchema;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, AssertsBookingSchema::class, RefreshDatabase::class );

defineBookingMigrationTests();
