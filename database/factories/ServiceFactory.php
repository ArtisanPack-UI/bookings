<?php

/**
 * Service factory.
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

use ArtisanPackUI\Bookings\Enums\ServiceAssignmentStrategy;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds services.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Service>
     */
    protected $model = Service::class;

    /**
     * Defines the model's default state.
     *
     * The slug carries a random suffix rather than leaning on Faker's unique()
     * bookkeeping, because the constraint it has to satisfy is a database index
     * that outlives any one generator — and on a single-tenant installation,
     * where every `site_id` is null, that index is global.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        $name = fake()->randomElement( [
            'Initial Consultation',
            'Discovery Call',
            'Strategy Session',
            'Follow-up Appointment',
            'Portfolio Review',
        ] );

        return [
            'name'                  => $name,
            'slug'                  => Str::slug( $name ) . '-' . Str::lower( Str::random( 8 ) ),
            'description'           => fake()->sentence( 12 ),
            'duration'              => fake()->randomElement( [ 15, 30, 45, 60, 90 ] ),
            'buffer_before'         => 0,
            'buffer_after'          => fake()->randomElement( [ 0, 10, 15 ] ),
            'price'                 => fake()->randomElement( [ '0.00', '75.00', '150.00', '250.00' ] ),
            'is_free'               => false,
            'max_bookings_per_slot' => 1,
            'is_active'             => true,
            'intake_schema'         => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'textarea', 'label' => 'What would you like to cover?' ],
                    [ 'name' => 'referrer', 'type' => 'text', 'label' => 'How did you hear about us?' ],
                ],
            ],
            'intake_schema_version' => 1,
            'assignment_strategy'   => ServiceAssignmentStrategy::Any,
            'color'                 => fake()->hexColor(),
            'timezone'              => 'America/Chicago',
            'metadata'              => null,
        ];
    }

    /**
     * Makes the service free.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function free(): static
    {
        return $this->state( fn (): array => [
            'is_free' => true,
            'price'   => null,
        ] );
    }

    /**
     * Takes the service out of the booking widget.
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
     * Lets several customers share one slot, as a group class would.
     *
     * @since 1.0.0
     *
     * @param  int  $capacity  How many bookings a slot accepts.
     *
     * @return static The configured factory.
     */
    public function groupBooked( int $capacity = 8 ): static
    {
        return $this->state( fn (): array => [ 'max_bookings_per_slot' => $capacity ] );
    }

    /**
     * Rotates assignment across the service's providers.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function roundRobin(): static
    {
        return $this->state( fn (): array => [
            'assignment_strategy' => ServiceAssignmentStrategy::RoundRobin,
        ] );
    }

    /**
     * Attaches providers who offer the service.
     *
     * @since 1.0.0
     *
     * @param  int  $count  How many providers to attach.
     *
     * @return static The configured factory.
     */
    public function withProviders( int $count = 2 ): static
    {
        return $this->afterCreating( function ( Service $service ) use ( $count ): void {
            $service->providers()->attach(
                ServiceProvider::factory()->count( $count )->create()->modelKeys(),
            );
        } );
    }

    /**
     * Marks the service's personal data as erased.
     *
     * `pii_erased_at` is outside the model's `$fillable` — only the erasure
     * routine has business setting it — and reachable here because factories
     * build their models unguarded.
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
