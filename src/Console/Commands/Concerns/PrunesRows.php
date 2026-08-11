<?php

/**
 * Shared pruning behaviour for the retention commands.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Console\Commands\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

use function config;
use function count;
use function is_numeric;
use function max;

/**
 * The bits every `bookings:prune-*` command does the same way.
 *
 * Three of them delete rows older than a retention window, and all three have
 * the same two problems: a window that has to come out of configuration without
 * a bad value quietly meaning "delete everything", and a delete that must not be
 * one statement wide enough to hold a lock over a year of history.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
trait PrunesRows
{
    /**
     * How many rows one delete statement removes.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected int $pruneChunkSize = 1000;

    /**
     * Reads a retention window, in days, out of configuration.
     *
     * Returns null rather than a number when the setting is missing, unreadable,
     * or not positive. A prune asked to keep data for zero days would delete the
     * lot, and the likeliest way to arrive at zero is a typo or an empty
     * environment variable rather than somebody's retention policy — so the
     * commands treat it as "retention is not configured" and do nothing, which
     * is the mistake that can be undone.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The key under `artisanpack.bookings`.
     *
     * @return int|null The window in days, or null when none is configured.
     */
    protected function retentionDays( string $key ): ?int
    {
        $days = config( 'artisanpack.bookings.' . $key );

        if ( ! is_numeric( $days ) || (int) $days < 1 ) {
            return null;
        }

        return (int) $days;
    }

    /**
     * Gets the moment before which rows are old enough to remove.
     *
     * **In the application's own zone, which is what `created_at` is in.**
     * Eloquent stamps a row with `Carbon::now()` and stores whatever `format()`
     * renders, so a local-zone timestamp is written as local wall clock and a
     * local-zone cutoff compares against it correctly. Converting this to UTC
     * would break the two prunes that read `created_at`, by exactly the offset.
     *
     * A caller comparing against a column the package writes as UTC — `end_time`
     * being the one that matters, see {@see PruneCalendarEventsCommand} — has to
     * call `->utc()` on this itself. The two conventions genuinely differ, and
     * the difference is only visible on an application whose `app.timezone` is
     * not UTC.
     *
     * @since 1.0.0
     *
     * @param  int  $days  The retention window, in days.
     *
     * @return CarbonImmutable The cutoff, in the application's zone.
     */
    protected function pruneCutoff( int $days ): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays( max( 1, $days ) );
    }

    /**
     * Deletes everything a query matches, a chunk at a time.
     *
     * By a page of primary keys rather than by the matching predicate, because
     * the predicate on two of the three callers is a subquery against another
     * table and re-running it per chunk is work the keys have already done. It
     * also bounds the statement: a first prune on an installation that has never
     * run one can match years of rows, and one delete that wide holds locks for
     * as long as it takes.
     *
     * @since 1.0.0
     *
     * @param  Builder<Model>  $query  The rows to remove.
     *
     * @return int How many rows were deleted.
     */
    protected function pruneMatching( Builder $query ): int
    {
        $model   = $query->getModel();
        $deleted = 0;

        do {
            $keys = ( clone $query )
                ->reorder( $model->getQualifiedKeyName() )
                ->limit( $this->pruneChunkSize )
                ->pluck( $model->getKeyName() )
                ->all();

            if ( [] === $keys ) {
                break;
            }

            $deleted += $model->newQuery()->whereKey( $keys )->delete();
        } while ( count( $keys ) === $this->pruneChunkSize );

        return $deleted;
    }
}
