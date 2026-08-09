<?php

/**
 * Webhook factory.
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

use ArtisanPackUI\Bookings\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

use function fake;
use function now;

/**
 * Builds outbound webhook endpoints.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Webhook>
     */
    protected $model = Webhook::class;

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
            'name'                      => fake()->company() . ' integration',
            'url'                       => 'https://' . fake()->domainName() . '/hooks/bookings',
            'secret'                    => Str::random( 40 ),
            'events'                    => [ 'booking.created', 'booking.cancelled' ],
            'is_active'                 => true,
            'consecutive_failure_count' => 0,
            'last_success_at'           => now()->subHours( 2 ),
        ];
    }

    /**
     * Subscribes the endpoint to a given set of events.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $events  The event names to subscribe to.
     *
     * @return static The configured factory.
     */
    public function subscribedTo( array $events ): static
    {
        return $this->state( fn (): array => [ 'events' => $events ] );
    }

    /**
     * Puts the endpoint on a failure streak.
     *
     * @since 1.0.0
     *
     * @param  int  $failures  How many consecutive failures it has had.
     *
     * @return static The configured factory.
     */
    public function failing( int $failures = 9 ): static
    {
        return $this->state( fn (): array => [
            'consecutive_failure_count' => $failures,
            'last_success_at'           => now()->subDays( 3 ),
        ] );
    }

    /**
     * Presents the endpoint as already disabled.
     *
     * Writes the end state the transition leaves behind rather than calling
     * {@see Webhook::disable()}, so that building a fixture does not fire an
     * event a listener would treat as news.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function disabled(): static
    {
        return $this->state( fn (): array => [
            'disabled_at' => now(),
            'is_active'   => false,
        ] );
    }
}
