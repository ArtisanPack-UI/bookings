<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Contracts\GoogleTokenProvider;
use ArtisanPackUI\Bookings\Drivers\Calendar\GoogleCalendarDriver;
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Exceptions\CalendarSyncException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

afterEach( function (): void {
    removeAllActions( 'ap.bookings.calendarSync.pushing' );
    removeAllActions( 'ap.bookings.calendarSync.pushed' );
    removeAllActions( 'ap.bookings.calendarSync.pullReceived' );
    removeAllFilters( 'ap.bookings.calendarSync.eventPayload' );
    CarbonImmutable::setTestNow();
    date_default_timezone_set( 'UTC' );
} );

/**
 * Builds the driver with a token provider that answers without OAuth.
 *
 * @return GoogleCalendarDriver The driver under test.
 */
function googleDriver(): GoogleCalendarDriver
{
    return new GoogleCalendarDriver( new class() implements GoogleTokenProvider {
        public function accessTokenFor( CalendarConnection $connection ): string
        {
            return 'fake-access-token';
        }
    } );
}

it( 'talks to the Google system', function (): void {
    expect( googleDriver()->driver() )->toBe( CalendarDriver::Google );
} );

describe( 'writing', function (): void {
    it( 'inserts an event under an id derived from the booking', function (): void {
        Http::fake( [ 'https://www.googleapis.com/*' => Http::response( [ 'id' => 'server-side' ], 200 ) ] );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->confirmed()->create();
        $driver     = googleDriver();

        $externalId = $driver->createEvent( $connection, $booking );

        expect( $externalId )->toBe( $driver->eventIdFor( $booking ) );

        Http::assertSent( fn ( Request $request ): bool => 'POST' === $request->method()
            && str_ends_with( $request->url(), '/events' )
            && $request['id'] === $externalId
            && 'confirmed' === $request['status'] );
    } );

    it( 'derives the same id every time, so a retried create is idempotent', function (): void {
        $driver  = googleDriver();
        $booking = Booking::factory()->create();

        expect( $driver->eventIdFor( $booking ) )->toBe( $driver->eventIdFor( $booking->fresh() ) );
    } );

    it( 'treats a 409 on create as the success it is', function (): void {
        Http::fake( [ 'https://www.googleapis.com/*' => Http::response( [ 'error' => 'duplicate' ], 409 ) ] );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->create();
        $driver     = googleDriver();

        expect( $driver->createEvent( $connection, $booking ) )->toBe( $driver->eventIdFor( $booking ) );
    } );

    it( 'fires pushing with the provider slug and pushed with the external id too', function (): void {
        Http::fake( [ 'https://www.googleapis.com/*' => Http::response( [], 200 ) ] );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->create();
        $seen       = [];

        addAction( 'ap.bookings.calendarSync.pushing', function ( Booking $pushed, string $slug ) use ( &$seen ): void {
            $seen['pushing'] = [ $pushed->getKey(), $slug ];
        } );
        addAction( 'ap.bookings.calendarSync.pushed', function ( Booking $pushed, string $slug, string $id ) use ( &$seen ): void {
            $seen['pushed'] = [ $pushed->getKey(), $slug, $id ];
        } );

        $externalId = googleDriver()->createEvent( $connection, $booking );

        expect( $seen['pushing'] )->toBe( [ $booking->getKey(), 'google' ] )
            ->and( $seen['pushed'] )->toBe( [ $booking->getKey(), 'google', $externalId ] );
    } );

    it( 'throws when Google refuses a create', function (): void {
        Http::fake( [ 'https://www.googleapis.com/*' => Http::response( [ 'error' => 'boom' ], 500 ) ] );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->create();

        googleDriver()->createEvent( $connection, $booking );
    } )->throws( CalendarSyncException::class );

    it( 're-inserts on update when the event has been deleted on the calendar', function (): void {
        Http::fake( [
            'https://www.googleapis.com/*' => function ( Request $request ) {
                return 'PUT' === $request->method()
                    ? Http::response( [ 'error' => 'gone' ], 404 )
                    : Http::response( [], 200 );
            },
        ] );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->create();
        $driver     = googleDriver();

        $externalId = $driver->updateEvent( $connection, $booking, $driver->eventIdFor( $booking ) );

        expect( $externalId )->toBe( $driver->eventIdFor( $booking ) );

        Http::assertSent( fn ( Request $request ): bool => 'PUT' === $request->method() );
        Http::assertSent( fn ( Request $request ): bool => 'POST' === $request->method()
            && $request['id'] === $externalId );
    } );

    it( 'treats a delete of an already-gone event as a success', function (): void {
        Http::fake( [ 'https://www.googleapis.com/*' => Http::response( [], 410 ) ] );

        $connection = CalendarConnection::factory()->google()->create();

        googleDriver()->deleteEvent( $connection, 'apbk-whatever' );

        Http::assertSent( fn ( Request $request ): bool => 'DELETE' === $request->method() );
    } );

    it( 'throws when Google refuses a delete for any other reason', function (): void {
        Http::fake( [ 'https://www.googleapis.com/*' => Http::response( [], 500 ) ] );

        $connection = CalendarConnection::factory()->google()->create();

        googleDriver()->deleteEvent( $connection, 'apbk-whatever' );
    } )->throws( CalendarSyncException::class );
} );

describe( 'building the event', function (): void {
    it( 'maps the booking to a Google event body', function (): void {
        $booking = Booking::factory()->confirmed()->create();

        $body = googleDriver()->buildEvent( $booking );

        expect( $body['status'] )->toBe( 'confirmed' )
            ->and( $body['start'] )->toHaveKey( 'dateTime' )
            ->and( $body['end'] )->toHaveKey( 'dateTime' );
    } );

    it( 'never sends iCalUID, which Google would reject as immutable on update', function (): void {
        $body = googleDriver()->buildEvent( Booking::factory()->confirmed()->create() );

        expect( $body )->not->toHaveKey( 'iCalUID' );
    } );

    it( 'passes the provider slug to the eventPayload filter', function (): void {
        $booking = Booking::factory()->create();
        $seen    = null;

        addFilter( 'ap.bookings.calendarSync.eventPayload', function ( array $payload, Booking $for, string $slug ) use ( &$seen ): array {
            $seen = $slug;

            return $payload;
        } );

        googleDriver()->buildEvent( $booking );

        expect( $seen )->toBe( 'google' );
    } );

    it( 'refuses an eventPayload filter that does not return an array', function (): void {
        $booking = Booking::factory()->create();

        addFilter( 'ap.bookings.calendarSync.eventPayload', fn (): string => 'nope' );

        googleDriver()->buildEvent( $booking );
    } )->throws( UnexpectedValueException::class, 'must return an array' );
} );

describe( 'reading busy periods', function (): void {
    it( 'keeps opaque events and drops free ones and its own', function (): void {
        $driver = googleDriver();

        Http::fake( [
            'https://www.googleapis.com/*' => Http::response( [
                'items' => [
                    [
                        'id'    => 'busy-1',
                        'start' => [ 'dateTime' => '2026-06-01T09:00:00Z' ],
                        'end'   => [ 'dateTime' => '2026-06-01T10:00:00Z' ],
                    ],
                    [
                        'id'           => 'free-1',
                        'transparency' => 'transparent',
                        'start'        => [ 'dateTime' => '2026-06-01T11:00:00Z' ],
                        'end'          => [ 'dateTime' => '2026-06-01T12:00:00Z' ],
                    ],
                    [
                        'id'    => 'apbk-ours',
                        'start' => [ 'dateTime' => '2026-06-01T13:00:00Z' ],
                        'end'   => [ 'dateTime' => '2026-06-01T14:00:00Z' ],
                    ],
                ],
            ], 200 ),
        ] );

        $connection = CalendarConnection::factory()->google()->create();
        $window     = new TimeRange(
            CarbonImmutable::parse( '2026-06-01T00:00:00Z' ),
            CarbonImmutable::parse( '2026-06-02T00:00:00Z' ),
        );

        $periods = $driver->busyPeriods( $connection, $window );

        expect( $periods )->toHaveCount( 1 )
            ->and( $periods[0]->start->toRfc3339String() )->toBe( '2026-06-01T09:00:00+00:00' )
            ->and( $periods[0]->end->toRfc3339String() )->toBe( '2026-06-01T10:00:00+00:00' );
    } );

    it( 'anchors an all-day event to UTC regardless of the app timezone', function (): void {
        // The bug this guards against: parsing a bare date in the app's own zone
        // would shift an all-day busy block by the offset near a day boundary.
        date_default_timezone_set( 'America/Chicago' );

        Http::fake( [
            'https://www.googleapis.com/*' => Http::response( [
                'items' => [
                    [
                        'id'    => 'allday-1',
                        'start' => [ 'date' => '2026-06-01' ],
                        'end'   => [ 'date' => '2026-06-02' ],
                    ],
                ],
            ], 200 ),
        ] );

        $connection = CalendarConnection::factory()->google()->create();
        $window     = new TimeRange(
            CarbonImmutable::parse( '2026-05-30T00:00:00Z' ),
            CarbonImmutable::parse( '2026-06-05T00:00:00Z' ),
        );

        $periods = googleDriver()->busyPeriods( $connection, $window );

        expect( $periods )->toHaveCount( 1 )
            ->and( $periods[0]->start->toRfc3339String() )->toBe( '2026-06-01T00:00:00+00:00' )
            ->and( $periods[0]->end->toRfc3339String() )->toBe( '2026-06-02T00:00:00+00:00' );
    } );
} );

describe( 'transport failures', function (): void {
    it( 'wraps an unreachable Google in a CalendarSyncException', function (): void {
        Http::fake( [
            'https://www.googleapis.com/*' => function (): void {
                throw new ConnectionException( 'Connection timed out' );
            },
        ] );

        $connection = CalendarConnection::factory()->google()->create();
        $booking    = Booking::factory()->create();

        googleDriver()->createEvent( $connection, $booking );
    } )->throws( CalendarSyncException::class, 'unreachable' );
} );

describe( 'push channels', function (): void {
    it( 'stores the channel a subscribe registers', function (): void {
        $expiresMs = CarbonImmutable::parse( '2026-06-08T00:00:00Z' )->getTimestampMs();

        Http::fake( [
            'https://www.googleapis.com/*' => Http::response( [
                'resourceId' => 'resource-abc',
                'expiration' => (string) $expiresMs,
            ], 200 ),
        ] );

        $connection = CalendarConnection::factory()->google()->create();

        $channel = googleDriver()->subscribeToChanges( $connection, 'https://app.test/calendar/webhook' );

        expect( $channel->resource_id )->toBe( 'resource-abc' )
            ->and( $channel->channel_id )->not->toBeNull()
            ->and( $channel->expires_at->getTimestampMs() )->toBe( $expiresMs )
            ->and( $connection->watchChannels()->count() )->toBe( 1 );

        Http::assertSent( fn ( Request $request ): bool => str_ends_with( $request->url(), '/events/watch' )
            && 'web_hook' === $request['type']
            && 'https://app.test/calendar/webhook' === $request['address'] );
    } );

    it( 'replaces and stops the old channel on renewal', function (): void {
        Http::fake( [
            'https://www.googleapis.com/calendar/v3/channels/stop' => Http::response( [], 200 ),
            'https://www.googleapis.com/*'                         => Http::response( [
                'resourceId' => 'resource-new',
                'expiration' => (string) CarbonImmutable::parse( '2026-06-15T00:00:00Z' )->getTimestampMs(),
            ], 200 ),
        ] );

        $connection = CalendarConnection::factory()->google()->create();
        $channel    = $connection->watchChannels()->create( [
            'channel_id'  => 'channel-old',
            'resource_id' => 'resource-old',
            'expires_at'  => CarbonImmutable::now()->addMinutes( 20 ),
        ] );

        $renewed = googleDriver()->renewSubscription( $channel, 'https://app.test/calendar/webhook' );

        expect( $renewed->resource_id )->toBe( 'resource-new' )
            ->and( $renewed->channel_id )->not->toBe( 'channel-old' );

        Http::assertSent( fn ( Request $request ): bool => str_ends_with( $request->url(), '/channels/stop' )
            && 'channel-old' === $request['id']
            && 'resource-old' === $request['resourceId'] );
    } );
} );

describe( 'incremental sync', function (): void {
    it( 'ingests changed events as busy blocks and saves the next token', function (): void {
        Http::fake( [
            'https://www.googleapis.com/*' => Http::response( [
                'items' => [
                    [
                        'id'    => 'ext-1',
                        'etag'  => '"etag-1"',
                        'start' => [ 'dateTime' => '2026-06-01T09:00:00Z' ],
                        'end'   => [ 'dateTime' => '2026-06-01T10:00:00Z' ],
                    ],
                ],
                'nextSyncToken' => 'token-next',
            ], 200 ),
        ] );

        $connection = CalendarConnection::factory()->google()->twoWay()->create( [ 'sync_token' => 'token-here' ] );
        $page       = null;

        addAction( 'ap.bookings.calendarSync.pullReceived', function ( array $payload, string $slug ) use ( &$page ): void {
            $page = [ $payload, $slug ];
        } );

        googleDriver()->incrementalSync( $connection );

        expect( $connection->busyBlocks()->where( 'external_event_id', 'ext-1' )->exists() )->toBeTrue()
            ->and( $connection->fresh()->sync_token )->toBe( 'token-next' )
            ->and( $page[1] )->toBe( 'google' )
            ->and( $page[0]['items'][0]['id'] )->toBe( 'ext-1' );
    } );

    it( 'removes the busy block for a cancelled event', function (): void {
        Http::fake( [
            'https://www.googleapis.com/*' => Http::response( [
                'items'         => [ [ 'id' => 'ext-2', 'status' => 'cancelled' ] ],
                'nextSyncToken' => 'token-next',
            ], 200 ),
        ] );

        $connection = CalendarConnection::factory()->google()->twoWay()->create( [ 'sync_token' => 'token-here' ] );
        $connection->busyBlocks()->create( [
            'external_event_id' => 'ext-2',
            'starts_at_utc'     => CarbonImmutable::parse( '2026-06-01T09:00:00Z' ),
            'ends_at_utc'       => CarbonImmutable::parse( '2026-06-01T10:00:00Z' ),
        ] );

        googleDriver()->incrementalSync( $connection );

        expect( $connection->busyBlocks()->where( 'external_event_id', 'ext-2' )->exists() )->toBeFalse();
    } );

    it( 'falls back to a bounded full resync on a 410 and recovers the token', function (): void {
        CarbonImmutable::setTestNow( '2026-06-01T00:00:00Z' );
        config()->set( 'artisanpack.bookings.calendar.two_way_lookahead_days', 60 );

        Http::fake( [
            'https://www.googleapis.com/*' => function ( Request $request ) {
                if ( str_contains( $request->url(), 'syncToken=' ) ) {
                    return Http::response( [ 'error' => 'gone' ], 410 );
                }

                return Http::response( [ 'items' => [], 'nextSyncToken' => 'token-fresh' ], 200 );
            },
        ] );

        $connection = CalendarConnection::factory()->google()->twoWay()->create( [ 'sync_token' => 'token-stale' ] );

        googleDriver()->incrementalSync( $connection );

        expect( $connection->fresh()->sync_token )->toBe( 'token-fresh' );

        // The recovery reads a fixed window: now through now + 60 days.
        Http::assertSent( fn ( Request $request ): bool => str_contains( $request->url(), 'timeMin=' )
            && str_contains( $request->url(), 'timeMax=' )
            && str_contains( urldecode( $request->url() ), '2026-07-31' ) );
    } );

    it( 'treats a 404 as a failure without discarding the cursor', function (): void {
        Http::fake( [ 'https://www.googleapis.com/*' => Http::response( [], 404 ) ] );

        $connection = CalendarConnection::factory()->google()->twoWay()->create( [ 'sync_token' => 'token-here' ] );

        expect( fn (): mixed => googleDriver()->incrementalSync( $connection ) )
            ->toThrow( CalendarSyncException::class );

        // A 404 is the calendar being gone, not the token being stale — the
        // cursor must survive so a reconnect can resume from it.
        expect( $connection->fresh()->sync_token )->toBe( 'token-here' );

        Http::assertSentCount( 1 );
    } );

    it( 'does a full read straight away when there is no token yet', function (): void {
        CarbonImmutable::setTestNow( '2026-06-01T00:00:00Z' );

        Http::fake( [
            'https://www.googleapis.com/*' => Http::response( [ 'items' => [], 'nextSyncToken' => 'token-first' ], 200 ),
        ] );

        $connection = CalendarConnection::factory()->google()->twoWay()->create( [ 'sync_token' => null ] );

        googleDriver()->incrementalSync( $connection );

        expect( $connection->fresh()->sync_token )->toBe( 'token-first' );

        Http::assertSent( fn ( Request $request ): bool => ! str_contains( $request->url(), 'syncToken=' )
            && str_contains( $request->url(), 'timeMax=' ) );
    } );
} );
