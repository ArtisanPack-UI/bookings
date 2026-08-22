<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Support\BookingWindow;
use Carbon\CarbonImmutable;

/*
 * The booking window is read in three places — the widget/slots resolver through
 * BookingWindow::clip(), and submission through StoreBookingRequest and
 * RescheduleBookingRequest. All three now measure the window through
 * BookingWindow::earliest() and BookingWindow::latest(), so a non-positive bound
 * cannot mean one thing to the calendar and the opposite to the form. These
 * assert what those two helpers make true for every one of them at once.
 */

function bookingWindowNow(): CarbonImmutable
{
    return CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' );
}

it( 'reads a non-positive maximum as no limit', function ( int|string $value ): void {
    config()->set( 'artisanpack.bookings.booking_window.max_advance_minutes', $value );

    expect( BookingWindow::latest( bookingWindowNow() ) )->toBeNull();
} )->with( [
    'a blank string'       => '',
    'a non-numeric string' => 'off',
    'a string zero'        => '0',
    'an integer zero'      => 0,
    'a negative'           => -60,
] );

it( 'reads a missing maximum as no limit', function (): void {
    config()->set( 'artisanpack.bookings.booking_window', [ 'min_advance_minutes' => 60 ] );

    expect( BookingWindow::latest( bookingWindowNow() ) )->toBeNull();
} );

it( 'bounds the window when the maximum is positive', function (): void {
    $now = bookingWindowNow();

    config()->set( 'artisanpack.bookings.booking_window.max_advance_minutes', 60 * 24 * 90 );

    expect( BookingWindow::latest( $now )?->toIso8601String() )
        ->toBe( $now->addMinutes( 60 * 24 * 90 )->toIso8601String() );
} );

it( 'reads a non-positive minimum as no wait, the same way the maximum reads no limit', function ( int|string $value ): void {
    config()->set( 'artisanpack.bookings.booking_window.min_advance_minutes', $value );

    expect( BookingWindow::earliest( bookingWindowNow() )->toIso8601String() )
        ->toBe( bookingWindowNow()->toIso8601String() );
} )->with( [
    'a blank string'       => '',
    'a non-numeric string' => 'off',
    'a string zero'        => '0',
    'an integer zero'      => 0,
    'a negative'           => -60,
] );

it( 'holds a booking off until a positive minimum has passed', function (): void {
    $now = bookingWindowNow();

    config()->set( 'artisanpack.bookings.booking_window.min_advance_minutes', 60 );

    expect( BookingWindow::earliest( $now )->toIso8601String() )
        ->toBe( $now->addMinutes( 60 )->toIso8601String() );
} );

/*
 * DST end-bound drift — the span end must add a day/month in the local zone, not
 * to the UTC instant, or the last local hour of a fall-back day vanishes and the
 * first local hour of the day after a spring-forward day leaks in. Both bounds
 * are cleared so clip() returns the raw span, and now is pinned well before the
 * dates so the earliest bound cannot trim the start.
 */
describe( 'the day and month spans across a DST transition', function (): void {
    beforeEach( function (): void {
        CarbonImmutable::setTestNow( '2026-01-01 00:00:00' );

        config()->set( 'artisanpack.bookings.booking_window.min_advance_minutes', 0 );
        config()->set( 'artisanpack.bookings.booking_window.max_advance_minutes', 0 );
    } );

    afterEach( function (): void {
        CarbonImmutable::setTestNow();
    } );

    it( 'ends a fall-back day at the true next local midnight, keeping the 25th hour', function (): void {
        $service = Service::factory()->make();

        $window = BookingWindow::day( $service, '2026-11-01', 'America/Chicago' );

        expect( $window )->not->toBeNull()
            ->and( $window->start->toIso8601String() )->toBe( '2026-11-01T05:00:00+00:00' )
            ->and( $window->end->toIso8601String() )->toBe( '2026-11-02T06:00:00+00:00' );
    } );

    it( 'ends a spring-forward day at the true next local midnight, not leaking the next day in', function (): void {
        $service = Service::factory()->make();

        $window = BookingWindow::day( $service, '2026-03-08', 'America/Chicago' );

        expect( $window )->not->toBeNull()
            ->and( $window->start->toIso8601String() )->toBe( '2026-03-08T06:00:00+00:00' )
            ->and( $window->end->toIso8601String() )->toBe( '2026-03-09T05:00:00+00:00' );
    } );

    it( 'ends a month that contains a DST change at the true next local month start', function (): void {
        $service = Service::factory()->make();

        $window = BookingWindow::month( $service, '2026-11', 'America/Chicago' );

        expect( $window )->not->toBeNull()
            ->and( $window->start->toIso8601String() )->toBe( '2026-11-01T05:00:00+00:00' )
            ->and( $window->end->toIso8601String() )->toBe( '2026-12-01T06:00:00+00:00' );
    } );
} );
