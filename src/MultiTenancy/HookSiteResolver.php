<?php

/**
 * Hook-backed site resolver.
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
use UnexpectedValueException;

use function applyFilters;
use function filter_var;
use function get_debug_type;
use function is_int;
use function is_string;
use function sprintf;

use const FILTER_VALIDATE_INT;

/**
 * Resolves the current site through the CMS framework's filter hook.
 *
 * This is the default resolver. It applies the site-resolution filter with a
 * null default, so a standalone installation — where nothing is listening —
 * resolves to null and behaves as a single-tenant application. When
 * artisanpack-ui/cms-framework is installed it answers the filter, and the same
 * resolver starts returning a real site. The coupling flows one way: bookings
 * listens, and cms-framework never needs to know bookings exists.
 *
 * The filter is applied on every call rather than memoised, because a queue
 * worker or console command may iterate over several sites within a single
 * process and a cached answer would scope those later iterations to the wrong
 * site.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class HookSiteResolver implements SiteResolver
{
    /**
     * The filter hook the current site is resolved through.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const HOOK = 'ap.cmsFramework.currentSite.resolve';

    /**
     * Gets the identifier of the site currently in context.
     *
     * @since 1.0.0
     *
     * @throws UnexpectedValueException When a listener returns a value that is
     *                                  neither null nor an integer identifier.
     *
     * @return int|null The current site identifier, or null when nothing
     *                  answers the filter.
     */
    public function currentSiteId(): ?int
    {
        $resolved = applyFilters( self::HOOK, null );

        if ( null === $resolved ) {
            return null;
        }

        if ( is_int( $resolved ) ) {
            return $resolved;
        }

        if ( is_string( $resolved ) ) {
            $asInt = filter_var( $resolved, FILTER_VALIDATE_INT );

            if ( false !== $asInt ) {
                return $asInt;
            }
        }

        // Coercing an unusable value to null would silently unscope every
        // query, which leaks one site's bookings into another. Failing loudly
        // is the safer direction.
        throw new UnexpectedValueException( sprintf(
            'The "%s" filter must return an integer site identifier or null, got %s.',
            self::HOOK,
            get_debug_type( $resolved ),
        ) );
    }
}
