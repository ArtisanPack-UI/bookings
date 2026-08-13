<?php

/**
 * Calendar busy block factory.
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

use ArtisanPackUI\Bookings\Models\CalendarBusyBlock;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Builds spans of external busy time.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<CalendarBusyBlock>
 */
class CalendarBusyBlockFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<CalendarBusyBlock>
     */
    protected $model = CalendarBusyBlock::class;

    /**
     * Defines the model's default state.
     *
     * The connection is two-way, because a busy block from a connection that
     * only pushes outward could never have been read in the first place.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        $startsAt = Carbon::now()
            ->utc()
            ->addDays( fake()->numberBetween( 1, 30 ) )
            ->setTime( fake()->numberBetween( 8, 17 ), fake()->randomElement( [ 0, 30 ] ) );

        return [
            'connection_id'     => CalendarConnection::factory()->twoWay(),
            'external_event_id' => Str::lower( Str::random( 26 ) ),
            'starts_at_utc'     => $startsAt,
            'ends_at_utc'       => $startsAt->copy()->addMinutes( fake()->randomElement( [ 30, 60, 90 ] ) ),
            'etag'              => '"' . Str::random( 16 ) . '"',
        ];
    }

    /**
     * Blocks a specific span.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $start  When the block opens, in UTC.
     * @param  DateTimeInterface|string  $end  When the block closes, in UTC.
     *
     * @return static The configured factory.
     */
    public function spanning( DateTimeInterface|string $start, DateTimeInterface|string $end ): static
    {
        // Read as UTC, matching the columns. A bare string would otherwise be
        // interpreted in the application's timezone and the fixture would
        // describe a different window from the one the test names.
        return $this->state( fn (): array => [
            'starts_at_utc' => Carbon::parse( $start, 'UTC' ),
            'ends_at_utc'   => Carbon::parse( $end, 'UTC' ),
        ] );
    }

    /**
     * Blocks a whole day.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $date  The day to block, in UTC.
     *
     * @return static The configured factory.
     */
    public function allDay( DateTimeInterface|string $date ): static
    {
        $day = Carbon::parse( $date, 'UTC' )->startOfDay();

        return $this->state( fn (): array => [
            'starts_at_utc' => $day,
            'ends_at_utc'   => $day->copy()->addDay(),
        ] );
    }
}
