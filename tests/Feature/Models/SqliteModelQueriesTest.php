<?php

declare( strict_types=1 );

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

// sqlite stores dates, times, and JSON as text and compares them
// lexicographically, which is the engine most likely to disagree with a
// comparison written against a real DATE column — and the one every other test
// in tests/Feature/Models already runs on.
defineEngineSensitiveModelTests();

defineEngineSensitiveAvailabilityTests();
