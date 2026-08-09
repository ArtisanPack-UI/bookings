<?php

/**
 * Availability override factory.
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

use ArtisanPackUI\Bookings\Enums\AvailabilityOverrideType;
use ArtisanPackUI\Bookings\Models\AvailabilityOverride;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

use function fake;

/**
 * Builds single-date exceptions to a provider's weekly schedule.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<AvailabilityOverride>
 */
class AvailabilityOverrideFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<AvailabilityOverride>
     */
    protected $model = AvailabilityOverride::class;

    /**
     * Defines the model's default state.
     *
     * A day off, which is the common case — the times are null because there is
     * no window to describe.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        return [
            'provider_id'      => ServiceProvider::factory(),
            'date'             => Carbon::now()->addDays( fake()->numberBetween( 1, 60 ) )->startOfDay(),
            'type'             => AvailabilityOverrideType::Unavailable,
            'start_time_local' => null,
            'end_time_local'   => null,
            'reason'           => fake()->randomElement( [
                'Annual leave',
                'Doctor appointment',
                'Conference',
                'Personal day',
            ] ),
        ];
    }

    /**
     * Closes the whole day.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function unavailable(): static
    {
        return $this->state( fn (): array => [
            'type'             => AvailabilityOverrideType::Unavailable,
            'start_time_local' => null,
            'end_time_local'   => null,
        ] );
    }

    /**
     * Replaces the day's usual hours with a shorter window.
     *
     * @since 1.0.0
     *
     * @param  string  $start  The local start time, as `H:i`.
     * @param  string  $end  The local end time, as `H:i`.
     *
     * @return static The configured factory.
     */
    public function customHours( string $start = '12:00', string $end = '16:00' ): static
    {
        return $this->state( fn (): array => [
            'type'             => AvailabilityOverrideType::CustomHours,
            'start_time_local' => $start,
            'end_time_local'   => $end,
        ] );
    }

    /**
     * Puts the exception on a given date.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $date  The day the exception falls on.
     *
     * @return static The configured factory.
     */
    public function onDate( DateTimeInterface|string $date ): static
    {
        return $this->state( fn (): array => [ 'date' => Carbon::parse( $date )->startOfDay() ] );
    }
}
