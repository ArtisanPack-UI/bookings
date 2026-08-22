<?php

/**
 * Site global scope.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Models\Scopes;

use ArtisanPackUI\Core\MultiTenancy\SiteContext;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains a query to the site currently in context.
 *
 * The site itself comes from `artisanpack-ui/core`'s shared `SiteContext`,
 * which is the one place in the ecosystem a site becomes known — so a request
 * cannot be site 2 for one package while being site 1 for this one. That
 * context owns both questions this scope used to ask itself: whether site
 * resolution is switched on at all, and which resolver answers it.
 *
 * The scope is deliberately inert whenever the context reports no site, which
 * leaves every row visible. That is what lets a single-tenant application
 * install the package and never think about sites again.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BelongsToSiteScope implements Scope
{
    /**
     * Applies the scope to a given Eloquent query builder.
     *
     * @since 1.0.0
     *
     * @param  Builder<Model>  $builder  The query builder being scoped.
     * @param  Model  $model  The model the query is built from.
     *
     * @return void
     */
    public function apply( Builder $builder, Model $model ): void
    {
        $siteId = self::currentSiteId();

        if ( null === $siteId ) {
            return;
        }

        /** @var \ArtisanPackUI\Bookings\Models\Concerns\BelongsToSite&Model $model */
        $builder->where( $model->qualifyColumn( $model->getSiteIdColumn() ), '=', $siteId );
    }

    /**
     * Gets the site currently in context, if any.
     *
     * The context is asked on every call rather than cached, because the site
     * in context can change within a single process — a console command that
     * loops over sites with `SiteContext::forSite()` is the obvious case.
     *
     * The container is checked for the binding rather than assumed, so the
     * scope stays inert in a container that never registered core's provider
     * instead of failing to build a `SiteContext` out of nothing.
     *
     * @since 1.0.0
     *
     * @return int|string|null The current site identifier, or null when queries
     *                         should not be scoped at all.
     */
    public static function currentSiteId(): int|string|null
    {
        $container = Container::getInstance();

        if ( ! $container->bound( SiteContext::class ) ) {
            return null;
        }

        return $container->make( SiteContext::class )->currentSiteId();
    }
}
