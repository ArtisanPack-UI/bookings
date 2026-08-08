<?php

/**
 * Null site resolver.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\MultiTenancy;

use ArtisanPackUI\Bookings\Contracts\SiteResolver;

/**
 * A resolver that never puts a site in context.
 *
 * Bind this when an application wants multi-tenancy switched off outright
 * rather than merely unconfigured, so that no hook can put a site in context
 * behind its back.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class NullSiteResolver implements SiteResolver
{
    /**
     * Gets the identifier of the site currently in context.
     *
     * @since 1.0.0
     *
     * @return int|null Always null.
     */
    public function currentSiteId(): ?int
    {
        return null;
    }
}
