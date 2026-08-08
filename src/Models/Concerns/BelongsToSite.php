<?php

/**
 * Site ownership concern.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Models\Concerns;

use ArtisanPackUI\Bookings\Models\Scopes\BelongsToSiteScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Opts a model into site scoping.
 *
 * A model that uses this trait needs a nullable `site_id` column. Every query
 * against it is filtered by the site in context, and new records are stamped
 * with that site on create unless one was set explicitly.
 *
 * Keep `site_id` out of the model's `$fillable`. An explicitly set site wins
 * over the resolved one — which is what makes deliberate cross-site writes
 * possible — so a mass-assignable `site_id` would let request data place a row
 * under another tenant. Laravel's default `$guarded = ['*']` already fails
 * closed here; the note is for whoever writes the first model that opts in.
 * Deliberate cross-site writes should go through `forceCreate()` or an explicit
 * `setAttribute()`, where the intent is visible at the call site.
 *
 * Two things defeat the stamp, both by design rather than oversight, and both
 * of which write rows that no site-scoped read will ever return:
 *
 * - Writes that bypass Eloquent's model events — `Model::query()->insert()`,
 *   `->upsert()`, and anything running under `Event::fake()` — never fire
 *   `creating`, so they land with a null `site_id`. A bulk import or seeder
 *   reaching for `insert()` has to set `site_id` itself.
 * - `Model::unguard()` makes `isFillable()` return true unconditionally, so an
 *   application that unguards globally puts `site_id` back within reach of mass
 *   assignment. Site-scoped models are not safe to use under a global unguard.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @mixin Model
 */
trait BelongsToSite
{
    /**
     * Boots the trait on the model.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function bootBelongsToSite(): void
    {
        static::addGlobalScope( new BelongsToSiteScope() );

        static::creating( function ( Model $model ): void {
            /** @var Model&self $model */
            $column = $model->getSiteIdColumn();

            if ( null !== $model->getAttribute( $column ) ) {
                return;
            }

            $model->setAttribute( $column, BelongsToSiteScope::currentSiteId() );
        } );
    }

    /**
     * Gets the name of the column holding the owning site.
     *
     * @since 1.0.0
     *
     * @return string The site foreign key column name.
     */
    public function getSiteIdColumn(): string
    {
        return 'site_id';
    }

    /**
     * Removes the site scope from a query.
     *
     * Useful for administrative and maintenance queries that deliberately span
     * every site, such as retention pruning.
     *
     * @since 1.0.0
     *
     * @param  Builder<Model>  $query  The query being built.
     *
     * @return Builder<Model> The query with the site scope removed.
     */
    public function scopeAcrossAllSites( Builder $query ): Builder
    {
        return $query->withoutGlobalScope( BelongsToSiteScope::class );
    }
}
