<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Booking Notification Log Table Migration
 *
 * A record of every notification sent about a booking, and the mechanism that
 * keeps the reminder cron idempotent.
 *
 * The unique key on `(booking_id, type, channel, scheduled_for)` is doing real
 * work: it makes "send the 24-hour reminder for this booking, by mail" a claim
 * on a row rather than a decision made by reading. A cron that overlaps itself,
 * a queue that delivers a job twice, a retry after a timeout — all of them race
 * to insert the same key, exactly one wins, and the losers fail cleanly instead
 * of sending a customer the same reminder twice.
 *
 * `channel` is part of the key rather than merely recorded, because the package
 * sends the same message over several channels at once — a confirmation goes to
 * the customer by mail and to staff as a database notification. Keyed without
 * it, the first channel to claim would lock the others out and the admin
 * notification would silently never arrive.
 *
 * `scheduled_for` is NULL for notifications that are not scheduled — a
 * confirmation, a cancellation. NULLs are distinct in a unique index on every
 * supported engine, so those are not deduplicated, which is right: two
 * reschedules genuinely warrant two emails.
 *
 * **Erasure contract.** `recipient` holds an email address or a phone number for
 * customer-facing channels, so this table carries customer PII without an
 * erasure marker of its own. It does not need one: every row is keyed to a
 * booking, so erasing a booking means redacting `recipient` on `booking_id = ?`
 * in the same routine that redacts the booking. Deletion is already covered —
 * the foreign key cascades, so retention pruning takes the log with the booking
 * — and `artisanpack.bookings.retention.notification_log_days` bounds the rest
 * at 90 days. The erasure command in plan §9.5 has to include this table
 * explicitly; a routine that only walks the four tables with `pii_erased_at` on
 * them leaves the customer's address sitting here in readable form.
 *
 * **`error` holds whatever the channel threw**, and a transport's failure
 * message routinely quotes the address it could not reach — an SMTP rejection
 * reads `550 5.1.1 <sam@example.test>: Recipient address rejected`. So this
 * column is a second place customer contact details land, arriving only on the
 * paths nobody tests. The erasure routine has to redact `error` alongside
 * `recipient` for the booking; redacting `recipient` alone leaves the address
 * sitting in the column next to it.
 *
 * Staff-facing channels are deliberately outside that: the database channel
 * records an internal notifiable reference such as `App\Models\User:12` rather
 * than a staff address, so an erasure sweep scoped to a booking does not blank
 * the record of who was told about it, and there is no staff address here to
 * leak in the first place.
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
        Schema::create( 'booking_notification_log', function ( Blueprint $table ): void {
            $table->id();
            $table->foreignId( 'booking_id' )->constrained( 'bookings' )->cascadeOnDelete();
            $table->string( 'channel', 32 );
            $table->enum(
                'type',
                [ 'confirmation', 'reminder', 'cancellation', 'reschedule', 'no_show' ],
            );
            $table->string( 'recipient' );
            $table->timestamp( 'scheduled_for' )->nullable();
            $table->enum( 'status', [ 'pending', 'sent', 'failed' ] )->default( 'pending' );
            $table->text( 'error' )->nullable();
            $table->timestamp( 'sent_at' )->nullable();
            $table->timestamps();

            $table->unique(
                [ 'booking_id', 'type', 'channel', 'scheduled_for' ],
                'notification_log_booking_type_channel_schedule_unique',
            );
            $table->index( [ 'status', 'scheduled_for' ] );
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
        Schema::dropIfExists( 'booking_notification_log' );
    }
};
