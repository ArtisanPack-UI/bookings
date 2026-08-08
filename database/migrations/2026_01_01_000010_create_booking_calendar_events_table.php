<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Booking Calendar Events Table Migration
 *
 * The outbound sync ledger: which booking became which event on which
 * connected calendar. Without it there is no way to update or delete the
 * external copy when the booking changes.
 *
 * `etag` is the external system's version marker, kept so an update can be
 * sent conditionally and a concurrent edit made in the calendar itself is not
 * silently overwritten.
 *
 * Rows are pruned some days after the parent booking is cancelled or deleted,
 * per `artisanpack.bookings.retention.calendar_events_ttl_days`.
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
        Schema::create( 'booking_calendar_events', function ( Blueprint $table ): void {
            $table->id();
            $table->foreignId( 'booking_id' )->constrained( 'bookings' )->cascadeOnDelete();
            $table->foreignId( 'connection_id' )
                ->constrained( 'booking_calendar_connections' )
                ->cascadeOnDelete();
            $table->string( 'external_event_id' );
            $table->string( 'etag' )->nullable();
            $table->timestamp( 'last_synced_at' )->nullable();
            $table->text( 'sync_error' )->nullable();
            $table->timestamps();

            // One event per booking per connection. A retried sync job has to
            // update the row it wrote last time rather than adding a second
            // event nobody will ever clean up.
            $table->unique( [ 'booking_id', 'connection_id' ] );
            $table->index( [ 'connection_id', 'external_event_id' ] );
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
        Schema::dropIfExists( 'booking_calendar_events' );
    }
};
