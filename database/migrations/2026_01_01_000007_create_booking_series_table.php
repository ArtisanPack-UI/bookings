<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Booking Series Table Migration
 *
 * The rule a set of recurring bookings was generated from. The individual
 * occurrences live in `bookings`; this row is what they were derived from.
 *
 * `rrule` holds a canonical RFC 5545 recurrence rule and `dtstart_local` plus
 * `dtstart_timezone` hold a floating start. Materialisation into UTC occurrence
 * rows is a pure function of those three columns, which is what makes "this and
 * all following" edits re-derivable rather than approximated: the source of
 * truth is the rule, not the rows it produced.
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
        Schema::create( 'booking_series', function ( Blueprint $table ): void {
            $table->id();
            $table->unsignedBigInteger( 'site_id' )->nullable();
            // Restricted for the same reason as `bookings`: a series carries
            // customer data, and a hard delete of the service or provider it
            // was booked against must not silently take it.
            $table->foreignId( 'service_id' )->constrained( 'services' )->restrictOnDelete();
            $table->foreignId( 'provider_id' )->nullable()->constrained( 'service_providers' )->restrictOnDelete();
            $table->string( 'customer_name' );
            $table->string( 'customer_email' );
            $table->string( 'customer_phone', 50 )->nullable();
            $table->text( 'rrule' );
            $table->dateTime( 'dtstart_local' );
            $table->string( 'dtstart_timezone', 64 );
            $table->dateTime( 'until_local' )->nullable();
            $table->unsignedInteger( 'occurrence_count' )->nullable();
            $table->unsignedInteger( 'intake_schema_version' );
            $table->timestamp( 'cancelled_at' )->nullable();
            $table->json( 'metadata' )->nullable();
            $table->timestamp( 'pii_erased_at' )->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index( 'site_id' );
            $table->index( [ 'site_id', 'service_id' ] );
            $table->index( 'provider_id' );
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
        Schema::dropIfExists( 'booking_series' );
    }
};
