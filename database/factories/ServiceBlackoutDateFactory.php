<?php

/**
 * Service blackout date factory.
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

use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceBlackoutDate;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

use function fake;

/**
 * Builds service blackout dates.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<ServiceBlackoutDate>
 */
class ServiceBlackoutDateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ServiceBlackoutDate>
     */
    protected $model = ServiceBlackoutDate::class;

    /**
     * Defines the model's default state.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        $startsOn = Carbon::now()->addDays( fake()->numberBetween( 7, 90 ) )->startOfDay();

        return [
            'service_id' => Service::factory(),
            'starts_on'  => $startsOn,
            'ends_on'    => $startsOn->copy()->addDays( fake()->numberBetween( 0, 4 ) ),
            'reason'     => fake()->randomElement( [
                'Public holiday',
                'Annual leave',
                'Office closed',
                'Training day',
            ] ),
        ];
    }

    /**
     * Closes every service on the site rather than one of them.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function siteWide(): static
    {
        return $this->state( fn (): array => [ 'service_id' => null ] );
    }

    /**
     * Closes a single named day.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $date  The day to close.
     *
     * @return static The configured factory.
     */
    public function onDate( DateTimeInterface|string $date ): static
    {
        $day = Carbon::parse( $date )->startOfDay();

        return $this->state( fn (): array => [
            'starts_on' => $day,
            'ends_on'   => $day,
        ] );
    }
}
