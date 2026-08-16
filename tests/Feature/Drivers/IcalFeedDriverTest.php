<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Drivers\Calendar\IcalFeedDriver;
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\IcalFeedService;
use ArtisanPackUI\Bookings\Services\IcalTokenService;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

afterEach( function (): void {
    removeAllActions( 'ap.bookings.calendarSync.pushing' );
    removeAllActions( 'ap.bookings.calendarSync.pushed' );
    removeAllFilters( 'ap.bookings.calendarSync.eventPayload' );
} );

/**
 * Gets the iCal driver out of the container.
 *
 * @return IcalFeedDriver The driver under test.
 */
function icalDriver(): IcalFeedDriver
{
    return app( IcalFeedDriver::class );
}

it( 'talks to the iCal system', function (): void {
    expect( icalDriver()->driver() )->toBe( CalendarDriver::Ical );
} );

describe( 'writing', function (): void {
    it( 'returns the feed UID as the external event id when creating', function (): void {
        $booking = Booking::factory()->create();

        expect( icalDriver()->createEvent( new CalendarConnection(), $booking ) )
            ->toBe( app( IcalFeedService::class )->uid( $booking ) );
    } );

    it( 'keeps the UID stable when updating, ignoring the id passed in', function (): void {
        $booking = Booking::factory()->create();

        expect( icalDriver()->updateEvent( new CalendarConnection(), $booking, 'whatever-was-stored' ) )
            ->toBe( app( IcalFeedService::class )->uid( $booking ) );
    } );

    it( 'fires pushing and pushed with the booking and its external id', function (): void {
        $booking = Booking::factory()->create();
        $seen    = [];

        addAction( 'ap.bookings.calendarSync.pushing', function ( Booking $pushed, string $id ) use ( &$seen ): void {
            $seen['pushing'] = [ $pushed->getKey(), $id ];
        } );
        addAction( 'ap.bookings.calendarSync.pushed', function ( Booking $pushed, string $id ) use ( &$seen ): void {
            $seen['pushed'] = [ $pushed->getKey(), $id ];
        } );

        $externalId = icalDriver()->createEvent( new CalendarConnection(), $booking );

        expect( $seen['pushing'] )->toBe( [ $booking->getKey(), $externalId ] )
            ->and( $seen['pushed'] )->toBe( [ $booking->getKey(), $externalId ] );
    } );

    it( 'never pushes to an external calendar, so deleting is a no-op', function (): void {
        $fired = false;

        addAction( 'ap.bookings.calendarSync.pushed', function () use ( &$fired ): void {
            $fired = true;
        } );

        icalDriver()->deleteEvent( new CalendarConnection(), 'some-event-id' );

        expect( $fired )->toBeFalse();
    } );
} );

describe( 'reading', function (): void {
    it( 'reads no busy periods, because a feed is written to and never read from', function (): void {
        $window = new TimeRange(
            CarbonImmutable::parse( '2026-01-01' ),
            CarbonImmutable::parse( '2026-02-01' ),
        );

        expect( icalDriver()->busyPeriods( new CalendarConnection(), $window ) )->toBe( [] );
    } );
} );

describe( 'building the event', function (): void {
    it( 'serialises a VEVENT carrying the booking UID, times, and status', function (): void {
        $booking = Booking::factory()->confirmed()->create();

        $event = icalDriver()->buildEvent( $booking );

        expect( $event )->toContain( 'BEGIN:VEVENT' )
            ->and( $event )->toContain( 'UID:' . app( IcalFeedService::class )->uid( $booking ) )
            ->and( $event )->toContain( 'STATUS:CONFIRMED' )
            ->and( $event )->toContain( 'DTSTART' );
    } );

    it( 'marks a booking nobody has approved yet as tentative', function (): void {
        $booking = Booking::factory()->requested()->create();

        expect( icalDriver()->buildEvent( $booking ) )->toContain( 'STATUS:TENTATIVE' );
    } );

    it( 'applies the eventPayload filter before serialising', function (): void {
        $booking = Booking::factory()->create();

        addFilter( 'ap.bookings.calendarSync.eventPayload', function ( array $payload ): array {
            $payload['summary'] = 'Rewritten by a consumer';

            return $payload;
        } );

        expect( icalDriver()->buildEvent( $booking ) )->toContain( 'SUMMARY:Rewritten by a consumer' );
    } );

    it( 'passes the booking to the eventPayload filter', function (): void {
        $booking = Booking::factory()->create();
        $seen    = null;

        addFilter( 'ap.bookings.calendarSync.eventPayload', function ( array $payload, Booking $for ) use ( &$seen ): array {
            $seen = $for->getKey();

            return $payload;
        } );

        icalDriver()->buildEvent( $booking );

        expect( $seen )->toBe( $booking->getKey() );
    } );

    it( 'refuses an eventPayload filter that does not return an array', function (): void {
        $booking = Booking::factory()->create();

        addFilter( 'ap.bookings.calendarSync.eventPayload', fn (): string => 'nope' );

        icalDriver()->buildEvent( $booking );
    } )->throws( UnexpectedValueException::class, 'must return an array' );

    it( 'refuses an eventPayload filter that drops the start from a date', function (): void {
        $booking = Booking::factory()->create();

        addFilter( 'ap.bookings.calendarSync.eventPayload', function ( array $payload ): array {
            $payload['start'] = 'not a date';

            return $payload;
        } );

        icalDriver()->buildEvent( $booking );
    } )->throws( UnexpectedValueException::class, 'must keep start as a date' );
} );

describe( 'the subscription URL', function (): void {
    it( 'builds the signed feed URL from a minted token', function (): void {
        $provider = ServiceProvider::factory()->create();
        $token    = app( IcalTokenService::class )->issueFor( $provider );

        expect( icalDriver()->subscriptionUrl( $token ) )
            ->toBe( app( IcalTokenService::class )->feedUrl( $token ) )
            ->toContain( $token );
    } );

    it( 'reports whether a provider has a feed to subscribe to', function (): void {
        $provider = ServiceProvider::factory()->create();

        expect( icalDriver()->servesFeedFor( $provider ) )->toBeFalse();

        app( IcalTokenService::class )->issueFor( $provider );

        expect( icalDriver()->servesFeedFor( $provider->fresh() ) )->toBeTrue();
    } );
} );
