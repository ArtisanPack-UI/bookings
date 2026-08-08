<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create Bookings Table Migration
 *
 * The central table: one row per appointment. `start_time` and `end_time` are
 * points in time and are stored in UTC; `customer_timezone` records the zone
 * the customer was in when they booked, so the same instant can be rendered
 * back to them the way they chose it.
 *
 * **Erasure contract.** `customer_name` and `customer_email` stay NOT NULL, and
 * erasure overwrites them with a redaction placeholder rather than nulling
 * them. A booking has a customer — that is not a fact the schema should stop
 * asserting in order to make a later routine's job easier, and nullable columns
 * would let an ordinary bug write a booking with no customer at all and no
 * complaint from the database. `pii_erased_at` is what distinguishes an erased
 * row from a real one; it exists precisely so the placeholder does not have to.
 * The erasure routine must set it in the same write that redacts the fields.
 *
 * The manage token is stored only as a SHA-256 hash. The plain token exists in
 * the confirmation email and the widget URL and nowhere else, so a leaked
 * database row does not hand an attacker the ability to cancel someone's
 * booking.
 *
 * The interesting part of this migration is the partial unique index — see
 * `addActiveSlotGuard()`.
 *
 * @since 1.0.0
 */
return new class extends Migration {
    /**
     * The booking statuses that occupy a provider's slot.
     *
     * A cancelled or completed booking does not block the slot, so the race
     * guard has to exclude them rather than being unique on every status.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    private const ACTIVE_STATUSES = [ 'requested', 'confirmed' ];

    /**
     * Runs the migration.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create( 'bookings', function ( Blueprint $table ): void {
            $table->id();
            $table->unsignedBigInteger( 'site_id' )->nullable();
            $table->string( 'booking_number', 50 )->unique();
            // Restrict, not cascade. Services and providers are soft deletable,
            // so a hard delete is an explicit destructive act — and letting one
            // silently take every booking ever made against it with it is the
            // worst available outcome. Retention pruning removes the bookings
            // first, and then the parent goes.
            $table->foreignId( 'service_id' )->constrained( 'services' )->restrictOnDelete();
            $table->foreignId( 'provider_id' )->nullable()->constrained( 'service_providers' )->restrictOnDelete();

            // The series is the exception: deleting the rule that generated a
            // set of occurrences detaches them rather than destroying them.
            $table->foreignId( 'series_id' )->nullable()->constrained( 'booking_series' )->nullOnDelete();
            $table->unsignedInteger( 'series_index' )->nullable();
            $table->timestamp( 'detached_from_series_at' )->nullable();
            $table->string( 'customer_name' );
            $table->string( 'customer_email' );
            $table->string( 'customer_phone', 50 )->nullable();
            $table->string( 'customer_timezone', 64 );
            $table->dateTime( 'start_time' );
            $table->dateTime( 'end_time' );
            $table->enum( 'status', [ 'requested', 'confirmed', 'cancelled', 'completed', 'no_show' ] )
                ->default( 'requested' );
            $table->enum(
                'assignment_strategy',
                [ 'customer', 'round_robin', 'admin', 'api', 'default_provider' ],
            )->default( 'customer' );
            $table->unsignedInteger( 'intake_schema_version' );
            $table->json( 'intake_data' )->nullable();
            $table->text( 'notes' )->nullable();
            $table->char( 'manage_token_hash', 64 )->unique();
            $table->timestamp( 'pii_erased_at' )->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index( 'site_id' );
            $table->index( [ 'provider_id', 'start_time' ] );
            $table->index( [ 'site_id', 'status', 'start_time' ] );
            $table->index( [ 'series_id', 'series_index' ] );
        } );

        $this->addActiveSlotGuard();
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
        Schema::dropIfExists( 'bookings' );
    }

    /**
     * Adds the database-level guard against double-booking a provider.
     *
     * Two customers taking the last slot at the same instant is a real race,
     * and the application-level advisory lock that normally prevents it is only
     * as good as the process holding it. This index is the backstop: if the
     * lock is lost, bypassed, or the write comes from somewhere that never took
     * it, the second insert fails and the caller retries with another provider.
     *
     * It has to be a *partial* unique index. A plain unique on
     * `(provider_id, start_time)` would also stop a customer rebooking a slot
     * they had previously cancelled, which is not a race — it is the system
     * working.
     *
     * Postgres and SQLite both index a WHERE clause directly. MySQL has no
     * partial indexes, so the same rule is emulated with a generated column
     * that evaluates to a slot key while the booking is active and to NULL
     * otherwise: MySQL treats NULLs as distinct in a unique index, so inactive
     * rows stop competing for the slot for exactly the same reason the WHERE
     * clause excludes them elsewhere.
     *
     * Bookings with no provider are excluded on every engine — NULL columns
     * fall out of a unique index in Postgres and SQLite, and CONCAT() with a
     * NULL yields NULL in MySQL — which is correct, because an unassigned
     * booking is not holding anybody's slot.
     *
     * Soft-deleted rows are excluded for the same reason, and this is the one
     * place the guard goes beyond plan §5.8. A soft-deleted booking is
     * invisible to every domain query, so leaving it in the index would block a
     * slot that availability reports as free — producing a unique violation
     * that no retry can ever resolve, because no candidate provider is the
     * problem. The index has to agree with what the model can see.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function addActiveSlotGuard(): void
    {
        $driver   = Schema::getConnection()->getDriverName();
        $statuses = "'" . implode( "', '", self::ACTIVE_STATUSES ) . "'";

        if ( in_array( $driver, [ 'mysql', 'mariadb' ], true ) ) {
            DB::statement( sprintf(
                'ALTER TABLE `bookings`
                    ADD COLUMN `active_slot_key` VARCHAR(64)
                    GENERATED ALWAYS AS (
                        CASE WHEN `status` IN (%s) AND `deleted_at` IS NULL
                            THEN CONCAT(`provider_id`, \':\', `start_time`)
                        END
                    ) VIRTUAL,
                    ADD UNIQUE INDEX `bookings_active_slot_unique` (`active_slot_key`)',
                $statuses,
            ) );

            return;
        }

        DB::statement( sprintf(
            'CREATE UNIQUE INDEX "bookings_active_slot_unique"
                ON "bookings" ("provider_id", "start_time")
                WHERE "status" IN (%s) AND "deleted_at" IS NULL',
            $statuses,
        ) );
    }
};
