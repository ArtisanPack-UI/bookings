<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Availability Schedules Table Migration
 *
 * A provider's recurring weekly hours. `day_of_week` is Sunday-indexed (0–6)
 * to match Carbon's `dayOfWeek`.
 *
 * The times are local wall-clock in the provider's own timezone and are never
 * stored as UTC. That is what lets a row survive a daylight-saving changeover:
 * "09:00 local, whatever the offset happens to be that day" keeps meaning the
 * same thing, where a UTC-normalised 13:00 would silently become 08:00 local
 * every spring.
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
        Schema::create( 'availability_schedules', function ( Blueprint $table ): void {
            $table->id();
            $table->foreignId( 'provider_id' )->constrained( 'service_providers' )->cascadeOnDelete();
            $table->unsignedTinyInteger( 'day_of_week' );
            $table->time( 'start_time_local' );
            $table->time( 'end_time_local' );
            $table->date( 'effective_from' )->nullable();
            $table->date( 'effective_until' )->nullable();
            $table->boolean( 'is_available' )->default( true );
            $table->timestamps();

            $table->index( [ 'provider_id', 'day_of_week' ] );
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
        Schema::dropIfExists( 'availability_schedules' );
    }
};
