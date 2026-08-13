<?php

/**
 * Booking schema assertions.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asserts the initial migration set produced the schema the plan describes.
 *
 * The assertions live in a trait because they have to be made against three
 * database engines, and the whole point of the exercise is that the answer is
 * the same on all of them. Each engine's test file uses this alongside the
 * concern that points it at a server, so a divergence shows up as one engine's
 * file failing rather than as a gap nobody noticed.
 *
 * @since 1.0.0
 */
trait AssertsBookingSchema
{
    /**
     * Every table the initial migration set is responsible for.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    private const EXPECTED_TABLES = [
        'services',
        'service_blackout_dates',
        'service_providers',
        'service_provider_service',
        'availability_schedules',
        'availability_overrides',
        'booking_series',
        'bookings',
        'booking_calendar_connections',
        'booking_calendar_events',
        'booking_calendar_watch_channels',
        'booking_webhooks',
        'booking_webhook_deliveries',
        'booking_notification_log',
        'booking_intake_schema_versions',
        'booking_calendar_busy_blocks',
    ];

    /**
     * The tables a site owns directly and therefore scopes by.
     *
     * The tables not listed here hang off one of these — a busy block belongs
     * to a connection, a schema version to a service — so they are reached
     * through an already-scoped parent. Giving them their own `site_id` would
     * add a second copy of the same fact and somewhere for it to disagree.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    private const SITE_SCOPED_TABLES = [
        'services',
        'service_blackout_dates',
        'service_providers',
        'booking_series',
        'bookings',
        'booking_calendar_connections',
        'booking_webhooks',
    ];

    /**
     * The tables holding customer or provider personal data.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    private const PERSONAL_DATA_TABLES = [
        'services',
        'service_providers',
        'booking_series',
        'bookings',
    ];

    /**
     * Asserts every planned table was created.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function assertEveryTableExists(): void
    {
        foreach ( self::EXPECTED_TABLES as $table ) {
            expect( Schema::hasTable( $table ) )
                ->toBeTrue( sprintf( 'The "%s" table was not created.', $table ) );
        }
    }

    /**
     * Asserts the site-owned tables carry a nullable, indexed `site_id`.
     *
     * Nullable is the load-bearing part. A single-tenant application never
     * populates the column, so a NOT NULL one would force every such
     * application to invent a site id it has no use for.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function assertSiteScopingColumnsExist(): void
    {
        foreach ( self::SITE_SCOPED_TABLES as $table ) {
            $column = collect( Schema::getColumns( $table ) )->firstWhere( 'name', 'site_id' );

            expect( $column )->not->toBeNull( sprintf( '"%s" has no site_id column.', $table ) );
            expect( $column['nullable'] )
                ->toBeTrue( sprintf( '"%s".site_id must be nullable.', $table ) );

            $indexed = collect( Schema::getIndexes( $table ) )
                ->contains( fn ( array $index ): bool => 'site_id' === ( $index['columns'][0] ?? null ) );

            expect( $indexed )->toBeTrue( sprintf( '"%s".site_id is not indexed.', $table ) );
        }
    }

    /**
     * Asserts the personal-data tables can be soft deleted and erased.
     *
     * The two are not the same thing and both are needed: a soft delete hides a
     * row while keeping the audit trail, and `pii_erased_at` records that the
     * personal fields on a row that still has to exist were actually cleared.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function assertErasureColumnsExist(): void
    {
        foreach ( self::PERSONAL_DATA_TABLES as $table ) {
            expect( Schema::hasColumn( $table, 'deleted_at' ) )
                ->toBeTrue( sprintf( '"%s" is not soft deletable.', $table ) );
            expect( Schema::hasColumn( $table, 'pii_erased_at' ) )
                ->toBeTrue( sprintf( '"%s" has no pii_erased_at column.', $table ) );
        }
    }

    /**
     * Inserts a service and returns its id.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Attributes overriding the defaults.
     *
     * @return int The new service id.
     */
    protected function insertService( array $attributes = [] ): int
    {
        return (int) DB::table( 'services' )->insertGetId( array_merge( [
            'name'       => 'Consultation',
            'slug'       => 'consultation-' . uniqid(),
            'duration'   => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes ) );
    }

    /**
     * Inserts a service provider and returns its id.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Attributes overriding the defaults.
     *
     * @return int The new provider id.
     */
    protected function insertProvider( array $attributes = [] ): int
    {
        return (int) DB::table( 'service_providers' )->insertGetId( array_merge( [
            'name'       => 'Dana',
            'slug'       => 'dana-' . uniqid(),
            'timezone'   => 'America/Chicago',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes ) );
    }

    /**
     * Inserts a booking.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Attributes overriding the defaults.
     *
     * @return int The new booking id.
     */
    protected function insertBooking( array $attributes = [] ): int
    {
        return (int) DB::table( 'bookings' )->insertGetId( array_merge( [
            'booking_number'        => 'BK-' . uniqid(),
            'customer_name'         => 'Sam',
            'customer_email'        => 'sam@example.test',
            'customer_timezone'     => 'America/Chicago',
            'start_time'            => '2026-03-02 15:00:00',
            'end_time'              => '2026-03-02 15:30:00',
            'status'                => 'confirmed',
            'intake_schema_version' => 1,
            'manage_token_hash'     => hash( 'sha256', uniqid( '', true ) ),
            'created_at'            => now(),
            'updated_at'            => now(),
        ], $attributes ) );
    }

    /**
     * Inserts a notification log entry.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Attributes overriding the defaults.
     *
     * @return int The new log entry id.
     */
    protected function insertNotificationLog( array $attributes = [] ): int
    {
        return (int) DB::table( 'booking_notification_log' )->insertGetId( array_merge( [
            'channel'       => 'mail',
            'type'          => 'reminder',
            'recipient'     => 'sam@example.test',
            'scheduled_for' => '2026-03-01 15:00:00',
            'status'        => 'pending',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $attributes ) );
    }

    /**
     * Asserts every planned table is dropped when the batch is rolled back.
     *
     * The schema is migrated back afterwards. Testbench already restores it
     * between tests — verified by probing `Schema::hasTable()` in the following
     * test with this line removed, on MySQL, where DDL commits implicitly and
     * the transaction `RefreshDatabase` opened therefore cannot undo the drops.
     * So this is not repairing a live failure. It is here because a test that
     * drops sixteen tables should put them back itself rather than rely on a
     * harness detail to notice, and `leaves a usable schema behind the rollback
     * test` pins that either way.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function assertRollbackDropsEveryTable(): void
    {
        $this->artisan( 'migrate:rollback', [ '--force' => true ] )->run();

        try {
            foreach ( self::EXPECTED_TABLES as $table ) {
                expect( Schema::hasTable( $table ) )
                    ->toBeFalse( sprintf( 'The "%s" table survived the rollback.', $table ) );
            }
        } finally {
            // In a finally block so that a failed assertion still leaves the
            // schema behind it intact: one broken expectation should report one
            // failure, not cascade into every test that follows.
            $this->artisan( 'migrate', [ '--force' => true ] )->run();
        }
    }

    /**
     * Gets the column names of an index on a table, if it exists.
     *
     * @since 1.0.0
     *
     * @param  string  $table  The table to inspect.
     * @param  string  $index  The index name.
     *
     * @return array<int, string>|null The indexed columns, or null when absent.
     */
    protected function indexColumns( string $table, string $index ): ?array
    {
        $found = collect( Schema::getIndexes( $table ) )->firstWhere( 'name', $index );

        return null === $found ? null : array_values( $found['columns'] );
    }
}
