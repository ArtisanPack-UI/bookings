<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Booking Calendar Watch Channels Table Migration
 *
 * Push notification registrations for two-way sync — a Google watch channel or
 * a Microsoft 365 subscription. Both expire, which is why `expires_at` is
 * indexed: the `bookings:calendar-watch-renew` command sweeps this column and
 * renews anything about to lapse.
 *
 * Apple has no push mechanism, so an Apple connection has no row here and is
 * polled instead.
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
        Schema::create( 'booking_calendar_watch_channels', function ( Blueprint $table ): void {
            $table->id();
            $table->foreignId( 'connection_id' )
                ->constrained( 'booking_calendar_connections' )
                ->cascadeOnDelete();
            $table->string( 'channel_id', 64 )->nullable();
            $table->string( 'resource_id' )->nullable();
            $table->string( 'subscription_id' )->nullable();
            $table->timestamp( 'expires_at' );
            $table->timestamps();

            // The incoming webhook identifies itself by channel or subscription
            // id and nothing else, so both have to be findable on their own.
            $table->unique( 'channel_id' );
            $table->unique( 'subscription_id' );
            $table->index( 'connection_id' );
            $table->index( 'expires_at' );
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
        Schema::dropIfExists( 'booking_calendar_watch_channels' );
    }
};
