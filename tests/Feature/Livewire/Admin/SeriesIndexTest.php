<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Admin\SeriesIndex;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // The occurrences a series materialises land on Mondays in June 2026, and the
    // scopes reason about what is still to come — so "now" has to sit before all
    // of them rather than whenever the suite happens to run.
    $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );
} );

afterEach( function (): void {
    $this->travelBack();
} );

describe( 'listing series', function (): void {
    it( 'shows the series the site owns', function (): void {
        BookingSeries::factory()->create( [ 'customer_name' => 'Ada Customer' ] );
        BookingSeries::factory()->create( [ 'customer_name' => 'Grace Customer' ] );

        Livewire::test( SeriesIndex::class )
            ->assertSee( 'Ada Customer' )
            ->assertSee( 'Grace Customer' );
    } );

    it( 'filters the list by customer name', function (): void {
        BookingSeries::factory()->create( [ 'customer_name' => 'Ada Customer', 'customer_email' => 'ada@example.test' ] );
        BookingSeries::factory()->create( [ 'customer_name' => 'Grace Customer', 'customer_email' => 'grace@example.test' ] );

        Livewire::test( SeriesIndex::class )
            ->set( 'search', 'Ada' )
            ->assertSee( 'Ada Customer' )
            ->assertDontSee( 'Grace Customer' );
    } );

    it( 'filters the list by customer email', function (): void {
        BookingSeries::factory()->create( [ 'customer_name' => 'Ada Customer', 'customer_email' => 'blue@example.test' ] );
        BookingSeries::factory()->create( [ 'customer_name' => 'Grace Customer', 'customer_email' => 'green@example.test' ] );

        Livewire::test( SeriesIndex::class )
            ->set( 'search', 'blue@example.test' )
            ->assertSee( 'Ada Customer' )
            ->assertDontSee( 'Grace Customer' );
    } );

    it( 'returns to the first page when the search changes', function (): void {
        BookingSeries::factory()->count( 20 )->create();

        Livewire::test( SeriesIndex::class )
            ->call( 'gotoPage', 2 )
            ->assertSet( 'paginators.page', 2 )
            ->set( 'search', 'x' )
            ->assertSet( 'paginators.page', 1 );
    } );

    it( 'hides cancelled series until they are asked for', function (): void {
        BookingSeries::factory()->cancelled()->create( [ 'customer_name' => 'Cancelled Customer' ] );

        Livewire::test( SeriesIndex::class )
            ->assertDontSee( 'Cancelled Customer' )
            ->set( 'cancelled', true )
            ->assertSee( 'Cancelled Customer' );
    } );

    it( 'flags a series that has a detached occurrence', function (): void {
        [ $service ] = bookableService();

        $series     = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );
        $occurrence = $series->occurrences()->first();

        $occurrence->detachFromSeries();

        Livewire::test( SeriesIndex::class )
            ->assertSee( 'detached' );
    } );
} );

describe( 'acting on a series', function (): void {
    it( 'asks the host page to open the editor for a series', function (): void {
        $series = BookingSeries::factory()->create();

        Livewire::test( SeriesIndex::class )
            ->call( 'edit', $series->id )
            ->assertDispatched( 'bookings-edit-series', seriesId: $series->id );
    } );

    it( 'cancels a whole series through the series service', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        Livewire::test( SeriesIndex::class )
            ->call( 'cancel', $series->id )
            ->assertDispatched( 'bookings-series-cancelled', seriesId: $series->id );

        expect( $series->fresh()->isCancelled() )->toBeTrue();
    } );

    it( 'shows the reason rather than throwing when the series is already cancelled', function (): void {
        $series = BookingSeries::factory()->cancelled()->create();

        Livewire::test( SeriesIndex::class )
            ->call( 'cancel', $series->id )
            ->assertHasErrors( 'series' )
            ->assertNotDispatched( 'bookings-series-cancelled' );
    } );
} );
