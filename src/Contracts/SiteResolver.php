<?php

/**
 * Site resolver contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Contracts;

/**
 * Resolves the site the current request belongs to.
 *
 * Every owned table carries a nullable `site_id`, and the BelongsToSite global
 * scope asks the bound resolver which site to filter on. Returning null means
 * "no site in context" — the scope then leaves the query alone rather than
 * filtering on a site nobody selected, which is what keeps a single-tenant
 * application working without configuring anything.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface SiteResolver
{
    /**
     * Gets the identifier of the site currently in context.
     *
     * @since 1.0.0
     *
     * @return int|null The current site identifier, or null when no site is in
     *                  context.
     */
    public function currentSiteId(): ?int;
}
