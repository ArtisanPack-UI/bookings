<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Availability Overrides Table Migration
 *
 * Single-date exceptions to a provider's weekly schedule: a day off, or a day
 * worked at hours other than the usual ones. Same wall-clock semantics as
 * `availability_schedules` — the times are local to the provider's timezone.
 *
 * The spec's `is_available` boolean is replaced by an explicit `type`, because
 * the boolean had to carry two meanings at once ("blocked" versus "these hours
 * instead") and reading a schema should not require knowing which.
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
        Schema::create( 'availability_overrides', function ( Blueprint $table ): void {
            $table->id();
            $table->foreignId( 'provider_id' )->constrained( 'service_providers' )->cascadeOnDelete();
            $table->date( 'date' );
            $table->enum( 'type', [ 'unavailable', 'custom_hours' ] );
            $table->time( 'start_time_local' )->nullable();
            $table->time( 'end_time_local' )->nullable();
            $table->string( 'reason' )->nullable();
            $table->timestamps();

            $table->index( [ 'provider_id', 'date' ] );
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
        Schema::dropIfExists( 'availability_overrides' );
    }
};
