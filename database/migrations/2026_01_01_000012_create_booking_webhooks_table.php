<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Booking Webhooks Table Migration
 *
 * Outbound webhook endpoints. `events` holds the list of booking events the
 * endpoint has subscribed to, and `secret` is the key its payload signatures
 * are computed with.
 *
 * An endpoint that fails `artisanpack.bookings.webhooks.failure_threshold`
 * times in a row is disabled rather than retried forever — a dead endpoint
 * should not keep a queue busy indefinitely.
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
        Schema::create( 'booking_webhooks', function ( Blueprint $table ): void {
            $table->id();
            $table->unsignedBigInteger( 'site_id' )->nullable();
            $table->string( 'name' );
            $table->string( 'url', 500 );
            $table->text( 'secret' );
            $table->json( 'events' );
            $table->boolean( 'is_active' )->default( true );
            $table->unsignedInteger( 'consecutive_failure_count' )->default( 0 );
            $table->timestamp( 'disabled_at' )->nullable();
            $table->timestamp( 'last_success_at' )->nullable();
            $table->timestamps();

            $table->index( 'site_id' );
            $table->index( [ 'site_id', 'is_active' ] );
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
        Schema::dropIfExists( 'booking_webhooks' );
    }
};
