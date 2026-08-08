<?php

declare( strict_types=1 );

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsBookingSchema;
use Tests\Concerns\TestsWithPostgres;

uses( TestsWithPostgres::class, AssertsBookingSchema::class, RefreshDatabase::class )->group( 'postgres' );

defineBookingMigrationTests();
