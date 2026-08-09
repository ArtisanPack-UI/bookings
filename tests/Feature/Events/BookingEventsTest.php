<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\SeriesEditScope;
use ArtisanPackUI\Bookings\Events\BookingCancelled;
use ArtisanPackUI\Bookings\Events\BookingCompleted;
use ArtisanPackUI\Bookings\Events\BookingConfirmed;
use ArtisanPackUI\Bookings\Events\BookingNoShow;
use ArtisanPackUI\Bookings\Events\BookingRequested;
use ArtisanPackUI\Bookings\Events\BookingRescheduled;
use ArtisanPackUI\Bookings\Events\CalendarConnectionDisabled;
use ArtisanPackUI\Bookings\Events\CalendarSynced;
use ArtisanPackUI\Bookings\Events\CalendarSyncFailed;
use ArtisanPackUI\Bookings\Events\SeriesCancelled;
use ArtisanPackUI\Bookings\Events\SeriesCreated;
use ArtisanPackUI\Bookings\Events\SeriesEdited;
use ArtisanPackUI\Bookings\Events\WebhookDisabled;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\Webhook;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * Builds one of every event, each with a payload worth asserting on.
 *
 * A file-local closure rather than a global function: Pest loads every test
 * file into one process, so a global would be a redeclaration fatal the day
 * another file picks the same name. It is also not a dataset — datasets resolve
 * while Pest is collecting tests, long before any migration has run, and these
 * need rows.
 *
 * @var callable(): array<string, array{0: object, 1: callable(object): void}>
 */
$bookingEventCases = function (): array {
    $booking    = Booking::factory()->create();
    $series     = BookingSeries::factory()->create();
    $connection = CalendarConnection::factory()->create();
    $webhook    = Webhook::factory()->create();

    $previousPeriod = new TimeRange(
        Carbon::parse( '2026-03-01 09:00:00', 'UTC' ),
        Carbon::parse( '2026-03-01 10:00:00', 'UTC' ),
    );

    return [
        'BookingRequested' => [
            new BookingRequested( $booking ),
            function ( BookingRequested $event ) use ( $booking ): void {
                expect( $event->booking->id )->toBe( $booking->id );
            },
        ],
        'BookingConfirmed' => [
            new BookingConfirmed( $booking, BookingActor::Admin ),
            function ( BookingConfirmed $event ) use ( $booking ): void {
                expect( $event->booking->id )->toBe( $booking->id )
                    ->and( $event->actor )->toBe( BookingActor::Admin );
            },
        ],
        'BookingRescheduled' => [
            new BookingRescheduled( $booking, $previousPeriod, BookingActor::Customer ),
            function ( BookingRescheduled $event ) use ( $booking, $previousPeriod ): void {
                expect( $event->booking->id )->toBe( $booking->id )
                    ->and( $event->previousPeriod->equals( $previousPeriod ) )->toBeTrue()
                    ->and( $event->actor )->toBe( BookingActor::Customer );
            },
        ],
        'BookingCancelled' => [
            new BookingCancelled( $booking, BookingActor::Provider, 'Provider is ill.' ),
            function ( BookingCancelled $event ) use ( $booking ): void {
                expect( $event->booking->id )->toBe( $booking->id )
                    ->and( $event->actor )->toBe( BookingActor::Provider )
                    ->and( $event->reason )->toBe( 'Provider is ill.' );
            },
        ],
        'BookingCompleted' => [
            new BookingCompleted( $booking, BookingActor::Provider ),
            function ( BookingCompleted $event ) use ( $booking ): void {
                expect( $event->booking->id )->toBe( $booking->id )
                    ->and( $event->actor )->toBe( BookingActor::Provider );
            },
        ],
        'BookingNoShow' => [
            new BookingNoShow( $booking, BookingActor::Admin ),
            function ( BookingNoShow $event ) use ( $booking ): void {
                expect( $event->booking->id )->toBe( $booking->id )
                    ->and( $event->actor )->toBe( BookingActor::Admin );
            },
        ],
        'SeriesCreated' => [
            new SeriesCreated( $series, 12 ),
            function ( SeriesCreated $event ) use ( $series ): void {
                expect( $event->series->id )->toBe( $series->id )
                    ->and( $event->occurrenceCount )->toBe( 12 );
            },
        ],
        'SeriesCancelled' => [
            new SeriesCancelled( $series, BookingActor::Customer, 4, 'Moving away.' ),
            function ( SeriesCancelled $event ) use ( $series ): void {
                expect( $event->series->id )->toBe( $series->id )
                    ->and( $event->actor )->toBe( BookingActor::Customer )
                    ->and( $event->cancelledOccurrenceCount )->toBe( 4 )
                    ->and( $event->reason )->toBe( 'Moving away.' );
            },
        ],
        'SeriesEdited' => [
            new SeriesEdited( $series, SeriesEditScope::ThisAndFollowing, BookingActor::Admin ),
            function ( SeriesEdited $event ) use ( $series ): void {
                expect( $event->series->id )->toBe( $series->id )
                    ->and( $event->scope )->toBe( SeriesEditScope::ThisAndFollowing )
                    ->and( $event->actor )->toBe( BookingActor::Admin )
                    ->and( $event->splitSeries )->toBeNull();
            },
        ],
        'CalendarSynced' => [
            new CalendarSynced( $connection, $booking, 'evt_9f3c' ),
            function ( CalendarSynced $event ) use ( $connection, $booking ): void {
                expect( $event->connection->id )->toBe( $connection->id )
                    ->and( $event->booking->id )->toBe( $booking->id )
                    ->and( $event->externalEventId )->toBe( 'evt_9f3c' );
            },
        ],
        'CalendarSyncFailed' => [
            new CalendarSyncFailed( $connection, 'HTTP 503 from the calendar API.', $booking ),
            function ( CalendarSyncFailed $event ) use ( $connection, $booking ): void {
                expect( $event->connection->id )->toBe( $connection->id )
                    ->and( $event->reason )->toBe( 'HTTP 503 from the calendar API.' )
                    ->and( $event->booking->id )->toBe( $booking->id );
            },
        ],
        'CalendarConnectionDisabled' => [
            new CalendarConnectionDisabled( $connection, 'Too many consecutive failures.' ),
            function ( CalendarConnectionDisabled $event ) use ( $connection ): void {
                expect( $event->connection->id )->toBe( $connection->id )
                    ->and( $event->reason )->toBe( 'Too many consecutive failures.' );
            },
        ],
        'WebhookDisabled' => [
            new WebhookDisabled( $webhook, 'Endpoint returned 410.' ),
            function ( WebhookDisabled $event ) use ( $webhook ): void {
                expect( $event->webhook->id )->toBe( $webhook->id )
                    ->and( $event->reason )->toBe( 'Endpoint returned 410.' );
            },
        ],
    ];
};

it( 'defers every event until the surrounding transaction commits', function () use ( $bookingEventCases ): void {
    // A queue worker on another connection cannot see an uncommitted row, and
    // SerializesModels restores a payload by re-reading it — so an event that
    // escaped mid-transaction would hand the listener a ModelNotFoundException
    // for a booking that does exist.
    foreach ( $bookingEventCases() as $name => [ $event ] ) {
        expect( $event )->toBeInstanceOf( ShouldDispatchAfterCommit::class, $name );
    }
} );

it( 'holds an event back until the transaction commits', function (): void {
    // The marker interface says what we asked for; this says it happened. A
    // listener that ran mid-transaction would be reading rows that may still be
    // rolled back — and on a queue worker, rows another connection cannot see.
    $booking = Booking::factory()->create();
    $heard   = [];

    Event::listen( BookingConfirmed::class, function () use ( &$heard ): void {
        $heard[] = 'confirmed';
    } );

    DB::transaction( function () use ( $booking, &$heard ): void {
        BookingConfirmed::dispatch( $booking );

        expect( $heard )->toBeEmpty();
    } );

    expect( $heard )->toBe( [ 'confirmed' ] );
} );

it( 'drops an event whose transaction rolled back', function (): void {
    $booking = Booking::factory()->create();
    $heard   = [];

    Event::listen( BookingCancelled::class, function () use ( &$heard ): void {
        $heard[] = 'cancelled';
    } );

    try {
        DB::transaction( function () use ( $booking ): void {
            BookingCancelled::dispatch( $booking, BookingActor::Customer );

            throw new RuntimeException( 'rolling back' );
        } );
    } catch ( RuntimeException ) {
        // Expected — the point is what did not happen next.
    }

    expect( $heard )->toBeEmpty();
} );

it( 'refuses a negative cancelled-occurrence count', function (): void {
    new SeriesCancelled( BookingSeries::factory()->create(), BookingActor::Customer, -1 );
} )->throws( InvalidArgumentException::class, 'cannot be negative' );

it( 'accepts a zero cancelled-occurrence count', function (): void {
    // Legitimate: every remaining occurrence had already been cancelled one by one.
    $event = new SeriesCancelled( BookingSeries::factory()->create(), BookingActor::Admin, 0 );

    expect( $event->cancelledOccurrenceCount )->toBe( 0 );
} );

it( 'refuses to let a listener mutate a payload', function (): void {
    // The immutability the CRM package will rely on is enforced by the engine,
    // not by convention — a listener cannot rewrite what the next listener sees.
    $booking = Booking::factory()->create();
    $event   = new BookingCancelled( $booking, BookingActor::Customer, 'Changed my mind.' );

    expect( fn (): string => $event->reason = 'something else' )->toThrow( Error::class );
} );

describe( 'the public event surface', function () use ( $bookingEventCases ): void {
    it( 'dispatches every event with the payload it was built with', function () use ( $bookingEventCases ): void {
        // Built before the fake, because faking the dispatcher also silences the
        // model events the factories rely on — a booking created under
        // `Event::fake()` never mints its booking number.
        $cases = $bookingEventCases();

        Event::fake();

        foreach ( $cases as $name => [ $event, $assert ] ) {
            Event::dispatch( $event );

            Event::assertDispatched(
                $event::class,
                function ( object $dispatched ) use ( $assert ): bool {
                    $assert( $dispatched );

                    return true;
                },
            );
        }

        expect( $cases )->not->toBeEmpty();
    } );

    it( 'survives the queue', function () use ( $bookingEventCases ): void {
        // Every one of these is a plausible queued-listener payload, and
        // SerializesModels rebuilds the models by identifier rather than
        // restoring the rows it was handed. A payload that cannot make the
        // round trip fails only in production, on a queue worker, so it is
        // asserted here instead.
        foreach ( $bookingEventCases() as $name => [ $event, $assert ] ) {
            $restored = unserialize( serialize( $event ) );

            expect( $restored )->toBeInstanceOf( $event::class, $name );

            $assert( $restored );
        }
    } );

    it( 'reaches a listener registered for it', function () use ( $bookingEventCases ): void {
        $cases = $bookingEventCases();
        $heard = [];

        foreach ( $cases as [ $event, $assert ] ) {
            Event::listen( $event::class, function ( object $dispatched ) use ( &$heard, $assert ): void {
                $assert( $dispatched );

                $heard[] = $dispatched::class;
            } );

            Event::dispatch( $event );
        }

        expect( $heard )->toHaveCount( count( $cases ) );
    } );
} );
