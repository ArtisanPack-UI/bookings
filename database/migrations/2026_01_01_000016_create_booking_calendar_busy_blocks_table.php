<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Booking Calendar Busy Blocks Table Migration
 *
 * The inbound half of two-way calendar sync: external events, projected down
 * to the only thing availability cares about, which is a span of time the
 * provider is not free.
 *
 * Nothing about the external event is stored beyond its identity, its bounds,
 * and its etag. A busy block is a fact about time, not a copy of somebody's
 * private calendar entry — pulling in titles and attendees would put third
 * parties' data in this database for no benefit availability could use.
 *
 * Times are UTC, matching bookings, so the availability query can compare them
 * without converting anything.
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
        Schema::create( 'booking_calendar_busy_blocks', function ( Blueprint $table ): void {
            $table->id();
            $table->foreignId( 'connection_id' )
                ->constrained( 'booking_calendar_connections' )
                ->cascadeOnDelete();
            $table->string( 'external_event_id' );
            $table->dateTime( 'starts_at_utc' );
            $table->dateTime( 'ends_at_utc' );
            $table->string( 'etag' )->nullable();
            $table->timestamps();

            // Incremental sync replays events it has already sent, so ingestion
            // upserts on this key rather than accumulating duplicate blocks.
            $table->unique( [ 'connection_id', 'external_event_id' ], 'busy_blocks_connection_event_unique' );

            // Availability asks for overlaps within a window on one connection.
            $table->index( [ 'connection_id', 'starts_at_utc', 'ends_at_utc' ], 'busy_blocks_connection_range_index' );
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
        Schema::dropIfExists( 'booking_calendar_busy_blocks' );
    }
};
