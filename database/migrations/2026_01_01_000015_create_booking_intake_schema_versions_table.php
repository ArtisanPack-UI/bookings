<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Booking Intake Schema Versions Table Migration
 *
 * The history of a service's intake form. Every edit appends a version rather
 * than replacing the current one, and bookings record the version they were
 * captured against.
 *
 * That is what makes an old booking still readable. Rendering last year's
 * answers against this year's form gives you fields nobody was asked and
 * answers with nowhere to go; rendering them against the form that was
 * actually on screen gives you what the customer actually said.
 *
 * The table has no `site_id`: a version belongs to a service, and the service
 * already belongs to a site.
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
        Schema::create( 'booking_intake_schema_versions', function ( Blueprint $table ): void {
            $table->id();
            $table->foreignId( 'service_id' )->constrained( 'services' )->cascadeOnDelete();
            $table->unsignedInteger( 'version' );
            $table->json( 'schema' );
            $table->timestamps();

            // Versions are the thing bookings point at, so a service must never
            // end up with two rows claiming to be the same one.
            $table->unique( [ 'service_id', 'version' ] );
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
        Schema::dropIfExists( 'booking_intake_schema_versions' );
    }
};
