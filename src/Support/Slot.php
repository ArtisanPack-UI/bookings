<?php

/**
 * Bookable slot value object.
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

use JsonSerializable;

/**
 * One span of time a customer can book, and who would serve it.
 *
 * A slot is a candidate, not a reservation. It says a provider looks free for a
 * span of time according to the availability rules; whether a booking can
 * actually be written into it is settled by the unique index on `bookings`,
 * which is the only thing that can settle it without a race.
 *
 * The provider is nullable because a service using round-robin assignment
 * produces slots before anybody is picked for them — see
 * {@see \ArtisanPackUI\Bookings\Contracts\RoundRobinStrategy}, which is what
 * fills it in.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final readonly class Slot implements JsonSerializable
{
    /**
     * Constructs the slot.
     *
     * @since 1.0.0
     *
     * @param  TimeRange  $period  The span of time the slot occupies.
     * @param  int|null  $providerId  The provider who would serve it, when one is
     *                                already known.
     */
    public function __construct(
        public TimeRange $period,
        public ?int $providerId = null,
    ) {
    }

    /**
     * Gets a copy of the slot assigned to a provider.
     *
     * @since 1.0.0
     *
     * @param  int|null  $providerId  The provider to assign the slot to.
     *
     * @return self A new slot over the same period.
     */
    public function forProvider( ?int $providerId ): self
    {
        return new self( $this->period, $providerId );
    }

    /**
     * Gets the slot as an array.
     *
     * @since 1.0.0
     *
     * @return array{start: string, end: string, provider_id: int|null} The slot.
     */
    public function toArray(): array
    {
        return $this->period->toArray() + [ 'provider_id' => $this->providerId ];
    }

    /**
     * Gets the data that should be serialized to JSON.
     *
     * @since 1.0.0
     *
     * @return array{start: string, end: string, provider_id: int|null} The slot.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
