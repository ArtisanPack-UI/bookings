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

use ArtisanPackUI\Bookings\Contracts\SiteResolver;

/**
 * A resolver that always reports the same site.
 *
 * Stands in for the application-supplied resolver an operator configures
 * through `multi_tenant.site_resolver`. The default is what the container
 * builds when the class is named in configuration and given no arguments.
 *
 * @since 1.0.0
 */
class FixedSiteResolver implements SiteResolver
{
    /**
     * The site this resolver reports.
     *
     * @var int|null
     */
    protected ?int $siteId;

    /**
     * Constructs the resolver.
     *
     * The house rules ask for constructor property promotion, which would leave
     * this constructor with an empty body — and Pint and PHP-CS-Fixer disagree
     * about how to format one, each undoing the other on every run. Written out
     * longhand, both agree. Worth revisiting when the first promoted
     * constructor lands in src/, where the rule actually earns its keep.
     *
     * @since 1.0.0
     *
     * @param  int|null  $siteId  The site to report. Defaults to site 1.
     */
    public function __construct( ?int $siteId = 1 )
    {
        $this->siteId = $siteId;
    }

    /**
     * Gets the identifier of the site currently in context.
     *
     * @since 1.0.0
     *
     * @return int|null The configured site identifier.
     */
    public function currentSiteId(): ?int
    {
        return $this->siteId;
    }
}
