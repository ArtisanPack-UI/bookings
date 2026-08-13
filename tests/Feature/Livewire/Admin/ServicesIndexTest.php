<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Admin\ServicesIndex;
use ArtisanPackUI\Bookings\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'listing services', function (): void {
    it( 'shows the services the site owns', function (): void {
        Service::factory()->create( [ 'name' => 'Consultation' ] );
        Service::factory()->create( [ 'name' => 'Follow-up' ] );

        Livewire::test( ServicesIndex::class )
            ->assertSee( 'Consultation' )
            ->assertSee( 'Follow-up' );
    } );

    it( 'filters the list by name', function (): void {
        Service::factory()->create( [ 'name' => 'Consultation' ] );
        Service::factory()->create( [ 'name' => 'Massage' ] );

        Livewire::test( ServicesIndex::class )
            ->set( 'search', 'Cons' )
            ->assertSee( 'Consultation' )
            ->assertDontSee( 'Massage' );
    } );

    it( 'filters the list by slug', function (): void {
        Service::factory()->create( [ 'name' => 'Consultation', 'slug' => 'blue-room' ] );
        Service::factory()->create( [ 'name' => 'Massage', 'slug' => 'green-room' ] );

        Livewire::test( ServicesIndex::class )
            ->set( 'search', 'blue-room' )
            ->assertSee( 'Consultation' )
            ->assertDontSee( 'Massage' );
    } );

    it( 'returns to the first page when the search changes', function (): void {
        Service::factory()->count( 20 )->create();

        Livewire::test( ServicesIndex::class )
            ->call( 'gotoPage', 2 )
            ->assertSet( 'paginators.page', 2 )
            ->set( 'search', 'x' )
            ->assertSet( 'paginators.page', 1 );
    } );

    it( 'hides retired services until they are asked for', function (): void {
        $retired = Service::factory()->create( [ 'name' => 'Retired service' ] );
        $retired->delete();

        Livewire::test( ServicesIndex::class )
            ->assertDontSee( 'Retired service' )
            ->set( 'trashed', true )
            ->assertSee( 'Retired service' );
    } );
} );

describe( 'acting on a service', function (): void {
    it( 'asks the host page to open the editor for a new service', function (): void {
        Livewire::test( ServicesIndex::class )
            ->call( 'create' )
            ->assertDispatched( 'bookings-edit-service', serviceId: null );
    } );

    it( 'asks the host page to open the editor for an existing one', function (): void {
        $service = Service::factory()->create();

        Livewire::test( ServicesIndex::class )
            ->call( 'edit', $service->id )
            ->assertDispatched( 'bookings-edit-service', serviceId: $service->id );
    } );

    it( 'flips a service between active and inactive', function (): void {
        $service = Service::factory()->create( [ 'is_active' => true ] );

        Livewire::test( ServicesIndex::class )
            ->call( 'toggleActive', $service->id );

        expect( $service->refresh()->is_active )->toBeFalse();
    } );

    it( 'retires a service with a soft delete rather than erasing it', function (): void {
        $service = Service::factory()->create();

        Livewire::test( ServicesIndex::class )
            ->call( 'delete', $service->id )
            ->assertDispatched( 'bookings-service-deleted', serviceId: $service->id );

        expect( Service::query()->find( $service->id ) )->toBeNull()
            ->and( Service::withTrashed()->find( $service->id ) )->not->toBeNull();
    } );

    it( 'brings a retired service back', function (): void {
        $service = Service::factory()->create();
        $service->delete();

        Livewire::test( ServicesIndex::class )
            ->set( 'trashed', true )
            ->call( 'restore', $service->id )
            ->assertDispatched( 'bookings-service-restored', serviceId: $service->id );

        expect( Service::query()->find( $service->id ) )->not->toBeNull();
    } );
} );
