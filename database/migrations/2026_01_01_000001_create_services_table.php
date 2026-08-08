<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create Services Table Migration
 *
 * A service is the thing a customer books: a name, a duration, and the rules
 * that govern how it is scheduled. Everything else in the package hangs off
 * this table, so it migrates first.
 *
 * `default_provider_id` deliberately carries no foreign key. Providers are
 * created by the next-but-one migration, and adding the constraint afterwards
 * would mean an ALTER TABLE that SQLite cannot express without rebuilding the
 * table. The column is indexed and the relationship is enforced in the domain
 * layer instead.
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
        Schema::create( 'services', function ( Blueprint $table ): void {
            $table->id();
            $table->unsignedBigInteger( 'site_id' )->nullable();
            $table->string( 'name' );
            $table->string( 'slug' );
            $table->text( 'description' )->nullable();
            $table->unsignedInteger( 'duration' );
            $table->unsignedInteger( 'buffer_before' )->default( 0 );
            $table->unsignedInteger( 'buffer_after' )->default( 0 );
            $table->decimal( 'price', 10, 2 )->nullable();
            $table->boolean( 'is_free' )->default( false );
            $table->unsignedInteger( 'max_bookings_per_slot' )->default( 1 );
            $table->boolean( 'is_active' )->default( true );
            $table->json( 'intake_schema' )->nullable();
            $table->unsignedInteger( 'intake_schema_version' )->default( 1 );
            $table->enum( 'assignment_strategy', [ 'any', 'round_robin', 'default_provider' ] )->default( 'any' );
            $table->unsignedBigInteger( 'default_provider_id' )->nullable();
            $table->unsignedBigInteger( 'image_media_id' )->nullable();
            $table->string( 'image_url', 500 )->nullable();
            $table->string( 'color', 7 )->nullable();
            $table->string( 'timezone', 64 )->nullable();
            $table->json( 'metadata' )->nullable();
            $table->timestamp( 'pii_erased_at' )->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A slug is unique to the site that owns it, not globally: two
            // tenants both wanting "consultation" is the normal case.
            $table->unique( [ 'site_id', 'slug' ] );
            $table->index( 'site_id' );
            $table->index( [ 'site_id', 'is_active' ] );
            $table->index( 'default_provider_id' );
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
        Schema::dropIfExists( 'services' );
    }

    /**
     * Makes the slug unique among services belonging to no site.
     *
     * `UNIQUE(site_id, slug)` says what it means only while a site is
     * resolving. Nulls are distinct in a unique index on every supported
     * engine, so on a single-tenant installation — where `site_id` is null on
     * every row, and which is the default configuration — that index enforces
     * nothing at all. The one case the constraint most needs to cover is the
     * one it silently drops.
     *
     * This closes it with a second unique index over the rows the first one
     * cannot see. Postgres and SQLite index the predicate directly; MySQL has
     * no partial indexes, so the same rule is emulated with a generated column
     * that carries the slug only while the row belongs to no site.
     *
     * Soft-deleted rows stay in both indexes deliberately. Freeing a trashed
     * service's slug would let a new service take it and then collide the
     * moment somebody restored the old one, which is a worse failure than
     * being told up front that the slug is taken.
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
                'ALTER TABLE `services`
                    ADD COLUMN `unscoped_slug` VARCHAR(255)
                    GENERATED ALWAYS AS (
                        CASE WHEN `site_id` IS NULL THEN `slug` END
                    ) VIRTUAL,
                    ADD UNIQUE INDEX `services_unscoped_slug_unique` (`unscoped_slug`)',
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX "services_unscoped_slug_unique"
                ON "services" ("slug")
                WHERE "site_id" IS NULL',
        );
    }
};
