<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add Provider Notification Types To Booking Notification Log Migration
 *
 * Widens the `type` column of `booking_notification_log` to accept the two
 * provider-facing notices a reassignment raises — `provider_assigned` and
 * `provider_unassigned`. The create migration carries the full value set for a
 * fresh database; this brings a database that was already migrated from an
 * earlier revision up to it, so `NotificationLog::logSend()` does not reject the
 * new types on an existing install.
 *
 * MySQL enforces the set as a native `ENUM` and PostgreSQL as a `CHECK`
 * constraint, so each is rewritten in its own dialect through a raw statement —
 * `enum(...)->change()` is avoided because it needs `doctrine/dbal` on the
 * Laravel 10 releases the package still supports. SQLite is left alone: it backs
 * the test suite and local development, both of which migrate from scratch and
 * so already have the full set from the create migration, and rebuilding a
 * SQLite table to alter a `CHECK` buys nothing where no long-lived install
 * exists to upgrade.
 *
 * @since 1.0.0
 */
return new class extends Migration {
    /**
     * The value set including the provider notices.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    private array $withProviderTypes = [
        'confirmation',
        'reminder',
        'cancellation',
        'reschedule',
        'no_show',
        'provider_assigned',
        'provider_unassigned',
    ];

    /**
     * The value set as it stood before the provider notices.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    private array $withoutProviderTypes = [
        'confirmation',
        'reminder',
        'cancellation',
        'reschedule',
        'no_show',
    ];

    /**
     * Runs the migration.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function up(): void
    {
        $this->setTypeValues( $this->withProviderTypes );
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
        $this->setTypeValues( $this->withoutProviderTypes );
    }

    /**
     * Rewrites the `type` column to accept exactly the given values.
     *
     * @since 1.0.0
     *
     * @param  list<string>  $values  The values the column should accept.
     *
     * @return void
     */
    private function setTypeValues( array $values ): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $list   = implode( ',', array_map(
            static fn ( string $value ): string => "'" . $value . "'",
            $values,
        ) );

        if ( 'mysql' === $driver ) {
            DB::statement( sprintf(
                'ALTER TABLE `booking_notification_log` MODIFY `type` ENUM(%s) NOT NULL',
                $list,
            ) );

            return;
        }

        if ( 'pgsql' === $driver ) {
            DB::statement( 'ALTER TABLE booking_notification_log DROP CONSTRAINT IF EXISTS booking_notification_log_type_check' );
            DB::statement( sprintf(
                'ALTER TABLE booking_notification_log ADD CONSTRAINT booking_notification_log_type_check CHECK (type::text IN (%s))',
                $list,
            ) );
        }
    }
};
