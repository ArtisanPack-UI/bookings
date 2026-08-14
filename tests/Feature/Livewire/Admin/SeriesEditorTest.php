<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Admin\SeriesEditor;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // Every occurrence a series materialises here is a Monday in June 2026, and
    // the scopes are written in terms of what is still to come — so "now" has to
    // be a fixed point before all of them.
    $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );
} );

afterEach( function (): void {
    $this->travelBack();
} );

describe( 'loading the editor', function (): void {
    it( 'prefills the rule-touching form from the series', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        Livewire::test( SeriesEditor::class, [ 'seriesId' => $series->id ] )
            ->assertSet( 'rrule', 'FREQ=WEEKLY;COUNT=3' )
            ->assertSet( 'customerName', 'Sam Rivera' )
            ->assertSet( 'customerEmail', 'sam@example.test' )
            ->assertSet( 'dtstartTimezone', 'America/Chicago' )
            ->assertSet( 'dtstartLocal', '2026-06-01T10:00' )
            ->assertSet( 'scope', 'all' );
    } );

    it( 'prefills the single-occurrence form when an occurrence is chosen', function (): void {
        [ $service ] = bookableService();

        $series     = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );
        $occurrence = $series->occurrences()->first();

        Livewire::test( SeriesEditor::class, [ 'seriesId' => $series->id ] )
            ->set( 'scope', 'this' )
            ->set( 'occurrenceId', $occurrence->id )
            ->assertSet( 'occurrenceStart', '2026-06-01T10:00' )
            ->assertSet( 'occurrenceCustomerName', 'Sam Rivera' );
    } );
} );

describe( 'editing one occurrence', function (): void {
    it( 'moves the occurrence and detaches it from the rule', function (): void {
        [ $service ] = bookableService();

        $series     = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );
        $occurrence = $series->occurrences()->first();

        Livewire::test( SeriesEditor::class, [ 'seriesId' => $series->id ] )
            ->set( 'scope', 'this' )
            ->set( 'occurrenceId', $occurrence->id )
            ->set( 'occurrenceStart', '2026-06-01T14:00' )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->assertDispatched( 'bookings-series-saved', seriesId: $series->id );

        $occurrence->refresh();

        expect( $occurrence->isDetachedFromSeries() )->toBeTrue()
            ->and( $occurrence->start_time->toIso8601String() )->toBe( bookingStart( '14:00' )->toIso8601String() )
            ->and( $series->fresh()->rrule )->toBe( 'FREQ=WEEKLY;COUNT=3' );
    } );

    it( 'requires an occurrence for a scoped edit', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        Livewire::test( SeriesEditor::class, [ 'seriesId' => $series->id ] )
            ->set( 'scope', 'this' )
            ->call( 'save' )
            ->assertHasErrors( 'occurrenceId' )
            ->assertNotDispatched( 'bookings-series-saved' );
    } );
} );

describe( 'editing this and following', function (): void {
    it( 'splits the series and carries the change forward on a new one', function (): void {
        [ $service ] = bookableService();

        $series     = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );
        $occurrence = $series->occurrences()->skip( 1 )->first();

        Livewire::test( SeriesEditor::class, [ 'seriesId' => $series->id ] )
            ->set( 'scope', 'this_and_following' )
            ->set( 'occurrenceId', $occurrence->id )
            ->set( 'customerName', 'Sam Rivera-Jones' )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->assertDispatched( 'bookings-series-saved' );

        expect( BookingSeries::query()->count() )->toBe( 2 )
            ->and( BookingSeries::query()->where( 'customer_name', 'Sam Rivera-Jones' )->exists() )->toBeTrue();
    } );
} );

describe( 'editing the whole series', function (): void {
    it( 're-materialises everything to come under a new rule', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        Livewire::test( SeriesEditor::class, [ 'seriesId' => $series->id ] )
            ->set( 'rrule', 'FREQ=WEEKLY;COUNT=2' )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->assertDispatched( 'bookings-series-saved', seriesId: $series->id );

        expect( $series->fresh()->rrule )->toBe( 'FREQ=WEEKLY;COUNT=2' );
    } );

    it( 'refuses to save when nothing has changed', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        Livewire::test( SeriesEditor::class, [ 'seriesId' => $series->id ] )
            ->call( 'save' )
            ->assertHasErrors( 'scope' )
            ->assertNotDispatched( 'bookings-series-saved' );
    } );

    it( 'refuses a provider the site does not own before it writes anything', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        Livewire::test( SeriesEditor::class, [ 'seriesId' => $series->id ] )
            ->set( 'providerId', 999999 )
            ->call( 'save' )
            ->assertHasErrors( 'providerId' )
            ->assertNotDispatched( 'bookings-series-saved' );

        expect( $series->fresh()->isCancelled() )->toBeFalse()
            ->and( $series->occurrences()->count() )->toBeGreaterThan( 0 );
    } );

    it( 'surfaces a rule it cannot read rather than throwing', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        Livewire::test( SeriesEditor::class, [ 'seriesId' => $series->id ] )
            ->set( 'rrule', 'this-is-not-a-rule' )
            ->call( 'save' )
            ->assertHasErrors( 'scope' )
            ->assertNotDispatched( 'bookings-series-saved' );
    } );
} );

describe( 'cancelling and closing', function (): void {
    it( 'cancels the whole series through the series service', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        Livewire::test( SeriesEditor::class, [ 'seriesId' => $series->id ] )
            ->call( 'cancelSeries' )
            ->assertDispatched( 'bookings-series-cancelled', seriesId: $series->id );

        expect( $series->fresh()->isCancelled() )->toBeTrue();
    } );

    it( 'signals a plain close without saving', function (): void {
        $series = BookingSeries::factory()->create();

        Livewire::test( SeriesEditor::class, [ 'seriesId' => $series->id ] )
            ->call( 'cancel' )
            ->assertDispatched( 'bookings-series-editor-cancelled' );
    } );
} );
