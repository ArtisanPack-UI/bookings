<?php

/**
 * Meeting type registry.
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
use ArtisanPackUI\Bookings\Contracts\MeetingTypeRegistry as MeetingTypeRegistryContract;
use UnexpectedValueException;

use function applyFilters;
use function get_debug_type;
use function is_array;
use function sprintf;

/**
 * The registry the package ships, backed by the extension filter.
 *
 * The four built-in types are seeded on construction and are ordinary entries,
 * not special cases: an application can replace any of them by registering its
 * own type under the same key, and the rest of the package cannot tell the
 * difference.
 *
 * Keys are snake_case rather than the `1:1` / `round-robin` spellings the plan
 * uses in prose, because a key is a stored value and every other stored
 * identifier in the package is snake_case. The prose names map to
 * `one_to_one`, `group`, `recurring`, and `round_robin`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class MeetingTypeRegistry implements MeetingTypeRegistryContract
{
    /**
     * The types registered in PHP, before the filter runs.
     *
     * @since 1.0.0
     *
     * @var array<string, MeetingType>
     */
    protected array $types = [];

    /**
     * Constructs the registry with the built-in types.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        foreach ( self::defaults() as $type ) {
            $this->register( $type );
        }
    }

    /**
     * Gets the meeting types the package ships with.
     *
     * The strings are passed untranslated. {@see RegisteredMeetingType} runs
     * them through `__()` when they are read, which is what keeps this
     * singleton's labels following the current locale rather than freezing at
     * whichever one was active when it was first resolved.
     *
     * @since 1.0.0
     *
     * @return array<int, MeetingType> The built-in types.
     */
    public static function defaults(): array
    {
        return [
            new RegisteredMeetingType(
                'one_to_one',
                'One-to-one',
                'A single customer meets a single provider. The slot is theirs alone.',
            ),
            new RegisteredMeetingType(
                'group',
                'Group',
                'Several customers book the same slot with one provider, up to the service capacity.',
                allowsMultipleAttendees: true,
            ),
            new RegisteredMeetingType(
                'recurring',
                'Recurring',
                'One booking creates a repeating series from a recurrence rule.',
                isRecurring: true,
            ),
            new RegisteredMeetingType(
                'round_robin',
                'Round-robin',
                'The customer picks a time and the provider is assigned for them.',
                assignsProviderAutomatically: true,
            ),
        ];
    }

    /**
     * Adds a type to the registry.
     *
     * @since 1.0.0
     *
     * @param  MeetingType  $type  The type to add.
     *
     * @return void
     */
    public function register( MeetingType $type ): void
    {
        $this->types[ $type->key() ] = $type;
    }

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
     *                                  meeting types. Reading the registry runs
     *                                  the filter, so even this call can fail on
     *                                  a third party's bad subscriber.
     *
     * @return bool True when the key resolves to a type.
     */
    public function has( string $key ): bool
    {
        return null !== $this->get( $key );
    }

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
     * @return MeetingType|null The type, or null when nothing is registered.
     */
    public function get( string $key ): ?MeetingType
    {
        return $this->all()[ $key ] ?? null;
    }

    /**
     * Gets every registered type, keyed by identifier.
     *
     * The `ap.bookings.registeredMeetingTypes` filter runs here, on every call,
     * rather than once at boot. A package registering its type from its own
     * service provider has no way of knowing whether it boots before or after
     * this one, and filtering late means it does not have to.
     *
     * The filtered result is re-keyed from each type's own key, so a subscriber
     * that appends with `$types[] = $type` gets the same outcome as one that
     * assigns to a key — and cannot register a type under a key that disagrees
     * with the one the type reports.
     *
     * @since 1.0.0
     *
     * @throws UnexpectedValueException When a subscriber returns something other
     *                                  than an array of meeting types.
     *
     * @return array<string, MeetingType> The registered types.
     */
    public function all(): array
    {
        $filtered = applyFilters( 'ap.bookings.registeredMeetingTypes', $this->types );

        if ( ! is_array( $filtered ) ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.registeredMeetingTypes must return an array, got %s.',
                get_debug_type( $filtered ),
            ) );
        }

        $types = [];

        foreach ( $filtered as $type ) {
            if ( ! $type instanceof MeetingType ) {
                throw new UnexpectedValueException( sprintf(
                    'ap.bookings.registeredMeetingTypes must return %s instances, got %s.',
                    MeetingType::class,
                    get_debug_type( $type ),
                ) );
            }

            $types[ $type->key() ] = $type;
        }

        return $types;
    }
}
