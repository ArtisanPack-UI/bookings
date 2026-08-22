<?php

/**
 * Availability schedule factory.
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
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Builds recurring weekly availability.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<AvailabilitySchedule>
 */
class AvailabilityScheduleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<AvailabilitySchedule>
     */
    protected $model = AvailabilitySchedule::class;

    /**
     * Defines the model's default state.
     *
     * Times are written as bare `H:i` strings and stay that way. There is no
     * timezone here on purpose — the zone lives on the provider, and the point
     * of the column is that a nine o'clock start is nine o'clock whatever the
     * offset is doing that week.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        return [
            'provider_id'      => ServiceProvider::factory(),
            'day_of_week'      => fake()->numberBetween( 1, 5 ),
            'start_time_local' => '09:00',
            'end_time_local'   => '17:00',
            'effective_from'   => null,
            'effective_until'  => null,
            'is_available'     => true,
        ];
    }

    /**
     * Gives the provider a nine-to-five on a given weekday.
     *
     * @since 1.0.0
     *
     * @param  int  $dayOfWeek  The Sunday-indexed weekday, 0–6.
     *
     * @return static The configured factory.
     */
    public function nineToFive( int $dayOfWeek ): static
    {
        return $this->state( fn (): array => [
            'day_of_week'      => $dayOfWeek,
            'start_time_local' => '09:00',
            'end_time_local'   => '17:00',
            'is_available'     => true,
        ] );
    }

    /**
     * Sets the window this schedule opens and closes at.
     *
     * @since 1.0.0
     *
     * @param  string  $start  The local start time, as `H:i`.
     * @param  string  $end  The local end time, as `H:i`.
     *
     * @return static The configured factory.
     */
    public function between( string $start, string $end ): static
    {
        return $this->state( fn (): array => [
            'start_time_local' => $start,
            'end_time_local'   => $end,
        ] );
    }

    /**
     * Puts the schedule on a given weekday.
     *
     * @since 1.0.0
     *
     * @param  int  $dayOfWeek  The Sunday-indexed weekday, 0–6.
     *
     * @return static The configured factory.
     */
    public function onDayOfWeek( int $dayOfWeek ): static
    {
        return $this->state( fn (): array => [ 'day_of_week' => $dayOfWeek ] );
    }

    /**
     * Dates the schedule so it only applies inside a window.
     *
     * A null bound is open-ended in that direction — "from September, forever"
     * and "until the end of term" are both single-sided.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string|null  $from  The first day it applies.
     * @param  DateTimeInterface|string|null  $until  The last day it applies.
     *
     * @return static The configured factory.
     */
    public function effective(
        DateTimeInterface|string|null $from = null,
        DateTimeInterface|string|null $until = null,
    ): static {
        return $this->state( fn (): array => [
            'effective_from'  => null === $from ? null : Carbon::parse( $from )->startOfDay(),
            'effective_until' => null === $until ? null : Carbon::parse( $until )->startOfDay(),
        ] );
    }

    /**
     * Marks the window as one the provider is explicitly not available in.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function unavailable(): static
    {
        return $this->state( fn (): array => [ 'is_available' => false ] );
    }
}
