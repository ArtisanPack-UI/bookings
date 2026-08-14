<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Livewire\Admin\BookingsIndex;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'listing bookings', function (): void {
    it( 'shows the bookings the site owns', function (): void {
        Booking::factory()->create( [ 'customer_name' => 'Ada Lovelace' ] );
        Booking::factory()->create( [ 'customer_name' => 'Alan Turing' ] );

        Livewire::test( BookingsIndex::class )
            ->assertSee( 'Ada Lovelace' )
            ->assertSee( 'Alan Turing' );
    } );

    it( 'filters by customer name', function (): void {
        // Email and booking number are pinned, not left to the factory: the
        // search runs across all three columns, so a random `safeEmail()` or
        // `BK-…` on the row that must NOT match can contain "ada" by chance and
        // fail `assertDontSee` on that seed alone.
        Booking::factory()->create( [
            'customer_name'  => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
            'booking_number' => 'BK-LOVELACE01',
        ] );
        Booking::factory()->create( [
            'customer_name'  => 'Alan Turing',
            'customer_email' => 'turing@example.test',
            'booking_number' => 'BK-TURING0001',
        ] );

        Livewire::test( BookingsIndex::class )
            ->set( 'search', 'Ada' )
            ->assertSee( 'Ada Lovelace' )
            ->assertDontSee( 'Alan Turing' );
    } );

    it( 'filters by customer email', function (): void {
        Booking::factory()->create( [ 'customer_name' => 'Ada Lovelace', 'customer_email' => 'ada@example.test' ] );
        Booking::factory()->create( [ 'customer_name' => 'Alan Turing', 'customer_email' => 'alan@example.test' ] );

        Livewire::test( BookingsIndex::class )
            ->set( 'search', 'ada@example.test' )
            ->assertSee( 'Ada Lovelace' )
            ->assertDontSee( 'Alan Turing' );
    } );

    it( 'filters by booking number', function (): void {
        $needle = Booking::factory()->create( [ 'customer_name' => 'Ada Lovelace' ] );
        Booking::factory()->create( [ 'customer_name' => 'Alan Turing' ] );

        Livewire::test( BookingsIndex::class )
            ->set( 'search', $needle->booking_number )
            ->assertSee( 'Ada Lovelace' )
            ->assertDontSee( 'Alan Turing' );
    } );

    it( 'filters by status', function (): void {
        Booking::factory()->confirmed()->create( [ 'customer_name' => 'Confirmed Customer' ] );
        Booking::factory()->cancelled()->create( [ 'customer_name' => 'Cancelled Customer' ] );

        Livewire::test( BookingsIndex::class )
            ->set( 'status', BookingStatus::Cancelled->value )
            ->assertSee( 'Cancelled Customer' )
            ->assertDontSee( 'Confirmed Customer' );
    } );

    it( 'filters by provider', function (): void {
        $keep = ServiceProvider::factory()->create();
        $drop = ServiceProvider::factory()->create();

        Booking::factory()->for( $keep, 'provider' )->create( [ 'customer_name' => 'Kept Booking' ] );
        Booking::factory()->for( $drop, 'provider' )->create( [ 'customer_name' => 'Dropped Booking' ] );

        Livewire::test( BookingsIndex::class )
            ->set( 'providerId', (string) $keep->id )
            ->assertSee( 'Kept Booking' )
            ->assertDontSee( 'Dropped Booking' );
    } );

    it( 'filters by service', function (): void {
        $keep = Service::factory()->create();
        $drop = Service::factory()->create();

        Booking::factory()->for( $keep, 'service' )->create( [ 'customer_name' => 'Kept Booking' ] );
        Booking::factory()->for( $drop, 'service' )->create( [ 'customer_name' => 'Dropped Booking' ] );

        Livewire::test( BookingsIndex::class )
            ->set( 'serviceId', (string) $keep->id )
            ->assertSee( 'Kept Booking' )
            ->assertDontSee( 'Dropped Booking' );
    } );

    it( 'returns to the first page when the search changes', function (): void {
        Booking::factory()->count( 20 )->create();

        Livewire::test( BookingsIndex::class )
            ->call( 'gotoPage', 2 )
            ->assertSet( 'paginators.page', 2 )
            ->set( 'search', 'x' )
            ->assertSet( 'paginators.page', 1 );
    } );
} );

describe( 'acting on a booking', function (): void {
    it( 'asks the host page to open the detail view', function (): void {
        $booking = Booking::factory()->create();

        Livewire::test( BookingsIndex::class )
            ->call( 'view', $booking->id )
            ->assertDispatched( 'bookings-view-booking', bookingId: $booking->id );
    } );

    it( 'exports the filtered bookings as a CSV download', function (): void {
        Booking::factory()->create();

        Livewire::test( BookingsIndex::class )
            ->call( 'export' )
            ->assertFileDownloaded( 'bookings.csv' );
    } );
} );
