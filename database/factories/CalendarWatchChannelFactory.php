<?php

/**
 * Calendar watch channel factory.
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

use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\CalendarWatchChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds push notification registrations.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<CalendarWatchChannel>
 */
class CalendarWatchChannelFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<CalendarWatchChannel>
     */
    protected $model = CalendarWatchChannel::class;

    /**
     * Defines the model's default state.
     *
     * A Google watch channel: a channel and resource id, and no subscription id.
     * Both id columns are unique and nullable, so the two vendors' shapes coexist
     * only because the one that does not apply is left null.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        return [
            'connection_id'   => CalendarConnection::factory()->google(),
            'channel_id'      => Str::uuid()->toString(),
            'resource_id'     => Str::random( 24 ),
            'subscription_id' => null,
            'expires_at'      => now()->addDays( 7 ),
        ];
    }

    /**
     * Registers the Microsoft 365 shape instead.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function microsoft(): static
    {
        return $this->state( fn (): array => [
            'connection_id'   => CalendarConnection::factory()->microsoft(),
            'channel_id'      => null,
            'resource_id'     => null,
            'subscription_id' => Str::uuid()->toString(),
            'expires_at'      => now()->addDays( 3 ),
        ] );
    }

    /**
     * Lets the registration lapse.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function expired(): static
    {
        return $this->state( fn (): array => [ 'expires_at' => now()->subHours( 2 ) ] );
    }

    /**
     * Puts the registration inside the renewal window.
     *
     * @since 1.0.0
     *
     * @param  int  $hours  How long it has left.
     *
     * @return static The configured factory.
     */
    public function expiringIn( int $hours = 6 ): static
    {
        return $this->state( fn (): array => [ 'expires_at' => now()->addHours( $hours ) ] );
    }
}
