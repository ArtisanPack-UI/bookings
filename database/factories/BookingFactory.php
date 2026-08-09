<?php

/**
 * Booking factory.
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

use ArtisanPackUI\Bookings\Enums\BookingAssignmentStrategy;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Carbon;

use function fake;
use function now;

/**
 * Builds bookings.
 *
 * Every booking gets its own service and provider by default. That is not the
 * most realistic seed data — a real installation has many bookings against few
 * providers — but it is the only default that never trips the partial unique
 * index guarding a provider's slot. Tests that want several bookings sharing a
 * provider say so with `->for( $provider, 'provider' )`, and at that point the
 * start times are their problem, which is the right place for the decision.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Booking>
     */
    protected $model = Booking::class;

    /**
     * Defines the model's default state.
     *
     * `booking_number` and `manage_token_hash` are left out on purpose: the
     * model mints both on create, and a factory that supplied its own would test
     * the factory rather than the model.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        $timezone = fake()->randomElement( [ 'America/Chicago', 'America/New_York', 'Europe/London' ] );
        $start    = Carbon::now()
            ->addDays( fake()->numberBetween( 1, 45 ) )
            ->setTime( fake()->numberBetween( 9, 16 ), fake()->randomElement( [ 0, 15, 30, 45 ] ) );

        return [
            'service_id'            => Service::factory(),
            'provider_id'           => ServiceProvider::factory(),
            'customer_name'         => fake()->name(),
            'customer_email'        => fake()->safeEmail(),
            'customer_phone'        => fake()->numerify( '+1-###-###-####' ),
            'customer_timezone'     => $timezone,
            'start_time'            => $start,
            'end_time'              => $start->copy()->addMinutes( 30 ),
            'status'                => BookingStatus::Confirmed,
            'assignment_strategy'   => BookingAssignmentStrategy::Customer,
            'intake_schema_version' => 1,
            'intake_data'           => [
                'goal'     => fake()->sentence(),
                'referrer' => fake()->randomElement( [ 'search', 'referral', 'social' ] ),
            ],
            'notes'                 => null,
        ];
    }

    /**
     * Leaves the booking awaiting confirmation.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function requested(): static
    {
        return $this->state( fn (): array => [ 'status' => BookingStatus::Requested ] );
    }

    /**
     * Confirms the booking.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function confirmed(): static
    {
        return $this->state( fn (): array => [ 'status' => BookingStatus::Confirmed ] );
    }

    /**
     * Cancels the booking, freeing the slot it was holding.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function cancelled(): static
    {
        return $this->state( fn (): array => [ 'status' => BookingStatus::Cancelled ] );
    }

    /**
     * Moves the booking into the past and marks it as done.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function completed(): static
    {
        return $this->state( function (): array {
            $start = Carbon::now()->subDays( fake()->numberBetween( 1, 30 ) )->setTime( 10, 0 );

            return [
                'status'     => BookingStatus::Completed,
                'start_time' => $start,
                'end_time'   => $start->copy()->addMinutes( 30 ),
            ];
        } );
    }

    /**
     * Leaves the booking unassigned.
     *
     * An unassigned booking holds nobody's slot, which is why the guard against
     * double-booking ignores these.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function unassigned(): static
    {
        return $this->state( fn (): array => [ 'provider_id' => null ] );
    }

    /**
     * Starts the booking at a given moment.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $start  When the booking begins.
     * @param  int  $durationMinutes  How long it runs for.
     *
     * @return static The configured factory.
     */
    public function startingAt( DateTimeInterface|string $start, int $durationMinutes = 30 ): static
    {
        $startsAt = Carbon::parse( $start );

        return $this->state( fn (): array => [
            'start_time' => $startsAt,
            'end_time'   => $startsAt->copy()->addMinutes( $durationMinutes ),
        ] );
    }

    /**
     * Makes the booking an occurrence of a recurring series.
     *
     * Given no series, one is created and the booking becomes its head — the
     * first occurrence, at the series' own start. The service, provider, and
     * customer are taken from the series rather than generated, because an
     * occurrence that books a different service from the rule that produced it
     * is not a series at all.
     *
     * The occurrence index comes from a sequence rather than from the database.
     * `->count( 4 )->forSeries()` builds all four instances before any of them
     * is stored, so a query for the highest index already used would answer the
     * same thing four times — and four occurrences sharing a provider and a
     * start time is precisely what the slot guard exists to refuse.
     *
     * Each occurrence is advanced by a week in the series' own timezone and then
     * converted to UTC, which is what keeps a 3pm series at 3pm local across a
     * daylight-saving change instead of drifting an hour.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries|null  $series  The series to attach to, or null to create one.
     * @param  int|null  $index  The first occurrence's position, or null for the next free one.
     *
     * @return static The configured factory.
     */
    public function forSeries( ?BookingSeries $series = null, ?int $index = null ): static
    {
        $series ??= BookingSeries::factory()->create();
        $first    = $index ?? $series->nextSeriesIndex();

        return $this
            ->state( fn (): array => [
                'series_id'         => $series->getKey(),
                'service_id'        => $series->service_id,
                'provider_id'       => $series->provider_id,
                'customer_name'     => $series->customer_name,
                'customer_email'    => $series->customer_email,
                'customer_phone'    => $series->customer_phone,
                'customer_timezone' => $series->dtstart_timezone,
            ] )
            ->sequence( function ( Sequence $sequence ) use ( $series, $first ): array {
                $position = $first + $sequence->index;
                $start    = $series->dtstart()->addWeeks( $position - 1 )->utc();

                return [
                    'series_index' => $position,
                    'start_time'   => $start,
                    'end_time'     => $start->copy()->addMinutes( 30 ),
                ];
            } );
    }

    /**
     * Detaches the occurrence from the rule that generated it.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function detachedFromSeries(): static
    {
        return $this->state( fn (): array => [ 'detached_from_series_at' => now() ] );
    }

    /**
     * Marks the booking's personal data as erased.
     *
     * Redacts rather than nulls, because the columns are NOT NULL and a booking
     * having had a customer is not a fact the schema stops asserting. The marker
     * is set in the same write, which is the whole contract — without it there
     * is nothing distinguishing a redacted row from a real one.
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
            'intake_data'    => null,
            'notes'          => null,
            'pii_erased_at'  => now(),
        ] );
    }
}
