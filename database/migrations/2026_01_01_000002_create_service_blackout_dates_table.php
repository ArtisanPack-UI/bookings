<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Service Blackout Dates Table Migration
 *
 * Service-level closures: a date range during which a service cannot be
 * booked at all, regardless of who is available. A null `service_id` closes
 * every service on the site, which is how a holiday or a whole-business
 * shutdown is expressed.
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
        Schema::create( 'service_blackout_dates', function ( Blueprint $table ): void {
            $table->id();
            $table->unsignedBigInteger( 'site_id' )->nullable();
            $table->foreignId( 'service_id' )->nullable()->constrained( 'services' )->cascadeOnDelete();
            $table->date( 'starts_on' );
            $table->date( 'ends_on' );
            $table->string( 'reason' )->nullable();
            $table->timestamps();

            $table->index( 'site_id' );
            $table->index( [ 'site_id', 'service_id', 'starts_on', 'ends_on' ], 'blackout_site_service_range_index' );
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
        Schema::dropIfExists( 'service_blackout_dates' );
    }
};
