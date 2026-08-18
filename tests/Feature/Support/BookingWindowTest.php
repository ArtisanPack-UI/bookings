<?php

declare( strict_types=1 );

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
    'a blank string'  => '',
    'a string zero'   => '0',
    'an integer zero' => 0,
    'a negative'      => -60,
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
    'a blank string'  => '',
    'a string zero'   => '0',
    'an integer zero' => 0,
    'a negative'      => -60,
] );

it( 'holds a booking off until a positive minimum has passed', function (): void {
    $now = bookingWindowNow();

    config()->set( 'artisanpack.bookings.booking_window.min_advance_minutes', 60 );

    expect( BookingWindow::earliest( $now )->toIso8601String() )
        ->toBe( $now->addMinutes( 60 )->toIso8601String() );
} );
