<?php

/**
 * Calendar-sync throughput benchmark scenario.
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

use ArtisanPackUI\Bookings\Contracts\CalendarDriverRegistry;
use ArtisanPackUI\Bookings\Enums\CalendarSyncMode;
use ArtisanPackUI\Bookings\Jobs\SyncBookingToCalendars;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Carbon\CarbonImmutable;

/**
 * Registers a fake Google, seeds bookings, and times how fast they sync.
 *
 * This is the throughput half of issue #50: with Google faked in-process, the
 * number reported is how fast one worker drains the sync queue — the ceiling the
 * package imposes, before the queue driver and worker count that govern real
 * backpressure are chosen. Each pushed booking is run through the real
 * {@see SyncBookingToCalendars} job so the measured path is the orchestrator, the
 * ledger write, and the driver call a queued sync actually makes, not a shortcut
 * around them.
 *
 * The fake carries an optional per-call latency so a soak can model a realistic
 * calendar round-trip and watch throughput fall under it without ever touching
 * the network.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class CalendarSyncScenario
{
    /**
     * The fake driver in use, once one has been registered.
     *
     * @since 1.0.0
     *
     * @var FakeGoogleCalendarDriver|null
     */
    private ?FakeGoogleCalendarDriver $driver = null;

    /**
     * Constructs the scenario.
     *
     * @since 1.0.0
     *
     * @param  CalendarDriverRegistry  $registry  The registry the fake is added to.
     */
    public function __construct( private readonly CalendarDriverRegistry $registry )
    {
    }

    /**
     * Registers the fake Google driver the seeded connections will route to.
     *
     * @since 1.0.0
     *
     * @param  FakeGoogleCalendarDriver  $driver  The driver to register.
     *
     * @return void
     */
    public function useDriver( FakeGoogleCalendarDriver $driver ): void
    {
        $this->driver = $driver;

        $this->registry->register( $driver );
    }

    /**
     * Seeds providers with outbound Google connections and bookings to push.
     *
     * Each booking gets a distinct start time on its provider so the partial
     * unique index guarding a provider's slot never refuses the seed. Every
     * connection is outbound Google, which is what routes it to the fake.
     *
     * @since 1.0.0
     *
     * @param  int  $providerCount  How many providers to seed.
     * @param  int  $bookingsPerProvider  How many bookings each provider gets.
     *
     * @return array<int, array{booking: int, connection: int}> The (booking,
     *                                                          connection) pairs to push.
     */
    public function seed( int $providerCount, int $bookingsPerProvider ): array
    {
        $pairs = [];
        $start = CarbonImmutable::now()->addDay()->startOfDay()->setTime( 9, 0 );

        for ( $p = 0; $p < $providerCount; $p++ ) {
            $provider = ServiceProvider::factory()->create();

            $connection = CalendarConnection::factory()
                ->for( $provider, 'provider' )
                ->google()
                ->create( [ 'sync_mode' => CalendarSyncMode::Outbound, 'is_active' => true ] );

            for ( $b = 0; $b < $bookingsPerProvider; $b++ ) {
                $booking = Booking::factory()
                    ->for( $provider, 'provider' )
                    ->confirmed()
                    ->startingAt( $start->addMinutes( 30 * $b ) )
                    ->create();

                $pairs[] = [
                    'booking'    => (int) $booking->getKey(),
                    'connection' => (int) $connection->getKey(),
                ];
            }
        }

        return $pairs;
    }

    /**
     * Pushes every pair through the sync job and times the run.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{booking: int, connection: int}>  $pairs  The pairs to push.
     *
     * @return array{
     *     pushes: int,
     *     seconds: float,
     *     throughput: float,
     *     latency: array{count: int, min: float, max: float, mean: float, p50: float, p90: float, p95: float, p99: float},
     *     driver: array{create: int, update: int, delete: int, busy: int},
     *     events: int
     * } The measured summary, per-push latency in milliseconds and throughput in pushes per second.
     */
    public function measure( array $pairs ): array
    {
        $latencies = [];
        $wallStart = hrtime( true );

        foreach ( $pairs as $pair ) {
            $started = hrtime( true );

            SyncBookingToCalendars::dispatchSync( $pair['booking'], $pair['connection'] );

            $latencies[] = ( hrtime( true ) - $started ) / 1_000_000;
        }

        $seconds = ( hrtime( true ) - $wallStart ) / 1_000_000_000;
        $pushes  = count( $pairs );

        return [
            'pushes'     => $pushes,
            'seconds'    => $seconds,
            'throughput' => $seconds > 0 ? $pushes / $seconds : 0.0,
            'latency'    => Statistics::summarize( $latencies ),
            'driver'     => null === $this->driver ? [ 'create' => 0, 'update' => 0, 'delete' => 0, 'busy' => 0 ] : $this->driver->calls(),
            'events'     => null === $this->driver ? 0 : $this->driver->eventCount(),
        ];
    }
}
