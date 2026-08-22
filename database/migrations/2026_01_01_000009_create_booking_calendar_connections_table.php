<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Booking Calendar Connections Table Migration
 *
 * One row per external calendar a provider has connected. A provider can have
 * several — a work Google calendar and a personal iCal feed, say — which is
 * why this is a table rather than a column on `service_providers`.
 *
 * `sync_mode` defaults to `outbound`. Two-way sync lets an external calendar
 * suppress availability, which is a lot of power to hand to a third party by
 * default, so it is opted into per connection.
 *
 * `oauth_connection_id` points at a sibling OAuth package and carries no
 * foreign key: the driver packages are suggested, not required, so the table
 * they own may not exist.
 *
 * @since 1.0.0
 */
return new class extends Migration {
    /**
     * Runs the migration.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create( 'booking_calendar_connections', function ( Blueprint $table ): void {
            $table->id();
            $table->unsignedBigInteger( 'site_id' )->nullable();
            $table->foreignId( 'provider_id' )->constrained( 'service_providers' )->cascadeOnDelete();
            $table->string( 'driver', 32 );
            $table->string( 'external_calendar_id' );
            $table->unsignedBigInteger( 'oauth_connection_id' )->nullable();
            $table->enum( 'sync_mode', [ 'off', 'outbound', 'two_way' ] )->default( 'outbound' );
            $table->text( 'sync_token' )->nullable();
            $table->timestamp( 'last_sync_at' )->nullable();
            $table->text( 'last_sync_error' )->nullable();
            $table->unsignedInteger( 'consecutive_failure_count' )->default( 0 );
            $table->timestamp( 'disabled_at' )->nullable();
            $table->boolean( 'is_active' )->default( true );
            $table->timestamps();

            // The same calendar must not be connected to the same provider
            // twice: outbound sync would then write every booking to it twice.
            $table->unique(
                [ 'provider_id', 'driver', 'external_calendar_id' ],
                'calendar_connections_provider_calendar_unique',
            );
            $table->index( 'site_id' );
            $table->index( [ 'is_active', 'sync_mode' ] );
        } );
    }

    /**
     * Reverses the migration.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'booking_calendar_connections' );
    }
};
