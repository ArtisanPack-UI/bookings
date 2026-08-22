<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

it( 'clears provider-type rows before narrowing the notification-log enum on down', function (): void {
    // The down migration narrows the `type` set back to the pre-provider values.
    // On PostgreSQL the new CHECK refuses to add while a `provider_assigned` row
    // still violates it, and on MySQL the ENUM MODIFY coerces such a row to an
    // empty string — so the rows must be deleted first. SQLite has no such
    // constraint, but the delete runs on every driver and is what this asserts.
    $booking = Booking::factory()->create();

    NotificationLog::query()->create( [
        'booking_id' => $booking->getKey(),
        'type'       => 'provider_assigned',
        'channel'    => 'database',
        'status'     => 'sent',
        'recipient'  => 'App\\Models\\User:1',
    ] );

    $kept = NotificationLog::query()->create( [
        'booking_id' => $booking->getKey(),
        'type'       => 'confirmation',
        'channel'    => 'mail',
        'status'     => 'sent',
        'recipient'  => 'customer@example.test',
    ] );

    $migration = require dirname( __DIR__, 3 )
        . '/database/migrations/2026_08_18_000000_add_provider_types_to_booking_notification_log.php';

    $migration->down();

    expect( DB::table( 'booking_notification_log' )->where( 'type', 'provider_assigned' )->count() )->toBe( 0 )
        ->and( NotificationLog::query()->whereKey( $kept->getKey() )->exists() )->toBeTrue();
} );
