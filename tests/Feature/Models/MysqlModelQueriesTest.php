<?php

declare( strict_types=1 );

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithMysql;

uses( TestsWithMysql::class, RefreshDatabase::class )->group( 'mysql' );

// MySQL has native DATE, TIME, JSON, and ENUM types, so a comparison or a cast
// that only ever met sqlite's text columns gets held to a typed server here.
defineEngineSensitiveModelTests();
