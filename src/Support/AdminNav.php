<?php

/**
 * The admin navigation this package owns.
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

use Illuminate\Support\Facades\Route;

/**
 * The one list of the staff-facing screens, and the two shapes it is read in.
 *
 * The admin surface is reached two ways depending on the installation. Standing
 * alone, the package renders its own sidebar around these screens; with
 * cms-framework present, the same screens are handed to that shell's navigation
 * through the `ap.cmsFramework.admin.menu` filter. Both readers describe the
 * same list of pages, so the list lives here once — a screen added to the
 * sidebar but forgotten in the CMS menu (or the reverse) is the failure this
 * class exists to make impossible.
 *
 * Every entry names a route rather than a URL. The routes are registered from
 * `routes/admin.php` whether or not cms-framework is installed — only the layout
 * they render inside changes — so `route()` resolves in both worlds, and a
 * prefix an application has renamed in config follows automatically because the
 * name is stable while the path is not.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class AdminNav
{
    /**
     * The slug the CMS menu keys the bookings section under.
     *
     * cms-framework's menu is a flat `slug => entry` map, so a single slug owns
     * the whole bookings section and its children hang off it as `subItems`. It
     * is `bookings` rather than the route prefix because it names a place in
     * another package's menu, not a URL — and the prefix is an application's to
     * rename.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CMS_MENU_SLUG = 'bookings';

    /**
     * Gets the screens, in the order they are shown.
     *
     * Each entry is the parent-level navigation for a screen: the detail,
     * create, and intake-schema routes are reached from within a screen rather
     * than from the sidebar, so they are deliberately absent here.
     *
     * @since 1.0.0
     *
     * @return array<int, array{slug: string, label: string, route: string, icon: string}>
     */
    public static function items(): array
    {
        return [
            [
                'slug'  => 'bookings',
                'label' => __( 'Bookings' ),
                'route' => 'artisanpack.bookings.admin.bookings',
                'icon'  => 'fas.calendar-check',
            ],
            [
                'slug'  => 'bookings-calendar',
                'label' => __( 'Calendar' ),
                'route' => 'artisanpack.bookings.admin.calendar',
                'icon'  => 'fas.calendar-days',
            ],
            [
                'slug'  => 'bookings-services',
                'label' => __( 'Services' ),
                'route' => 'artisanpack.bookings.admin.services',
                'icon'  => 'fas.list-check',
            ],
            [
                'slug'  => 'bookings-providers',
                'label' => __( 'Providers' ),
                'route' => 'artisanpack.bookings.admin.providers',
                'icon'  => 'fas.users',
            ],
            [
                'slug'  => 'bookings-blackout-dates',
                'label' => __( 'Blackout Dates' ),
                'route' => 'artisanpack.bookings.admin.blackout-dates',
                'icon'  => 'fas.calendar-xmark',
            ],
            [
                'slug'  => 'bookings-series',
                'label' => __( 'Series' ),
                'route' => 'artisanpack.bookings.admin.series',
                'icon'  => 'fas.repeat',
            ],
            [
                'slug'  => 'bookings-calendar-connections',
                'label' => __( 'Calendar Connections' ),
                'route' => 'artisanpack.bookings.admin.calendar-connections',
                'icon'  => 'fas.plug',
            ],
            [
                'slug'  => 'bookings-webhooks',
                'label' => __( 'Webhooks' ),
                'route' => 'artisanpack.bookings.admin.webhooks',
                'icon'  => 'fas.bolt',
            ],
            [
                'slug'  => 'bookings-notifications',
                'label' => __( 'Notifications' ),
                'route' => 'artisanpack.bookings.admin.notifications',
                'icon'  => 'fas.bell',
            ],
            [
                'slug'  => 'bookings-settings',
                'label' => __( 'Settings' ),
                'route' => 'artisanpack.bookings.admin.settings',
                'icon'  => 'fas.gear',
            ],
        ];
    }

    /**
     * Builds the bookings contribution to cms-framework's admin menu.
     *
     * One top-level `bookings` node holding every screen as a `subItem`. The
     * shape mirrors what cms-framework's own decoration produces — `label`,
     * `url`, `permission`, `order` — because the `ap.cmsFramework.admin.menu`
     * filter runs *after* that decoration, so an injected node has to arrive
     * already wearing the keys the renderer reads. cms-framework re-applies its
     * capability gate and re-sanitizes URLs after the filter, so the
     * `permission` here is still enforced and a hostile `url` still cannot slip
     * through.
     *
     * The parent carries the gate as well as the children so the whole section
     * disappears for a user who cannot manage bookings, rather than showing an
     * empty heading.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The parent node, keyed by slug.
     */
    public static function cmsMenuEntries(): array
    {
        $permission = self::gate();
        $subItems   = [];
        $order      = 10;

        foreach ( self::items() as $item ) {
            $subItems[ $item['slug'] ] = [
                'slug'       => $item['slug'],
                'label'      => $item['label'],
                'menuTitle'  => $item['label'],
                'title'      => $item['label'],
                'url'        => self::url( $item['route'] ),
                'icon'       => $item['icon'],
                'iconId'     => $item['icon'],
                'permission' => $permission,
                'order'      => $order,
                'external'   => false,
            ];

            $order += 10;
        }

        return [
            self::CMS_MENU_SLUG => [
                'slug'       => self::CMS_MENU_SLUG,
                'label'      => __( 'Bookings' ),
                'menuTitle'  => __( 'Bookings' ),
                'title'      => __( 'Bookings' ),
                'url'        => self::url( 'artisanpack.bookings.admin.bookings' ),
                'icon'       => 'fas.calendar-check',
                'iconId'     => 'fas.calendar-check',
                'permission' => $permission,
                'order'      => 80,
                'external'   => false,
                'subItems'   => $subItems,
            ],
        ];
    }

    /**
     * Subscribes the bookings entries to cms-framework's admin menu.
     *
     * Bound only when the application has left `admin.auto_register_cms_nav` on —
     * an installation that mounts the screens somewhere of its own choosing turns
     * it off and keeps the shell's menu its own — and only when cms-framework is
     * actually installed. The gate is {@see HookSubscriptions::whenInstalled()}
     * rather than a bare `addFilter()` because the callback references a hook
     * that only exists in cms-framework, and the gate keeps it from being wired
     * onto a filter the application will never apply.
     *
     * @since 1.0.0
     *
     * @return bool True when the subscription was bound.
     */
    public static function subscribeToCmsMenu(): bool
    {
        if ( ! config( 'artisanpack.bookings.admin.auto_register_cms_nav', true ) ) {
            return false;
        }

        return HookSubscriptions::whenInstalled( 'cms-framework', static function (): void {
            addFilter(
                'ap.cmsFramework.admin.menu',
                static fn ( array $menu ): array => self::injectInto( $menu ),
            );
        } );
    }

    /**
     * Merges the bookings entries into a cms-framework admin menu.
     *
     * An entry already present in the menu wins over ours for the keys it
     * declares — the same precedence cms-framework's own plugin registry uses —
     * so an application that has customised the bookings section keeps its
     * customisation rather than having it overwritten on every request.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $menu  The menu as the filter received it.
     *
     * @return array<string, mixed> The menu with the bookings entries merged in.
     */
    public static function injectInto( array $menu ): array
    {
        foreach ( self::cmsMenuEntries() as $slug => $entry ) {
            $menu[ $slug ] = array_merge( $entry, $menu[ $slug ] ?? [] );
        }

        return $menu;
    }

    /**
     * Resolves a screen's URL, tolerating a route that is not registered.
     *
     * The nav is built at request time, so under a fully cached route table or
     * a headless install that never loaded `routes/admin.php` a name might not
     * resolve. A missing screen should drop out of the menu, not fatal the whole
     * page it was being drawn into.
     *
     * @since 1.0.0
     *
     * @param  string  $name  The route name.
     *
     * @return string The URL, or `#` when the route is not registered.
     */
    public static function url( string $name ): string
    {
        return Route::has( $name ) ? route( $name ) : '#';
    }

    /**
     * Gets the gate every admin screen sits behind.
     *
     * @since 1.0.0
     *
     * @return string The ability name.
     */
    public static function gate(): string
    {
        $gate = config( 'artisanpack.bookings.admin.gate', 'bookings.manage' );

        return is_string( $gate ) && '' !== $gate ? $gate : 'bookings.manage';
    }
}
