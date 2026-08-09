<?php

/**
 * Service provider factory.
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

use ArtisanPackUI\Bookings\Models\AvailabilitySchedule;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use function fake;
use function now;

/**
 * Builds service providers.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<ServiceProvider>
 */
class ServiceProviderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ServiceProvider>
     */
    protected $model = ServiceProvider::class;

    /**
     * Defines the model's default state.
     *
     * The timezone is a real IANA name rather than an offset, and it is never
     * null: availability is authored as wall-clock time against it, so a
     * provider without a zone has schedules that mean nothing.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name'                         => $name,
            'slug'                         => Str::slug( $name ) . '-' . Str::lower( Str::random( 8 ) ),
            'email'                        => fake()->unique()->safeEmail(),
            'phone'                        => fake()->numerify( '+1-###-###-####' ),
            'bio'                          => fake()->paragraph(),
            'timezone'                     => fake()->randomElement( [
                'America/Chicago',
                'America/New_York',
                'America/Denver',
                'Europe/London',
            ] ),
            'round_robin_weight'           => 1,
            'round_robin_last_assigned_at' => null,
            'sort_order'                   => 0,
            'is_active'                    => true,
            'metadata'                     => null,
        ];
    }

    /**
     * Takes the provider out of the assignment pool.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function inactive(): static
    {
        return $this->state( fn (): array => [ 'is_active' => false ] );
    }

    /**
     * Pins the provider to a given timezone.
     *
     * @since 1.0.0
     *
     * @param  string  $timezone  The IANA timezone name.
     *
     * @return static The configured factory.
     */
    public function inTimezone( string $timezone ): static
    {
        return $this->state( fn (): array => [ 'timezone' => $timezone ] );
    }

    /**
     * Gives the provider a weekday nine-to-five.
     *
     * The single most common schedule there is, and the one most availability
     * tests want as their starting point.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function withWeekdaySchedule(): static
    {
        return $this->afterCreating( function ( ServiceProvider $provider ): void {
            foreach ( [ 1, 2, 3, 4, 5 ] as $dayOfWeek ) {
                AvailabilitySchedule::factory()
                    ->for( $provider, 'provider' )
                    ->nineToFive( $dayOfWeek )
                    ->create();
            }
        } );
    }

    /**
     * Marks the provider as having been assigned work at a given time.
     *
     * @since 1.0.0
     *
     * @param  string  $when  A relative or absolute time the assigner last picked them.
     *
     * @return static The configured factory.
     */
    public function lastAssigned( string $when = '-1 hour' ): static
    {
        return $this->state( fn (): array => [
            'round_robin_last_assigned_at' => Carbon::parse( $when ),
        ] );
    }

    /**
     * Marks the provider's personal data as erased.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function erased(): static
    {
        return $this->state( fn (): array => [ 'pii_erased_at' => now() ] );
    }
}
