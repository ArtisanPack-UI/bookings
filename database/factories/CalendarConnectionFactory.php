<?php

/**
 * Calendar connection factory.
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

use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Enums\CalendarSyncMode;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

use function fake;
use function now;
use function sprintf;

/**
 * Builds connections to external calendars.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<CalendarConnection>
 */
class CalendarConnectionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<CalendarConnection>
     */
    protected $model = CalendarConnection::class;

    /**
     * Defines the model's default state.
     *
     * Outbound, matching the column default. Two-way sync hands an external
     * calendar the power to suppress availability, so it is opted into rather
     * than arrived at by accident — including here.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        return [
            'provider_id'               => ServiceProvider::factory(),
            'driver'                    => CalendarDriver::Google,
            'external_calendar_id'      => fake()->safeEmail(),
            'oauth_connection_id'       => null,
            'sync_mode'                 => CalendarSyncMode::Outbound,
            'sync_token'                => Str::random( 32 ),
            'last_sync_at'              => now()->subMinutes( fake()->numberBetween( 1, 240 ) ),
            'last_sync_error'           => null,
            'consecutive_failure_count' => 0,
            'is_active'                 => true,
        ];
    }

    /**
     * Connects a Google calendar.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function google(): static
    {
        return $this->state( fn (): array => [
            'driver'               => CalendarDriver::Google,
            'external_calendar_id' => fake()->safeEmail(),
        ] );
    }

    /**
     * Connects a Microsoft 365 calendar.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function microsoft(): static
    {
        return $this->state( fn (): array => [
            'driver'               => CalendarDriver::Microsoft,
            'external_calendar_id' => 'AAMkAD' . Str::random( 26 ),
        ] );
    }

    /**
     * Connects an Apple calendar over CalDAV.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function apple(): static
    {
        return $this->state( fn (): array => [
            'driver'               => CalendarDriver::Apple,
            'external_calendar_id' => sprintf( '/calendars/%s/home/', Str::lower( Str::random( 12 ) ) ),
        ] );
    }

    /**
     * Connects a plain iCal feed.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function ical(): static
    {
        return $this->state( fn (): array => [
            'driver'               => CalendarDriver::Ical,
            'external_calendar_id' => fake()->url() . '/feed.ics',
            'sync_token'           => null,
        ] );
    }

    /**
     * Lets the external calendar suppress availability.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function twoWay(): static
    {
        return $this->state( fn (): array => [ 'sync_mode' => CalendarSyncMode::TwoWay ] );
    }

    /**
     * Stops syncing the connection entirely.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function syncOff(): static
    {
        return $this->state( fn (): array => [ 'sync_mode' => CalendarSyncMode::Off ] );
    }

    /**
     * Puts the connection one failure away from being disabled.
     *
     * @since 1.0.0
     *
     * @param  int  $failures  How many consecutive failures it has had.
     *
     * @return static The configured factory.
     */
    public function failing( int $failures = 4 ): static
    {
        return $this->state( fn (): array => [
            'consecutive_failure_count' => $failures,
            'last_sync_error'           => 'The remote calendar returned 503.',
        ] );
    }

    /**
     * Presents the connection as already disabled.
     *
     * Writes the end state the transition leaves behind rather than calling
     * {@see CalendarConnection::disable()}, so that building a fixture does not
     * fire an event a listener would treat as news.
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
            'sync_token'  => null,
        ] );
    }
}
