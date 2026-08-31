<?php

/**
 * Backfill site_id command.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Console\Commands;

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\Scopes\BelongsToSiteScope;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceBlackoutDate;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Models\Webhook;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stamps a site onto the rows written before site scoping was switched on.
 *
 * Turning `artisanpack.core.multi_tenant.enabled` on for an installation that
 * already holds bookings is not free. Every row written while scoping was off
 * carries a null `site_id`, and {@see BelongsToSiteScope} matches on equality —
 * so the moment a site resolves, all of those rows drop out of every
 * site-scoped query at once, leaving only `acrossAllSites()` able to see them.
 * A single-tenant install converting to multi-tenant runs this first, against
 * the site those existing rows belong to, so the switch does not make its own
 * data vanish.
 *
 * It walks every table a site owns directly. Everything else in the package
 * hangs off one of these — a busy block off a connection, a schema version off
 * a service — and is reached through an already-scoped parent, so it carries no
 * `site_id` of its own and needs no backfill.
 *
 * The walk deliberately spans every site and reaches soft-deleted rows: it
 * removes the site scope with `withoutGlobalScope()` so it runs the same
 * whether or not scoping is already on, and pulls in trashed rows because a
 * soft-deleted booking an erasure request still has to reach would otherwise
 * keep its orphaning null `site_id`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.1.0
 */
class BackfillSiteIdCommand extends Command
{
    /**
     * The models a site owns directly, each of which needs its own backfill.
     *
     * @since 1.1.0
     *
     * @var array<int, class-string<Model>>
     */
    protected const OWNED_MODELS = [
        Service::class,
        ServiceProvider::class,
        ServiceBlackoutDate::class,
        Booking::class,
        BookingSeries::class,
        CalendarConnection::class,
        Webhook::class,
    ];

    /**
     * The console command signature.
     *
     * @since 1.1.0
     *
     * @var string
     */
    protected $signature = 'bookings:backfill-site-id
        {--site= : The site identifier to stamp onto rows written before scoping was enabled.}
        {--dry-run : Report how many rows would be stamped without changing anything.}';

    /**
     * The console command description.
     *
     * @since 1.1.0
     *
     * @var string
     */
    protected $description = 'Stamps a site onto pre-scoping rows so enabling multi-tenancy does not hide existing bookings.';

    /**
     * Runs the command.
     *
     * @since 1.1.0
     *
     * @return int The command exit code.
     */
    public function handle(): int
    {
        $siteId = $this->resolveSiteId();

        if ( null === $siteId ) {
            $this->error( __( 'Pass --site with a positive integer identifying the site the existing rows belong to.' ) );

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option( 'dry-run' );
        $total  = 0;

        foreach ( self::OWNED_MODELS as $model ) {
            $orphans = $this->orphanQuery( $model );

            $affected = $dryRun
                ? $orphans->count()
                : $orphans->update( [ ( new $model() )->getSiteIdColumn() => $siteId ] );

            $total += $affected;

            $this->line( __( ':model: :count row(s)', [
                'model' => class_basename( $model ),
                'count' => $affected,
            ] ) );
        }

        if ( $dryRun ) {
            $this->info( __(
                ':count row(s) would be stamped with site :site.',
                [ 'count' => $total, 'site' => $siteId ],
            ) );

            return self::SUCCESS;
        }

        $this->info( __(
            ':count row(s) stamped with site :site.',
            [ 'count' => $total, 'site' => $siteId ],
        ) );

        return self::SUCCESS;
    }

    /**
     * Reads and validates the target site identifier.
     *
     * The `site_id` columns are unsigned big integers, so a value that is not a
     * positive integer could never own a row and is refused. `FILTER_VALIDATE_INT`
     * does the refusing rather than a regex-and-cast: a value past `PHP_INT_MAX`
     * matches a digits-only pattern but `(int)` would clamp it to `PHP_INT_MAX`,
     * stamping every row with a site the operator never named — so the whole
     * point is to reject the out-of-range value instead of coercing it.
     *
     * @since 1.1.0
     *
     * @return int|null The site identifier, or null when the option is absent or invalid.
     */
    protected function resolveSiteId(): ?int
    {
        $value = $this->option( 'site' );

        // The command line always hands options over as strings; a programmatic
        // caller such as Artisan::call() can pass the integer directly.
        if ( is_string( $value ) ) {
            $value = trim( $value );
        }

        $siteId = filter_var( $value, FILTER_VALIDATE_INT );

        if ( false === $siteId || $siteId < 1 ) {
            return null;
        }

        return $siteId;
    }

    /**
     * Builds the unscoped query for a model's rows that still lack a site.
     *
     * @since 1.1.0
     *
     * @param  class-string<Model>  $model  The owned model to query.
     *
     * @return Builder<Model> The query matching every siteless row across all sites.
     */
    protected function orphanQuery( string $model ): Builder
    {
        $instance = new $model();

        $query = $model::query()->withoutGlobalScope( BelongsToSiteScope::class );

        if ( in_array( SoftDeletes::class, class_uses_recursive( $model ), true ) ) {
            $query->withTrashed();
        }

        return $query->whereNull( $instance->getSiteIdColumn() );
    }
}
