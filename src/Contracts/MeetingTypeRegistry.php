<?php

/**
 * Meeting type registry contract.
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
 * Holds the meeting types a booking can be made in.
 *
 * Resolution runs the registered set through the
 * `ap.bookings.registeredMeetingTypes` filter on every read rather than once at
 * boot, so a type registered from a service provider that boots later than this
 * package's still appears.
 *
 * Because the filter runs on every read, all three read methods depend on what
 * third-party subscribers return, and all three may therefore throw. Do not
 * treat `has()` as the safe way to probe the registry — it is exactly as
 * exposed to a bad subscriber as `all()` is.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface MeetingTypeRegistry
{
    /**
     * Adds a type to the registry.
     *
     * Registering a key that already exists replaces it, which is how an
     * application overrides a built-in type rather than removing and re-adding
     * it.
     *
     * @since 1.0.0
     *
     * @param  MeetingType  $type  The type to add.
     *
     * @return void
     */
    public function register( MeetingType $type ): void;

    /**
     * Determines whether a type is registered under a key.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The key to look for.
     *
     * @throws UnexpectedValueException When a subscriber to
     *                                  `ap.bookings.registeredMeetingTypes`
     *                                  returns something other than an array of
     *                                  meeting types.
     *
     * @return bool True when the key resolves to a type.
     */
    public function has( string $key ): bool;

    /**
     * Gets the type registered under a key.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The key to look up.
     *
     * @throws UnexpectedValueException When a subscriber to
     *                                  `ap.bookings.registeredMeetingTypes`
     *                                  returns something other than an array of
     *                                  meeting types.
     *
     * @return MeetingType|null The type, or null when nothing is registered under
     *                          the key.
     */
    public function get( string $key ): ?MeetingType;

    /**
     * Gets every registered type, keyed by identifier.
     *
     * @since 1.0.0
     *
     * @throws UnexpectedValueException When a subscriber to
     *                                  `ap.bookings.registeredMeetingTypes`
     *                                  returns something other than an array of
     *                                  meeting types.
     *
     * @return array<string, MeetingType> The registered types.
     */
    public function all(): array;
}
