<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Service Provider Service Table Migration
 *
 * The pivot saying which providers offer which services, plus the per-pairing
 * overrides — a senior provider charging more for the same service, or taking
 * longer over it.
 *
 * The table carries no `site_id`. Both sides of the pivot are already scoped
 * to a site, so a row here cannot span two of them; adding a third copy of the
 * same fact would only create somewhere for it to disagree.
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
        Schema::create( 'service_provider_service', function ( Blueprint $table ): void {
            $table->id();
            $table->foreignId( 'service_id' )->constrained( 'services' )->cascadeOnDelete();
            $table->foreignId( 'provider_id' )->constrained( 'service_providers' )->cascadeOnDelete();
            $table->decimal( 'custom_price', 10, 2 )->nullable();
            $table->unsignedInteger( 'custom_duration' )->nullable();
            $table->timestamps();

            $table->unique( [ 'service_id', 'provider_id' ] );
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
        Schema::dropIfExists( 'service_provider_service' );
    }
};
