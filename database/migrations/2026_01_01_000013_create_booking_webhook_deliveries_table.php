<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Booking Webhook Deliveries Table Migration
 *
 * One row per delivery attempt, kept so a failed webhook can be retried on a
 * backoff schedule and so an operator can see what was actually sent when a
 * consumer claims it never arrived.
 *
 * `dead` is a terminal status distinct from `failed`: it means the retry budget
 * is spent and nothing further will be attempted.
 *
 * Rows are pruned after `artisanpack.bookings.webhooks.delivery_retention_days`,
 * which defaults to 30.
 *
 * **Erasure contract.** `payload` is a copy of the event body, so it contains
 * the customer's name and email. Redacting inside stored JSON is guesswork, so
 * this table is retention-bound rather than erasure-bound: the payload is
 * deleted, not rewritten. Two things follow, and both are obligations on the
 * pruning ticket rather than on this migration. The prune command must actually
 * be scheduled — an unrun prune turns the retention window into a promise
 * nobody keeps — and erasing a booking must delete that booking's undelivered
 * and delivered payloads immediately rather than waiting out the window.
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
        Schema::create( 'booking_webhook_deliveries', function ( Blueprint $table ): void {
            $table->id();
            $table->foreignId( 'webhook_id' )->constrained( 'booking_webhooks' )->cascadeOnDelete();
            $table->string( 'event_type' );
            $table->json( 'payload' );
            $table->unsignedInteger( 'attempt_number' )->default( 1 );
            $table->enum( 'status', [ 'pending', 'success', 'failed', 'dead' ] )->default( 'pending' );
            $table->unsignedSmallInteger( 'response_status' )->nullable();
            $table->text( 'response_body' )->nullable();
            $table->timestamp( 'next_attempt_at' )->nullable();
            $table->timestamp( 'attempted_at' )->nullable();
            $table->timestamp( 'succeeded_at' )->nullable();
            $table->timestamps();

            $table->index( [ 'webhook_id', 'status' ] );

            // The retry sweep asks one question — what is due now — so the
            // status leads the index and the due time follows it.
            $table->index( [ 'status', 'next_attempt_at' ] );
            $table->index( 'created_at' );
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
        Schema::dropIfExists( 'booking_webhook_deliveries' );
    }
};
