<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create Service Providers Table Migration
 *
 * The person (or resource) a booking is assigned to. Providers do not have to
 * be application users — `user_id` is nullable and carries no foreign key,
 * because a package cannot assume the host application's users table exists or
 * what its key type is.
 *
 * `timezone` is an IANA name and is not nullable: availability is authored as
 * wall-clock time against it, so a provider without a zone has schedules that
 * mean nothing.
 *
 * The spec's `google_calendar_id` is deliberately absent — calendar identity
 * moved to `booking_calendar_connections`, which supports more than one
 * calendar and more than one vendor per provider.
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
        Schema::create( 'service_providers', function ( Blueprint $table ): void {
            $table->id();
            $table->unsignedBigInteger( 'site_id' )->nullable();
            $table->unsignedBigInteger( 'user_id' )->nullable();
            $table->string( 'name' );
            $table->string( 'slug' );
            $table->string( 'email' )->nullable();
            $table->string( 'phone', 50 )->nullable();
            $table->text( 'bio' )->nullable();
            $table->string( 'timezone', 64 )->default( 'UTC' );
            $table->unsignedBigInteger( 'image_media_id' )->nullable();
            $table->string( 'image_url', 500 )->nullable();
            $table->integer( 'round_robin_weight' )->default( 1 );
            $table->timestamp( 'round_robin_last_assigned_at' )->nullable();
            $table->integer( 'sort_order' )->default( 0 );
            $table->boolean( 'is_active' )->default( true );
            $table->json( 'metadata' )->nullable();

            // The provider's calendar feed credential, hashed the way
            // bookings.manage_token_hash is. Nullable because a provider has no
            // feed until somebody asks for one — and because only the hash is
            // stored, minting on create would throw the plain token away
            // unread, leaving a credential nobody can ever use.
            $table->char( 'ical_token_hash', 64 )->nullable()->unique();
            $table->timestamp( 'pii_erased_at' )->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique( [ 'site_id', 'slug' ] );
            $table->index( 'site_id' );
            $table->index( 'user_id' );
            $table->index( [ 'site_id', 'is_active' ] );

            // The round-robin cursor: the selection query orders by this column
            // to find the least recently assigned candidate.
            $table->index( 'round_robin_last_assigned_at' );
        } );

        $this->addUnscopedSlugGuard();
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
        Schema::dropIfExists( 'service_providers' );
    }

    /**
     * Makes the slug unique among providers belonging to no site.
     *
     * The counterpart to the guard on `services`, and for the same reason:
     * `UNIQUE(site_id, slug)` enforces nothing while `site_id` is null, which
     * is every row on a single-tenant installation. See the services migration
     * for the full reasoning.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function addUnscopedSlugGuard(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ( in_array( $driver, [ 'mysql', 'mariadb' ], true ) ) {
            DB::statement(
                'ALTER TABLE `service_providers`
                    ADD COLUMN `unscoped_slug` VARCHAR(255)
                    GENERATED ALWAYS AS (
                        CASE WHEN `site_id` IS NULL THEN `slug` END
                    ) VIRTUAL,
                    ADD UNIQUE INDEX `service_providers_unscoped_slug_unique` (`unscoped_slug`)',
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX "service_providers_unscoped_slug_unique"
                ON "service_providers" ("slug")
                WHERE "site_id" IS NULL',
        );
    }
};
