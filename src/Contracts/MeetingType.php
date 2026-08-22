<?php

/**
 * Meeting type contract.
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
 * One shape a service can be booked in.
 *
 * The package ships four — one-to-one, group, recurring, and round-robin — and
 * every one of them is a registry entry rather than a branch in a match
 * statement, so an application adding a fifth is doing the same thing the
 * package does rather than patching around it. Entries are contributed through
 * the `ap.bookings.registeredMeetingTypes` filter.
 *
 * A type describes how a booking behaves, not what it is about. "60 minute
 * consultation" is a service; "several attendees share one slot" is a meeting
 * type.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface MeetingType
{
    /**
     * Gets the stable identifier the type is stored and registered under.
     *
     * Written to service rows, so it must not change once anything has been
     * booked against it.
     *
     * @since 1.0.0
     *
     * @return string The type key, such as `one_to_one`.
     */
    public function key(): string;

    /**
     * Gets the human-readable name, translated.
     *
     * @since 1.0.0
     *
     * @return string The label shown to staff choosing a type.
     */
    public function label(): string;

    /**
     * Gets the human-readable explanation, translated.
     *
     * @since 1.0.0
     *
     * @return string A sentence explaining when to choose this type.
     */
    public function description(): string;

    /**
     * Determines whether one slot holds more than one attendee.
     *
     * False is the case that makes a slot exclusive, which is what the unique
     * index on `bookings` enforces. A type that answers true is opting out of
     * that exclusivity and into a capacity check instead.
     *
     * @since 1.0.0
     *
     * @return bool True when several bookings may share a slot.
     */
    public function allowsMultipleAttendees(): bool;

    /**
     * Determines whether booking this type creates a series rather than a row.
     *
     * @since 1.0.0
     *
     * @return bool True when a booking expands into recurring occurrences.
     */
    public function isRecurring(): bool;

    /**
     * Determines whether the provider is chosen for the customer.
     *
     * A type that answers true routes assignment through the bound
     * {@see RoundRobinStrategy} instead of asking the customer to pick.
     *
     * @since 1.0.0
     *
     * @return bool True when the package assigns the provider.
     */
    public function assignsProviderAutomatically(): bool;
}
