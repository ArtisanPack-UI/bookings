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

use ArtisanPackUI\Bookings\Contracts\SiteResolver;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains a query to the site currently in context.
 *
 * The scope is deliberately inert in three cases, each of which leaves every
 * row visible: multi-tenancy is switched off in configuration, no resolver is
 * bound in the container, or the bound resolver reports no site. That is what
 * lets a single-tenant application install the package and never think about
 * sites again.
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
     * The resolver is asked on every call rather than cached, because the site
     * in context can change within a single process — a console command that
     * loops over sites is the obvious case.
     *
     * @since 1.0.0
     *
     * @return int|null The current site identifier, or null when queries should
     *                  not be scoped at all.
     */
    public static function currentSiteId(): ?int
    {
        $container = Container::getInstance();

        if ( ! $container->bound( 'config' ) ) {
            return null;
        }

        if ( ! $container->make( 'config' )->get( 'artisanpack.bookings.multi_tenant.enabled', false ) ) {
            return null;
        }

        if ( ! $container->bound( SiteResolver::class ) ) {
            return null;
        }

        return $container->make( SiteResolver::class )->currentSiteId();
    }
}
