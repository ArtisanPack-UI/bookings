<?php

/**
 * Availability benchmark scenario.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Benchmarks;

use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\AvailabilityService;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;

/**
 * Seeds a service and times how long its availability takes to resolve.
 *
 * The question issue #50 asks — availability p95 under 200ms warm for five
 * providers across ninety days at fifteen-minute intervals — is answered here at
 * the {@see AvailabilityService::resolve()} seam rather than over HTTP, so the
 * number reported is the resolver's own cost with the controller, router, and
 * JSON serialisation taken out of it. The window is resolved whole rather than a
 * month at a time, which is the heavier read and the one the target is written
 * against.
 *
 * Cold and warm are the same call under two cache states: cold bumps the
 * availability cache out of reach before each timed resolve so every day is
 * recomputed from the database, warm resolves the populated window so every day
 * is a cache hit. The gap between the two is what the cache is worth.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class AvailabilityScenario
{
    /**
     * Constructs the scenario.
     *
     * @since 1.0.0
     *
     * @param  AvailabilityService  $availability  The resolver under test.
     */
    public function __construct( private readonly AvailabilityService $availability )
    {
    }

    /**
     * Seeds a service offered by several providers, each on a weekday schedule.
     *
     * Every provider shares one timezone so the window covers the same local days
     * for all of them, which is the dense case — five providers all open on the
     * same ninety days — rather than the cheaper one where their weekends fall
     * apart.
     *
     * @since 1.0.0
     *
     * @param  int  $providerCount  How many providers offer the service.
     * @param  string  $timezone  The IANA timezone the providers work in.
     *
     * @return Service The seeded service.
     */
    public function seed( int $providerCount, string $timezone = 'America/Chicago' ): Service
    {
        // Duration and buffers are pinned rather than taken from the factory's
        // random defaults, so two runs measure the same scenario — 30-minute
        // appointments on the 15-minute interval — and a change in the timing is
        // the resolver's, not the seed's.
        $service = Service::factory()->create( [
            'timezone'      => $timezone,
            'duration'      => 30,
            'buffer_before' => 0,
            'buffer_after'  => 0,
        ] );

        $providers = ServiceProvider::factory()
            ->count( $providerCount )
            ->inTimezone( $timezone )
            ->withWeekdaySchedule()
            ->create();

        $service->providers()->attach( $providers->modelKeys() );

        return $service;
    }

    /**
     * Times the resolve over cold and warm caches.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service to resolve availability for.
     * @param  int  $days  How many days the window spans.
     * @param  int  $coldIterations  How many cold resolves to time.
     * @param  int  $warmIterations  How many warm resolves to time.
     *
     * @return array{
     *     days: int,
     *     providers: int,
     *     slots: int,
     *     cold: array{count: int, min: float, max: float, mean: float, p50: float, p90: float, p95: float, p99: float},
     *     warm: array{count: int, min: float, max: float, mean: float, p50: float, p90: float, p95: float, p99: float}
     * } The measured summary, timings in milliseconds.
     */
    public function measure( Service $service, int $days, int $coldIterations, int $warmIterations ): array
    {
        $window = $this->window( $service->timezone, $days );

        $cold = [];

        for ( $i = 0; $i < $coldIterations; $i++ ) {
            // Bump every day this service could have cached out of reach, so the
            // resolve that follows recomputes each one from the database — the
            // cold path the target's cold-cache number is about.
            $this->availability->invalidateEverything();

            $cold[] = $this->time( $service, $window );
        }

        // Populate the window once so the timed warm resolves are all cache hits.
        $slots = $this->availability->resolve( $service, null, $window );

        $warm = [];

        for ( $i = 0; $i < $warmIterations; $i++ ) {
            $warm[] = $this->time( $service, $window );
        }

        return [
            'days'      => $days,
            'providers' => $service->providers()->count(),
            'slots'     => count( $slots ),
            'cold'      => Statistics::summarize( $cold ),
            'warm'      => Statistics::summarize( $warm ),
        ];
    }

    /**
     * Resolves the window once and returns how long it took, in milliseconds.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service to resolve.
     * @param  TimeRange  $window  The window to resolve over.
     *
     * @return float The elapsed time in milliseconds.
     */
    private function time( Service $service, TimeRange $window ): float
    {
        $started = hrtime( true );

        $this->availability->resolve( $service, null, $window );

        return ( hrtime( true ) - $started ) / 1_000_000;
    }

    /**
     * Builds the window to resolve over, starting at the next local midnight.
     *
     * @since 1.0.0
     *
     * @param  string  $timezone  The provider timezone the window is anchored in.
     * @param  int  $days  How many days it spans.
     *
     * @return TimeRange The window.
     */
    private function window( string $timezone, int $days ): TimeRange
    {
        $start = CarbonImmutable::now( $timezone )->addDay()->startOfDay();

        return new TimeRange( $start, $start->addDays( $days ) );
    }
}
