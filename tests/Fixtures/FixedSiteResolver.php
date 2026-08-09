<?php

/**
 * Fixed site resolver fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Core\Contracts\SiteResolver;

/**
 * A resolver that always reports the same site.
 *
 * Stands in for the application-supplied resolver an operator configures
 * through `artisanpack.core.multi_tenant.resolvers`. The default is what the
 * container builds when the class is named in configuration and given no
 * arguments.
 *
 * @since 1.0.0
 */
class FixedSiteResolver implements SiteResolver
{
    /**
     * Constructs the resolver.
     *
     * @since 1.0.0
     *
     * @param  int|string|null  $siteId  The site to report. Defaults to site 1.
     */
    public function __construct( protected int|string|null $siteId = 1 )
    {
    }

    /**
     * Gets the identifier of the site currently in context.
     *
     * @since 1.0.0
     *
     * @return int|string|null The configured site identifier.
     */
    public function currentSiteId(): int|string|null
    {
        return $this->siteId;
    }
}
