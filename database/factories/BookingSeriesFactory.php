<?php

/**
 * Booking series factory.
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
use ArtisanPackUI\Bookings\Models\BookingSeries;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Builds recurring booking series.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<BookingSeries>
 */
class BookingSeriesFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<BookingSeries>
     */
    protected $model = BookingSeries::class;

    /**
     * Defines the model's default state.
     *
     * `dtstart_local` is a floating start — a clock face, not an instant — and
     * is deliberately written without any timezone conversion. The zone it is
     * read in lives in `dtstart_timezone`, and pairing the two is what makes the
     * rule re-materialisable rather than merely repeatable.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        $occurrences = fake()->numberBetween( 4, 12 );

        return [
            'service_id'            => Service::factory(),
            'provider_id'           => ServiceProvider::factory(),
            'customer_name'         => fake()->name(),
            'customer_email'        => fake()->safeEmail(),
            'customer_phone'        => fake()->numerify( '+1-###-###-####' ),
            'rrule'                 => sprintf( 'FREQ=WEEKLY;COUNT=%d', $occurrences ),
            'dtstart_local'         => Carbon::now()->addDays( 7 )->setTime( 15, 0 ),
            'dtstart_timezone'      => 'America/Chicago',
            'until_local'           => null,
            'occurrence_count'      => $occurrences,
            'intake_schema_version' => 1,
            'cancelled_at'          => null,
            'metadata'              => null,
        ];
    }

    /**
     * Starts the series at a given local clock face in a given zone.
     *
     * @since 1.0.0
     *
     * @param  string  $localDateTime  The floating start, as `Y-m-d H:i`.
     * @param  string  $timezone  The IANA zone the clock face is read in.
     *
     * @return static The configured factory.
     */
    public function startingLocally( string $localDateTime, string $timezone ): static
    {
        return $this->state( fn (): array => [
            'dtstart_local'    => Carbon::parse( $localDateTime ),
            'dtstart_timezone' => $timezone,
        ] );
    }

    /**
     * Bounds the series by an end date instead of an occurrence count.
     *
     * @since 1.0.0
     *
     * @param  string  $untilLocal  The floating end, as `Y-m-d H:i`.
     *
     * @return static The configured factory.
     */
    public function untilLocal( string $untilLocal ): static
    {
        return $this->state( fn (): array => [
            'rrule'            => 'FREQ=WEEKLY',
            'until_local'      => Carbon::parse( $untilLocal ),
            'occurrence_count' => null,
        ] );
    }

    /**
     * Cancels the rule.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function cancelled(): static
    {
        return $this->state( fn (): array => [ 'cancelled_at' => now() ] );
    }

    /**
     * Materialises the series' occurrences.
     *
     * A straight fan-out of the weekly rule, which is all a factory needs and
     * all it should claim to be. Expanding an arbitrary RRULE — BYDAY, skipped
     * months, the occurrence cap — belongs to the series service, and this state
     * should delegate to it once that lands rather than growing a second,
     * quietly divergent implementation of the same rules.
     *
     * @since 1.0.0
     *
     * @param  int|null  $count  How many occurrences to create, or null to use
     *                           the series' own `occurrence_count`.
     *
     * @return static The configured factory.
     */
    public function withOccurrences( ?int $count = null ): static
    {
        return $this->afterCreating( function ( BookingSeries $series ) use ( $count ): void {
            Booking::factory()
                ->count( $count ?? $series->occurrence_count ?? 4 )
                ->forSeries( $series )
                ->create();
        } );
    }

    /**
     * Marks the series' personal data as erased.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function erased(): static
    {
        return $this->state( fn (): array => [
            'customer_name'  => '[erased]',
            'customer_email' => 'erased@example.invalid',
            'customer_phone' => null,
            'pii_erased_at'  => now(),
        ] );
    }
}
