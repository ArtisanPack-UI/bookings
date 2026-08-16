<?php

/**
 * Calendar driver registry contract.
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

use UnexpectedValueException;

/**
 * Holds the calendar sync drivers a connection can be routed to.
 *
 * This is the one registration surface every driver reaches through. The
 * built-in read-only iCal feed seeds itself here, and the driver packages —
 * `artisanpack-ui/google`, `artisanpack-ui/microsoft`, `artisanpack-ui/apple` —
 * add themselves through the `ap.bookings.calendarSync.providers` filter, as
 * does any out-of-tree driver. Nothing resolves a driver from a hardcoded map:
 * a connection whose `driver` value is not registered here simply has nothing to
 * sync it.
 *
 * Resolution runs the registered set through the filter on every read rather
 * than once at boot, so a driver registered from a service provider that boots
 * later than this package's still appears. Because the filter runs on every
 * read, all three read methods depend on what third-party subscribers return,
 * and all three may therefore throw. Do not treat `has()` as the safe way to
 * probe the registry — it is exactly as exposed to a bad subscriber as `all()`
 * is.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface CalendarDriverRegistry
{
    /**
     * Adds a driver to the registry.
     *
     * Registering a driver whose key already exists replaces it, which is how an
     * application overrides a built-in driver rather than removing and re-adding
     * it. The key is the driver's own {@see CalendarSyncDriver::driver()} value.
     *
     * @since 1.0.0
     *
     * @param  CalendarSyncDriver  $driver  The driver to add.
     *
     * @return void
     */
    public function register( CalendarSyncDriver $driver ): void;

    /**
     * Determines whether a driver is registered under a key.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The `CalendarDriver` value to look for.
     *
     * @throws UnexpectedValueException When a subscriber to
     *                                  `ap.bookings.calendarSync.providers`
     *                                  returns something other than an array of
     *                                  calendar sync drivers.
     *
     * @return bool True when the key resolves to a driver.
     */
    public function has( string $key ): bool;

    /**
     * Gets the driver registered under a key.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The `CalendarDriver` value to look up.
     *
     * @throws UnexpectedValueException When a subscriber to
     *                                  `ap.bookings.calendarSync.providers`
     *                                  returns something other than an array of
     *                                  calendar sync drivers.
     *
     * @return CalendarSyncDriver|null The driver, or null when nothing is
     *                                 registered under the key.
     */
    public function get( string $key ): ?CalendarSyncDriver;

    /**
     * Gets every registered driver, keyed by identifier.
     *
     * @since 1.0.0
     *
     * @throws UnexpectedValueException When a subscriber to
     *                                  `ap.bookings.calendarSync.providers`
     *                                  returns something other than an array of
     *                                  calendar sync drivers.
     *
     * @return array<string, CalendarSyncDriver> The registered drivers.
     */
    public function all(): array;
}
