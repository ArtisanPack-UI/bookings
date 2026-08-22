<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Admin\BookingCalendar;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'showing a week', function (): void {
    it( 'shows the bookings that fall within the shown week', function (): void {
        Booking::factory()->create( [
            'customer_name' => 'Within The Week',
            'start_time'    => '2035-06-05 10:00:00',
            'end_time'      => '2035-06-05 10:30:00',
        ] );

        Booking::factory()->create( [
            'customer_name' => 'Weeks Away',
            'start_time'    => '2035-07-01 10:00:00',
            'end_time'      => '2035-07-01 10:30:00',
        ] );

        Livewire::test( BookingCalendar::class )
            ->set( 'weekStart', '2035-06-04' )
            ->assertSee( 'Within The Week' )
            ->assertDontSee( 'Weeks Away' );
    } );

    it( 'leaves a chip uncoloured when no readable text colour is available', function (): void {
        // Without artisanpack-ui/accessibility the contrast colour is null, so a
        // service colour is not painted onto the chip — an unreadable label is
        // worse than a plain one. (The coloured branch needs the a11y package,
        // which is a suggested dependency absent from the test environment.)
        $service = Service::factory()->create( [ 'color' => '#3b82f6' ] );

        Booking::factory()->for( $service )->create( [
            'customer_name' => 'Plain Chip',
            'start_time'    => '2035-06-05 10:00:00',
            'end_time'      => '2035-06-05 10:30:00',
        ] );

        Livewire::test( BookingCalendar::class )
            ->set( 'weekStart', '2035-06-04' )
            ->assertSee( 'Plain Chip' )
            ->assertDontSee( 'background-color:', false );
    } );

    it( 'places a booking on its day in the application timezone', function (): void {
        // 02:00 UTC on 5 June is 21:00 the evening before in Chicago (UTC-5 in
        // June), so a timezone-aware calendar files it under 4 June, not 5 June.
        config()->set( 'app.timezone', 'America/Chicago' );

        $booking = Booking::factory()->create( [
            'start_time' => '2035-06-05 02:00:00',
            'end_time'   => '2035-06-05 02:30:00',
        ] );

        $days = Livewire::test( BookingCalendar::class )
            ->set( 'weekStart', '2035-06-04' )
            ->instance()
            ->bookingsByDay();

        expect( $days->has( '2035-06-04' ) )->toBeTrue()
            ->and( $days->has( '2035-06-05' ) )->toBeFalse()
            ->and( $days->get( '2035-06-04' )->pluck( 'id' ) )->toContain( $booking->id );
    } );

    it( 'excludes a booking that starts exactly at the next week', function (): void {
        Booking::factory()->create( [
            'customer_name' => 'Last Instant',
            'start_time'    => '2035-06-10 23:59:59',
            'end_time'      => '2035-06-11 00:29:59',
        ] );

        Booking::factory()->create( [
            'customer_name' => 'Next Week Start',
            'start_time'    => '2035-06-11 00:00:00',
            'end_time'      => '2035-06-11 00:30:00',
        ] );

        Livewire::test( BookingCalendar::class )
            ->set( 'weekStart', '2035-06-04' )
            ->assertSee( 'Last Instant' )
            ->assertDontSee( 'Next Week Start' );
    } );

    it( 'normalizes an arbitrary week-start date to the start of its week', function (): void {
        // A Wednesday query-string value still renders a Monday-first week.
        Livewire::test( BookingCalendar::class )
            ->set( 'weekStart', '2035-06-06' )
            ->assertSee( '4 Jun' );
    } );

    it( 'opens on the week containing today', function (): void {
        $expected = now()->startOfWeek()->toDateString();

        Livewire::test( BookingCalendar::class )
            ->assertSet( 'weekStart', $expected );
    } );
} );

describe( 'navigating the calendar', function (): void {
    it( 'steps forward a week', function (): void {
        Livewire::test( BookingCalendar::class )
            ->set( 'weekStart', '2035-06-04' )
            ->call( 'nextWeek' )
            ->assertSet( 'weekStart', '2035-06-11' );
    } );

    it( 'steps back a week', function (): void {
        Livewire::test( BookingCalendar::class )
            ->set( 'weekStart', '2035-06-04' )
            ->call( 'previousWeek' )
            ->assertSet( 'weekStart', '2035-05-28' );
    } );

    it( 'returns to the week containing today', function (): void {
        $expected = now()->startOfWeek()->toDateString();

        Livewire::test( BookingCalendar::class )
            ->set( 'weekStart', '2035-06-04' )
            ->call( 'thisWeek' )
            ->assertSet( 'weekStart', $expected );
    } );
} );

describe( 'filtering the calendar', function (): void {
    it( 'filters by provider', function (): void {
        $keep = ServiceProvider::factory()->create();
        $drop = ServiceProvider::factory()->create();

        Booking::factory()->for( $keep, 'provider' )->create( [
            'customer_name' => 'Kept Booking',
            'start_time'    => '2035-06-05 10:00:00',
            'end_time'      => '2035-06-05 10:30:00',
        ] );

        Booking::factory()->for( $drop, 'provider' )->create( [
            'customer_name' => 'Dropped Booking',
            'start_time'    => '2035-06-05 11:00:00',
            'end_time'      => '2035-06-05 11:30:00',
        ] );

        Livewire::test( BookingCalendar::class )
            ->set( 'weekStart', '2035-06-04' )
            ->set( 'providerId', (string) $keep->id )
            ->assertSee( 'Kept Booking' )
            ->assertDontSee( 'Dropped Booking' );
    } );

    it( 'filters by service', function (): void {
        $keep = Service::factory()->create();
        $drop = Service::factory()->create();

        Booking::factory()->for( $keep, 'service' )->create( [
            'customer_name' => 'Kept Booking',
            'start_time'    => '2035-06-05 10:00:00',
            'end_time'      => '2035-06-05 10:30:00',
        ] );

        Booking::factory()->for( $drop, 'service' )->create( [
            'customer_name' => 'Dropped Booking',
            'start_time'    => '2035-06-05 11:00:00',
            'end_time'      => '2035-06-05 11:30:00',
        ] );

        Livewire::test( BookingCalendar::class )
            ->set( 'weekStart', '2035-06-04' )
            ->set( 'serviceId', (string) $keep->id )
            ->assertSee( 'Kept Booking' )
            ->assertDontSee( 'Dropped Booking' );
    } );
} );

describe( 'acting on a booking', function (): void {
    it( 'asks the host page to open the detail view', function (): void {
        $booking = Booking::factory()->create();

        Livewire::test( BookingCalendar::class )
            ->call( 'view', $booking->id )
            ->assertDispatched( 'bookings-view-booking', bookingId: $booking->id );
    } );
} );
