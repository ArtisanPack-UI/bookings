<?php

/**
 * Calendar driver registry.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Calendar;

use ArtisanPackUI\Bookings\Contracts\CalendarDriverRegistry as CalendarDriverRegistryContract;
use ArtisanPackUI\Bookings\Contracts\CalendarSyncDriver;
use ArtisanPackUI\Bookings\Drivers\Calendar\IcalFeedDriver;
use UnexpectedValueException;

/**
 * The registry the package ships, backed by the extension filter.
 *
 * The read-only iCal driver is seeded on construction and is an ordinary entry,
 * not a special case: an application can replace it by registering its own
 * driver under the `ical` key, and the rest of the package cannot tell the
 * difference. Every other driver — Google, Microsoft, Apple, and anything
 * out-of-tree — arrives through the `ap.bookings.calendarSync.providers` filter.
 *
 * Drivers are keyed by their {@see CalendarSyncDriver::driver()} value, which is
 * the same value a connection stores in its `driver` column — so routing a
 * connection to its driver is one lookup by that value, with no map to keep in
 * step.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class CalendarDriverRegistry implements CalendarDriverRegistryContract
{
    /**
     * The drivers registered in PHP, before the filter runs.
     *
     * @since 1.0.0
     *
     * @var array<string, CalendarSyncDriver>
     */
    protected array $drivers = [];

    /**
     * Constructs the registry with the built-in iCal driver.
     *
     * The driver is injected rather than newed so the container wires its own
     * dependencies — the feed builder and the token service it delegates the
     * subscription URL to.
     *
     * @since 1.0.0
     *
     * @param  IcalFeedDriver  $ical  The built-in read-only outbound driver.
     */
    public function __construct( IcalFeedDriver $ical )
    {
        $this->register( $ical );
    }

    /**
     * Adds a driver to the registry.
     *
     * @since 1.0.0
     *
     * @param  CalendarSyncDriver  $driver  The driver to add.
     *
     * @return void
     */
    public function register( CalendarSyncDriver $driver ): void
    {
        $this->drivers[ $driver->driver()->value ] = $driver;
    }

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
     *                                  calendar sync drivers. Reading the
     *                                  registry runs the filter, so even this
     *                                  call can fail on a third party's bad
     *                                  subscriber.
     *
     * @return bool True when the key resolves to a driver.
     */
    public function has( string $key ): bool
    {
        return null !== $this->get( $key );
    }

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
    public function get( string $key ): ?CalendarSyncDriver
    {
        return $this->all()[ $key ] ?? null;
    }

    /**
     * Gets every registered driver, keyed by identifier.
     *
     * The `ap.bookings.calendarSync.providers` filter runs here, on every call,
     * rather than once at boot. A package registering its driver from its own
     * service provider has no way of knowing whether it boots before or after
     * this one, and filtering late means it does not have to.
     *
     * The filtered result is re-keyed from each driver's own value, so a
     * subscriber that appends with `$drivers[] = $driver` gets the same outcome
     * as one that assigns to a key — and cannot register a driver under a key
     * that disagrees with the one the driver reports.
     *
     * @since 1.0.0
     *
     * @throws UnexpectedValueException When a subscriber returns something other
     *                                  than an array of calendar sync drivers.
     *
     * @return array<string, CalendarSyncDriver> The registered drivers.
     */
    public function all(): array
    {
        $filtered = applyFilters( 'ap.bookings.calendarSync.providers', $this->drivers );

        if ( ! is_array( $filtered ) ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.calendarSync.providers must return an array, got %s.',
                get_debug_type( $filtered ),
            ) );
        }

        $drivers = [];

        foreach ( $filtered as $driver ) {
            if ( ! $driver instanceof CalendarSyncDriver ) {
                throw new UnexpectedValueException( sprintf(
                    'ap.bookings.calendarSync.providers must return %s instances, got %s.',
                    CalendarSyncDriver::class,
                    get_debug_type( $driver ),
                ) );
            }

            $drivers[ $driver->driver()->value ] = $driver;
        }

        return $drivers;
    }
}
