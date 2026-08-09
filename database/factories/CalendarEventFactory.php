<?php

/**
 * Calendar event factory.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Database\Factories;

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\CalendarEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

use function fake;
use function now;

/**
 * Builds outbound sync ledger entries.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<CalendarEvent>
     */
    protected $model = CalendarEvent::class;

    /**
     * Defines the model's default state.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        return [
            'booking_id'        => Booking::factory(),
            'connection_id'     => CalendarConnection::factory(),
            'external_event_id' => Str::lower( Str::random( 26 ) ),
            'etag'              => '"' . Str::random( 16 ) . '"',
            'last_synced_at'    => now()->subMinutes( fake()->numberBetween( 1, 120 ) ),
            'sync_error'        => null,
        ];
    }

    /**
     * Records that the last push to the calendar failed.
     *
     * @since 1.0.0
     *
     * @param  string  $error  What the calendar said.
     *
     * @return static The configured factory.
     */
    public function failing( string $error = 'The remote calendar returned 410 Gone.' ): static
    {
        return $this->state( fn (): array => [ 'sync_error' => $error ] );
    }

    /**
     * Presents the event as never having been pushed.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function neverSynced(): static
    {
        return $this->state( fn (): array => [
            'last_synced_at' => null,
            'etag'           => null,
        ] );
    }
}
