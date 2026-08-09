<?php

/**
 * Registered meeting type.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\MeetingTypes;

use ArtisanPackUI\Bookings\Contracts\MeetingType;

use function __;

/**
 * A meeting type described entirely by its data.
 *
 * The four built-in types differ only in the answers they give, not in how they
 * arrive at them, so they are four instances of this rather than four near
 * identical classes. An application whose type needs to compute an answer —
 * "group bookings only above a seat count" — writes its own
 * {@see MeetingType} instead.
 *
 * `$label` and `$description` are held as untranslated source strings and run
 * through `__()` when they are read, not when the type is built. The registry
 * is a singleton, so translating at construction would freeze every label at
 * whatever locale happened to be active the first time anything resolved it —
 * and a queue worker or an Octane process serves more than one locale from a
 * container it only booted once.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final readonly class RegisteredMeetingType implements MeetingType
{
    /**
     * Constructs the type.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The stable identifier.
     * @param  string  $label  The untranslated name, used as the translation key.
     * @param  string  $description  The untranslated explanation, used as the
     *                               translation key.
     * @param  bool  $allowsMultipleAttendees  Whether a slot holds several people.
     * @param  bool  $isRecurring  Whether booking creates a series.
     * @param  bool  $assignsProviderAutomatically  Whether the provider is chosen
     *                                              for the customer.
     */
    public function __construct(
        private string $key,
        private string $label,
        private string $description,
        private bool $allowsMultipleAttendees = false,
        private bool $isRecurring = false,
        private bool $assignsProviderAutomatically = false,
    ) {
    }

    /**
     * Gets the stable identifier the type is stored and registered under.
     *
     * @since 1.0.0
     *
     * @return string The type key.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Gets the human-readable name, translated.
     *
     * @since 1.0.0
     *
     * @return string The label in the current locale.
     */
    public function label(): string
    {
        return __( $this->label );
    }

    /**
     * Gets the human-readable explanation, translated.
     *
     * @since 1.0.0
     *
     * @return string The description in the current locale.
     */
    public function description(): string
    {
        return __( $this->description );
    }

    /**
     * Determines whether one slot holds more than one attendee.
     *
     * @since 1.0.0
     *
     * @return bool True when several bookings may share a slot.
     */
    public function allowsMultipleAttendees(): bool
    {
        return $this->allowsMultipleAttendees;
    }

    /**
     * Determines whether booking this type creates a series rather than a row.
     *
     * @since 1.0.0
     *
     * @return bool True when a booking expands into recurring occurrences.
     */
    public function isRecurring(): bool
    {
        return $this->isRecurring;
    }

    /**
     * Determines whether the provider is chosen for the customer.
     *
     * @since 1.0.0
     *
     * @return bool True when the package assigns the provider.
     */
    public function assignsProviderAutomatically(): bool
    {
        return $this->assignsProviderAutomatically;
    }
}
