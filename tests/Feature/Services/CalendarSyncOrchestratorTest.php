<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Contracts\CalendarSyncDriver;
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Enums\CalendarSyncMode;
use ArtisanPackUI\Bookings\Events\CalendarConnectionDisabled;
use ArtisanPackUI\Bookings\Events\CalendarSynced;
use ArtisanPackUI\Bookings\Events\CalendarSyncFailed;
use ArtisanPackUI\Bookings\Exceptions\CalendarSyncException;
use ArtisanPackUI\Bookings\Jobs\RemoveBookingFromCalendars;
use ArtisanPackUI\Bookings\Jobs\SyncBookingToCalendars;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\CalendarEvent;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\CalendarSyncOrchestrator;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.calendarSync.providers' );
    removeAllActions( 'ap.bookings.calendarSync.pushing' );
    removeAllActions( 'ap.bookings.calendarSync.connectionDisabled' );
} );

/**
 * Resolves the service under test.
 */
function calendarSyncOrchestrator(): CalendarSyncOrchestrator
{
    return app( CalendarSyncOrchestrator::class );
}

/**
 * Registers a driver so the registry resolves it, replacing any under its key.
 */
function registerCalendarDriver( CalendarSyncDriver $driver ): void
{
    addFilter(
        'ap.bookings.calendarSync.providers',
        static fn ( array $drivers ): array => array_merge( $drivers, [ $driver->driver()->value => $driver ] ),
    );
}

/**
 * Builds a driver that records its calls and answers with a fixed identifier.
 */
function recordingCalendarDriver(): CalendarSyncDriver
{
    return new class() implements CalendarSyncDriver {
        public array $created = [];

        public array $updated = [];

        public function driver(): CalendarDriver
        {
            return CalendarDriver::Google;
        }

        public function createEvent( CalendarConnection $connection, Booking $booking ): string
        {
            $this->created[] = $booking->getKey();

            return 'evt-created';
        }

        public function updateEvent( CalendarConnection $connection, Booking $booking, string $externalEventId ): string
        {
            $this->updated[] = $externalEventId;

            return $externalEventId;
        }

        public function deleteEvent( CalendarConnection $connection, string $externalEventId ): void
        {
        }

        public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array
        {
            return [];
        }
    };
}

/**
 * Builds a driver that records the events it is asked to delete.
 */
function deletingCalendarDriver(): CalendarSyncDriver
{
    return new class() implements CalendarSyncDriver {
        public array $deleted = [];

        public function driver(): CalendarDriver
        {
            return CalendarDriver::Google;
        }

        public function createEvent( CalendarConnection $connection, Booking $booking ): string
        {
            return 'evt-created';
        }

        public function updateEvent( CalendarConnection $connection, Booking $booking, string $externalEventId ): string
        {
            return $externalEventId;
        }

        public function deleteEvent( CalendarConnection $connection, string $externalEventId ): void
        {
            $this->deleted[] = $externalEventId;
        }

        public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array
        {
            return [];
        }
    };
}

/**
 * Builds a driver whose deletes always throw, counting the attempts it takes.
 */
function throwingDeleteCalendarDriver(): CalendarSyncDriver
{
    return new class() implements CalendarSyncDriver {
        public int $attempts = 0;

        public function driver(): CalendarDriver
        {
            return CalendarDriver::Google;
        }

        public function createEvent( CalendarConnection $connection, Booking $booking ): string
        {
            return 'evt-created';
        }

        public function updateEvent( CalendarConnection $connection, Booking $booking, string $externalEventId ): string
        {
            return $externalEventId;
        }

        public function deleteEvent( CalendarConnection $connection, string $externalEventId ): void
        {
            $this->attempts++;

            throw new CalendarSyncException( 'Google returned 503.' );
        }

        public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array
        {
            return [];
        }
    };
}

/**
 * Builds a driver whose writes always throw, counting the attempts it takes.
 */
function throwingCalendarDriver(): CalendarSyncDriver
{
    return new class() implements CalendarSyncDriver {
        public int $attempts = 0;

        public function driver(): CalendarDriver
        {
            return CalendarDriver::Google;
        }

        public function createEvent( CalendarConnection $connection, Booking $booking ): string
        {
            $this->attempts++;

            throw new CalendarSyncException( 'Google returned 503.' );
        }

        public function updateEvent( CalendarConnection $connection, Booking $booking, string $externalEventId ): string
        {
            $this->attempts++;

            throw new CalendarSyncException( 'Google returned 503.' );
        }

        public function deleteEvent( CalendarConnection $connection, string $externalEventId ): void
        {
        }

        public function busyPeriods( CalendarConnection $connection, TimeRange $window ): array
        {
            return [];
        }
    };
}

describe( 'dispatching a booking to its calendars', function (): void {
    beforeEach( function (): void {
        Queue::fake();
        registerCalendarDriver( recordingCalendarDriver() );
    } );

    it( 'queues one job per active, event-writing connection with a driver', function (): void {
        $provider = ServiceProvider::factory()->create();
        $booking  = Booking::factory()->for( $provider, 'provider' )->create();

        $outbound = CalendarConnection::factory()->google()->for( $provider, 'provider' )->create();
        CalendarConnection::factory()->google()->syncOff()->for( $provider, 'provider' )->create();
        CalendarConnection::factory()->google()->disabled()->for( $provider, 'provider' )->create();

        calendarSyncOrchestrator()->sync( $booking );

        Queue::assertPushed( SyncBookingToCalendars::class, 1 );
        Queue::assertPushed(
            SyncBookingToCalendars::class,
            static fn ( SyncBookingToCalendars $job ): bool => $job->bookingId === $booking->getKey()
                && $job->connectionId === $outbound->getKey(),
        );
    } );

    it( 'fires pushing once per connection with the booking and provider slug', function (): void {
        $provider = ServiceProvider::factory()->create();
        $booking  = Booking::factory()->for( $provider, 'provider' )->create();
        CalendarConnection::factory()->google()->for( $provider, 'provider' )->create();

        $seen = [];
        addAction(
            'ap.bookings.calendarSync.pushing',
            static function ( Booking $pushed, string $slug ) use ( &$seen ): void {
                $seen[] = [ $pushed->getKey(), $slug ];
            },
        );

        calendarSyncOrchestrator()->sync( $booking );

        expect( $seen )->toBe( [ [ $booking->getKey(), 'google' ] ] );
    } );

    it( 'skips connections whose driver is not installed', function (): void {
        removeAllFilters( 'ap.bookings.calendarSync.providers' );

        $provider = ServiceProvider::factory()->create();
        $booking  = Booking::factory()->for( $provider, 'provider' )->create();
        CalendarConnection::factory()->google()->for( $provider, 'provider' )->create();

        calendarSyncOrchestrator()->sync( $booking );

        Queue::assertNothingPushed();
    } );

    it( 'does nothing for a booking with no provider', function (): void {
        $booking = Booking::factory()->create( [ 'provider_id' => null ] );

        calendarSyncOrchestrator()->sync( $booking );

        Queue::assertNothingPushed();
    } );
} );

describe( 'pushing a booking through a connection', function (): void {
    beforeEach( function (): void {
        Event::fake( [ CalendarSynced::class, CalendarSyncFailed::class, CalendarConnectionDisabled::class ] );
    } );

    it( 'creates the event, records the ledger row, and fires synced', function (): void {
        registerCalendarDriver( recordingCalendarDriver() );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->for( $connection->provider, 'provider' )->create();

        calendarSyncOrchestrator()->push( $booking->getKey(), $connection->getKey() );

        $event = CalendarEvent::query()->sole();

        expect( $event->booking_id )->toBe( $booking->getKey() )
            ->and( $event->connection_id )->toBe( $connection->getKey() )
            ->and( $event->external_event_id )->toBe( 'evt-created' )
            ->and( $event->sync_error )->toBeNull();

        Event::assertDispatched(
            CalendarSynced::class,
            static fn ( CalendarSynced $e ): bool => 'evt-created' === $e->externalEventId
                && $e->booking->is( $booking ),
        );
    } );

    it( 'updates the recorded event rather than creating a second', function (): void {
        $driver = recordingCalendarDriver();
        registerCalendarDriver( $driver );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->for( $connection->provider, 'provider' )->create();
        CalendarEvent::factory()->create( [
            'booking_id'        => $booking->getKey(),
            'connection_id'     => $connection->getKey(),
            'external_event_id' => 'evt-existing',
        ] );

        calendarSyncOrchestrator()->push( $booking->getKey(), $connection->getKey() );

        expect( CalendarEvent::query()->count() )->toBe( 1 )
            ->and( $driver->updated )->toBe( [ 'evt-existing' ] )
            ->and( $driver->created )->toBe( [] );
    } );

    it( 'clears the failure streak on a successful sync', function (): void {
        registerCalendarDriver( recordingCalendarDriver() );

        $connection = CalendarConnection::factory()->google()->failing( 4 )->create();
        $booking    = Booking::factory()->for( $connection->provider, 'provider' )->create();

        calendarSyncOrchestrator()->push( $booking->getKey(), $connection->getKey() );

        $connection->refresh();

        expect( $connection->consecutive_failure_count )->toBe( 0 )
            ->and( $connection->last_sync_error )->toBeNull()
            ->and( $connection->last_sync_at )->not->toBeNull();
    } );

    it( 'fires failed and rethrows without counting the attempt', function (): void {
        registerCalendarDriver( throwingCalendarDriver() );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->for( $connection->provider, 'provider' )->create();

        expect( fn () => calendarSyncOrchestrator()->push( $booking->getKey(), $connection->getKey() ) )
            ->toThrow( CalendarSyncException::class );

        $connection->refresh();

        expect( $connection->consecutive_failure_count )->toBe( 0 )
            ->and( $connection->last_sync_error )->toBe( 'Google returned 503.' );

        Event::assertDispatched(
            CalendarSyncFailed::class,
            static fn ( CalendarSyncFailed $e ): bool => 'Google returned 503.' === $e->reason
                && $e->booking?->is( $booking ),
        );
    } );

    it( 'is a no-op for a disabled connection', function (): void {
        registerCalendarDriver( recordingCalendarDriver() );

        $connection = CalendarConnection::factory()->google()->disabled()->create();
        $booking    = Booking::factory()->for( $connection->provider, 'provider' )->create();

        calendarSyncOrchestrator()->push( $booking->getKey(), $connection->getKey() );

        expect( CalendarEvent::query()->count() )->toBe( 0 );
        Event::assertNotDispatched( CalendarSynced::class );
    } );
} );

describe( 'recording a spent push against a connection', function (): void {
    beforeEach( function (): void {
        Event::fake( [ CalendarConnectionDisabled::class ] );
    } );

    it( 'increments the consecutive failure count', function (): void {
        $connection = CalendarConnection::factory()->google()->create();

        calendarSyncOrchestrator()->recordFailure( $connection->getKey() );

        $connection->refresh();

        expect( $connection->consecutive_failure_count )->toBe( 1 )
            ->and( $connection->isDisabled() )->toBeFalse();
    } );

    it( 'disables the connection at the failure threshold', function (): void {
        $connection = CalendarConnection::factory()->google()->failing( 4 )->create();

        $reasons = [];
        addAction(
            'ap.bookings.calendarSync.connectionDisabled',
            static function ( CalendarConnection $disabled, string $reason ) use ( &$reasons ): void {
                $reasons[] = $reason;
            },
        );

        calendarSyncOrchestrator()->recordFailure( $connection->getKey() );

        $connection->refresh();

        expect( $connection->isDisabled() )->toBeTrue()
            ->and( $connection->is_active )->toBeFalse()
            ->and( $reasons )->toHaveCount( 1 )
            ->and( $reasons[0] )->toContain( '5 consecutive' );

        Event::assertDispatched( CalendarConnectionDisabled::class );
    } );

    it( 'downgrades a two-way connection that has been broken past the grace window', function (): void {
        $connection = CalendarConnection::factory()
            ->google()
            ->twoWay()
            ->create( [ 'last_sync_at' => Carbon::now()->subHours( 7 ) ] );

        $reasons = [];
        addAction(
            'ap.bookings.calendarSync.connectionDisabled',
            static function ( CalendarConnection $downgraded, string $reason ) use ( &$reasons ): void {
                $reasons[] = $reason;
            },
        );

        calendarSyncOrchestrator()->recordFailure( $connection->getKey() );

        $connection->refresh();

        expect( $connection->sync_mode )->toBe( CalendarSyncMode::Outbound )
            ->and( $connection->isDisabled() )->toBeFalse()
            ->and( $reasons )->toHaveCount( 1 )
            ->and( $reasons[0] )->toContain( 'downgraded to outbound' );

        Event::assertNotDispatched( CalendarConnectionDisabled::class );
    } );

    it( 'leaves a two-way connection alone while inside the grace window', function (): void {
        $connection = CalendarConnection::factory()
            ->google()
            ->twoWay()
            ->create( [ 'last_sync_at' => Carbon::now()->subHours( 1 ) ] );

        $fired = false;
        addAction(
            'ap.bookings.calendarSync.connectionDisabled',
            static function () use ( &$fired ): void {
                $fired = true;
            },
        );

        calendarSyncOrchestrator()->recordFailure( $connection->getKey() );

        $connection->refresh();

        expect( $connection->sync_mode )->toBe( CalendarSyncMode::TwoWay )
            ->and( $fired )->toBeFalse();
    } );
} );

describe( 'the pushing hook and the retry schedule', function (): void {
    it( 'fires pushing once for a logical push even when the write is retried', function (): void {
        Queue::fake();

        $driver = throwingCalendarDriver();
        registerCalendarDriver( $driver );

        $provider   = ServiceProvider::factory()->create();
        $booking    = Booking::factory()->for( $provider, 'provider' )->create();
        $connection = CalendarConnection::factory()->google()->for( $provider, 'provider' )->create();

        $count = 0;
        addAction(
            'ap.bookings.calendarSync.pushing',
            static function () use ( &$count ): void {
                $count++;
            },
        );

        calendarSyncOrchestrator()->sync( $booking );

        $job = new SyncBookingToCalendars( $booking->getKey(), $connection->getKey() );

        foreach ( range( 1, 3 ) as $ignored ) {
            try {
                $job->handle( calendarSyncOrchestrator() );
            } catch ( CalendarSyncException ) {
                // The retry the queue would make, made by hand.
            }
        }

        expect( $count )->toBe( 1 )
            ->and( $driver->attempts )->toBe( 3 );
    } );
} );

describe( 'unsyncing a booking from a former provider', function (): void {
    beforeEach( function (): void {
        Queue::fake();
        registerCalendarDriver( recordingCalendarDriver() );
    } );

    it( 'queues one removal per calendar the previous provider had the booking on', function (): void {
        $previous = ServiceProvider::factory()->create();
        $current  = ServiceProvider::factory()->create();

        $connection = CalendarConnection::factory()->google()->for( $previous, 'provider' )->create();

        $booking = Booking::factory()->for( $current, 'provider' )->create();
        CalendarEvent::factory()->create( [
            'booking_id'        => $booking->getKey(),
            'connection_id'     => $connection->getKey(),
            'external_event_id' => 'evt-previous',
        ] );

        calendarSyncOrchestrator()->unsync( $booking, $previous->getKey() );

        Queue::assertPushed( RemoveBookingFromCalendars::class, 1 );
        Queue::assertPushed(
            RemoveBookingFromCalendars::class,
            static fn ( RemoveBookingFromCalendars $job ): bool => $job->bookingId === $booking->getKey()
                && $job->connectionId === $connection->getKey(),
        );
    } );

    it( 'ignores calendars that belong to a different provider', function (): void {
        $previous = ServiceProvider::factory()->create();
        $other    = ServiceProvider::factory()->create();
        $current  = ServiceProvider::factory()->create();

        $previousConnection = CalendarConnection::factory()->google()->for( $previous, 'provider' )->create();
        $otherConnection    = CalendarConnection::factory()->google()->for( $other, 'provider' )->create();

        $booking = Booking::factory()->for( $current, 'provider' )->create();
        CalendarEvent::factory()->create( [
            'booking_id'    => $booking->getKey(),
            'connection_id' => $previousConnection->getKey(),
        ] );
        CalendarEvent::factory()->create( [
            'booking_id'    => $booking->getKey(),
            'connection_id' => $otherConnection->getKey(),
        ] );

        calendarSyncOrchestrator()->unsync( $booking, $previous->getKey() );

        Queue::assertPushed( RemoveBookingFromCalendars::class, 1 );
        Queue::assertPushed(
            RemoveBookingFromCalendars::class,
            static fn ( RemoveBookingFromCalendars $job ): bool => $job->connectionId === $previousConnection->getKey(),
        );
    } );

    it( 'queues nothing when the previous provider had the booking on no calendar', function (): void {
        $previous = ServiceProvider::factory()->create();
        $current  = ServiceProvider::factory()->create();

        $booking = Booking::factory()->for( $current, 'provider' )->create();

        calendarSyncOrchestrator()->unsync( $booking, $previous->getKey() );

        Queue::assertNothingPushed();
    } );
} );

describe( 'removing a booking from one connection', function (): void {
    it( 'deletes the external event and drops the ledger row', function (): void {
        $driver = deletingCalendarDriver();
        registerCalendarDriver( $driver );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->for( $connection->provider, 'provider' )->create();
        CalendarEvent::factory()->create( [
            'booking_id'        => $booking->getKey(),
            'connection_id'     => $connection->getKey(),
            'external_event_id' => 'evt-remove',
        ] );

        calendarSyncOrchestrator()->remove( $booking->getKey(), $connection->getKey() );

        expect( $driver->deleted )->toBe( [ 'evt-remove' ] )
            ->and( CalendarEvent::query()->count() )->toBe( 0 );
    } );

    it( 'is a no-op when there is no ledger row for the pair', function (): void {
        $driver = deletingCalendarDriver();
        registerCalendarDriver( $driver );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->for( $connection->provider, 'provider' )->create();

        calendarSyncOrchestrator()->remove( $booking->getKey(), $connection->getKey() );

        expect( $driver->deleted )->toBe( [] );
    } );

    it( 'is a no-op when the connection no longer exists', function (): void {
        $booking = Booking::factory()->create();

        calendarSyncOrchestrator()->remove( $booking->getKey(), 999999 );

        expect( CalendarEvent::query()->count() )->toBe( 0 );
    } );

    it( 'leaves the ledger row in place when the driver is not installed', function (): void {
        removeAllFilters( 'ap.bookings.calendarSync.providers' );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->for( $connection->provider, 'provider' )->create();
        CalendarEvent::factory()->create( [
            'booking_id'        => $booking->getKey(),
            'connection_id'     => $connection->getKey(),
            'external_event_id' => 'evt-orphan',
        ] );

        calendarSyncOrchestrator()->remove( $booking->getKey(), $connection->getKey() );

        expect( CalendarEvent::query()->count() )->toBe( 1 );
    } );

    it( 'keeps the ledger row when the delete fails, so a retry still has it', function (): void {
        $driver = throwingDeleteCalendarDriver();
        registerCalendarDriver( $driver );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->for( $connection->provider, 'provider' )->create();
        CalendarEvent::factory()->create( [
            'booking_id'        => $booking->getKey(),
            'connection_id'     => $connection->getKey(),
            'external_event_id' => 'evt-remove',
        ] );

        expect( fn () => calendarSyncOrchestrator()->remove( $booking->getKey(), $connection->getKey() ) )
            ->toThrow( CalendarSyncException::class );

        expect( CalendarEvent::query()->count() )->toBe( 1 )
            ->and( $driver->attempts )->toBe( 1 );
    } );
} );
