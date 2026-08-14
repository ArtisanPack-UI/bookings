<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Admin\CalendarConnectionsIndex;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'listing connections', function (): void {
    it( 'shows the calendars the site owns with their provider and error', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'name' => 'Dr. Ada Rivera' ] );
        CalendarConnection::factory()->for( $provider, 'provider' )->failing()->create( [
            'external_calendar_id' => 'ada@example.test',
        ] );

        Livewire::test( CalendarConnectionsIndex::class )
            ->assertSee( 'Dr. Ada Rivera' )
            ->assertSee( 'ada@example.test' )
            ->assertSee( 'The remote calendar returned 503.' );
    } );

    it( 'invites nothing when the site has no connections', function (): void {
        Livewire::test( CalendarConnectionsIndex::class )
            ->assertSee( 'No calendar connections yet.' );
    } );

    it( 'filters to the connections that need attention', function (): void {
        CalendarConnection::factory()->create( [ 'external_calendar_id' => 'healthy@example.test' ] );
        CalendarConnection::factory()->disabled()->create( [ 'external_calendar_id' => 'broken@example.test' ] );

        Livewire::test( CalendarConnectionsIndex::class )
            ->set( 'unhealthy', true )
            ->assertSee( 'broken@example.test' )
            ->assertDontSee( 'healthy@example.test' );
    } );

    it( 'searches by the calendar identifier', function (): void {
        CalendarConnection::factory()->create( [ 'external_calendar_id' => 'sales@example.test' ] );
        CalendarConnection::factory()->create( [ 'external_calendar_id' => 'support@example.test' ] );

        Livewire::test( CalendarConnectionsIndex::class )
            ->set( 'search', 'sales' )
            ->assertSee( 'sales@example.test' )
            ->assertDontSee( 'support@example.test' );
    } );

    it( 'searches by the provider name', function (): void {
        $rivera = ServiceProvider::factory()->create( [ 'name' => 'Dr. Ada Rivera' ] );
        $chen   = ServiceProvider::factory()->create( [ 'name' => 'Dr. Bo Chen' ] );
        CalendarConnection::factory()->for( $rivera, 'provider' )->create( [ 'external_calendar_id' => 'rivera-cal@example.test' ] );
        CalendarConnection::factory()->for( $chen, 'provider' )->create( [ 'external_calendar_id' => 'chen-cal@example.test' ] );

        Livewire::test( CalendarConnectionsIndex::class )
            ->set( 'search', 'Rivera' )
            ->assertSee( 'rivera-cal@example.test' )
            ->assertDontSee( 'chen-cal@example.test' );
    } );

    it( 'returns to the first page when the search changes', function (): void {
        CalendarConnection::factory()->count( 20 )->create();

        Livewire::test( CalendarConnectionsIndex::class )
            ->call( 'gotoPage', 2 )
            ->assertSet( 'paginators.page', 2 )
            ->set( 'search', 'x' )
            ->assertSet( 'paginators.page', 1 );
    } );
} );

describe( 'reconnecting a connection', function (): void {
    it( 'clears the failure state so the next sweep tries again', function (): void {
        $connection = CalendarConnection::factory()->disabled()->failing()->create();

        Livewire::test( CalendarConnectionsIndex::class )
            ->call( 'reconnect', $connection->id )
            ->assertDispatched( 'bookings-calendar-connection-reconnected', connectionId: $connection->id );

        $connection->refresh();

        expect( $connection->is_active )->toBeTrue()
            ->and( $connection->isDisabled() )->toBeFalse()
            ->and( $connection->consecutive_failure_count )->toBe( 0 )
            ->and( $connection->last_sync_error )->toBeNull()
            ->and( $connection->sync_token )->toBeNull();
    } );
} );

describe( 'disabling a connection', function (): void {
    it( 'stops syncing a live connection', function (): void {
        $connection = CalendarConnection::factory()->create();

        Livewire::test( CalendarConnectionsIndex::class )
            ->call( 'disable', $connection->id )
            ->assertDispatched( 'bookings-calendar-connection-disabled', connectionId: $connection->id );

        $connection->refresh();

        expect( $connection->isDisabled() )->toBeTrue()
            ->and( $connection->is_active )->toBeFalse();
    } );
} );

describe( 'tenant isolation', function (): void {
    it( 'cannot reconnect a connection belonging to another site', function (): void {
        scopeToSite( 2 );
        $theirs = CalendarConnection::factory()->disabled()->create();

        scopeToSite( 1 );

        Livewire::test( CalendarConnectionsIndex::class )
            ->call( 'reconnect', $theirs->id );
    } )->throws( ModelNotFoundException::class );

    it( 'does not list another site\'s connections', function (): void {
        scopeToSite( 2 );
        CalendarConnection::factory()->create( [ 'external_calendar_id' => 'theirs@example.test' ] );

        scopeToSite( 1 );

        Livewire::test( CalendarConnectionsIndex::class )
            ->assertDontSee( 'theirs@example.test' );
    } );
} );
