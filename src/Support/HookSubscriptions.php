<?php

/**
 * Optional-package subscription gate.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Support;

use Closure;
use InvalidArgumentException;

use function array_keys;
use function class_exists;
use function implode;
use function interface_exists;
use function sprintf;

/**
 * Binds a subscription to another package's hook only when that package is here.
 *
 * Every package this one integrates with is a `suggest`, not a `require` — the
 * bookings package runs standalone. So an integration cannot simply
 * `addFilter()` at boot: the callback would reference classes that do not exist,
 * and the fatal would land wherever the upstream filter happens to be applied
 * rather than at the call that was wrong.
 *
 * This class is the one place that answers "is that package installed?", so the
 * answer is given the same way everywhere and there is a single list of what
 * counts as proof. Probing a class rather than reading the composer manifest is
 * what makes it hold under a package that is installed but whose provider an
 * application has deliberately not registered — the class is what the callback
 * actually needs.
 *
 * ## Upstream hooks this package subscribes to
 *
 * These keep their upstream names. We do not rename another package's hooks.
 *
 * | Package        | Hook                              | Bound by |
 * |----------------|-----------------------------------|----------|
 * | cms-framework  | `ap.cmsFramework.admin.menu`      | #38      |
 * | forms          | `ap.forms.fieldTypes`             | #48      |
 *
 * Site resolution is deliberately absent. #3 originally planned to subscribe to
 * a `currentSite.resolve` filter directly; it shipped reading
 * {@see \ArtisanPackUI\Core\MultiTenancy\SiteContext} instead, which is where
 * `artisanpack-ui/core` applies `ap.cmsFramework.currentSite.resolve` on behalf
 * of the whole ecosystem. Subscribing here as well would resolve the site twice
 * and let this package disagree with every other one about which site a request
 * is for.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class HookSubscriptions
{
    /**
     * The class whose presence proves an optional package is installed.
     *
     * The probe is the class the integration itself would reach for, not the
     * package's service provider — a provider can be present while the feature
     * the integration hangs off has moved or been renamed, and the probe should
     * fail in that case too.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    private const PROBES = [
        'cms-framework' => 'ArtisanPackUI\\CmsFramework\\Modules\\Admin\\Managers\\AdminMenuManager',
        'forms'         => 'ArtisanPackUI\\Forms\\Config\\FieldTypes',
    ];

    /**
     * Determines whether an optional package is installed.
     *
     * @since 1.0.0
     *
     * @param  string  $package  The package slug, as keyed in the probe list.
     *
     * @throws InvalidArgumentException When the slug names no known package.
     *
     * @return bool True when the package's probe class is loadable.
     */
    public static function isInstalled( string $package ): bool
    {
        $probe = self::PROBES[ $package ] ?? null;

        if ( null === $probe ) {
            throw new InvalidArgumentException( sprintf(
                'Unknown optional package [%s]. Known packages: %s.',
                $package,
                implode( ', ', array_keys( self::PROBES ) ),
            ) );
        }

        // Interfaces too, so a probe can point at a contract without the caller
        // having to know which of the two the upstream package declared.
        return class_exists( $probe ) || interface_exists( $probe );
    }

    /**
     * Runs a subscription only when the package it integrates with is installed.
     *
     * The callback is what holds the `addAction()` / `addFilter()` calls, so
     * nothing referencing the other package is even parsed as a closure body
     * until the gate has opened.
     *
     * @since 1.0.0
     *
     * @param  string  $package  The package slug, as keyed in the probe list.
     * @param  Closure  $subscribe  The subscription to run when it is installed.
     *
     * @throws InvalidArgumentException When the slug names no known package.
     *
     * @return bool True when the subscription ran.
     */
    public static function whenInstalled( string $package, Closure $subscribe ): bool
    {
        if ( ! self::isInstalled( $package ) ) {
            return false;
        }

        $subscribe();

        return true;
    }

    /**
     * Gets the optional packages this one knows how to integrate with.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The known package slugs.
     */
    public static function knownPackages(): array
    {
        return array_keys( self::PROBES );
    }
}
