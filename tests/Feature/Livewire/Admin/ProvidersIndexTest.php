<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Admin\ProvidersIndex;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'listing providers', function (): void {
    it( 'shows the providers the site owns', function (): void {
        ServiceProvider::factory()->create( [ 'name' => 'Ada Provider' ] );
        ServiceProvider::factory()->create( [ 'name' => 'Grace Provider' ] );

        Livewire::test( ProvidersIndex::class )
            ->assertSee( 'Ada Provider' )
            ->assertSee( 'Grace Provider' );
    } );

    it( 'filters the list by name', function (): void {
        ServiceProvider::factory()->create( [ 'name' => 'Ada Provider', 'slug' => 'ada-provider', 'email' => 'ada@example.test' ] );
        ServiceProvider::factory()->create( [ 'name' => 'Grace Provider', 'slug' => 'grace-provider', 'email' => 'grace@example.test' ] );

        Livewire::test( ProvidersIndex::class )
            ->set( 'search', 'Ada' )
            ->assertSee( 'Ada Provider' )
            ->assertDontSee( 'Grace Provider' );
    } );

    it( 'filters the list by slug', function (): void {
        ServiceProvider::factory()->create( [ 'name' => 'Ada Provider', 'slug' => 'blue-room', 'email' => 'ada@example.test' ] );
        ServiceProvider::factory()->create( [ 'name' => 'Grace Provider', 'slug' => 'green-room', 'email' => 'grace@example.test' ] );

        Livewire::test( ProvidersIndex::class )
            ->set( 'search', 'blue-room' )
            ->assertSee( 'Ada Provider' )
            ->assertDontSee( 'Grace Provider' );
    } );

    it( 'filters the list by email', function (): void {
        ServiceProvider::factory()->create( [ 'name' => 'Ada Provider', 'slug' => 'ada-provider', 'email' => 'blue@example.test' ] );
        ServiceProvider::factory()->create( [ 'name' => 'Grace Provider', 'slug' => 'grace-provider', 'email' => 'green@example.test' ] );

        Livewire::test( ProvidersIndex::class )
            ->set( 'search', 'blue@example.test' )
            ->assertSee( 'Ada Provider' )
            ->assertDontSee( 'Grace Provider' );
    } );

    it( 'returns to the first page when the search changes', function (): void {
        ServiceProvider::factory()->count( 20 )->create();

        Livewire::test( ProvidersIndex::class )
            ->call( 'gotoPage', 2 )
            ->assertSet( 'paginators.page', 2 )
            ->set( 'search', 'x' )
            ->assertSet( 'paginators.page', 1 );
    } );

    it( 'hides retired providers until they are asked for', function (): void {
        $retired = ServiceProvider::factory()->create( [ 'name' => 'Retired Provider' ] );
        $retired->delete();

        Livewire::test( ProvidersIndex::class )
            ->assertDontSee( 'Retired Provider' )
            ->set( 'trashed', true )
            ->assertSee( 'Retired Provider' );
    } );
} );

describe( 'acting on a provider', function (): void {
    it( 'asks the host page to open the editor for a new provider', function (): void {
        Livewire::test( ProvidersIndex::class )
            ->call( 'create' )
            ->assertDispatched( 'bookings-edit-provider', providerId: null );
    } );

    it( 'asks the host page to open the editor for an existing one', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( ProvidersIndex::class )
            ->call( 'edit', $provider->id )
            ->assertDispatched( 'bookings-edit-provider', providerId: $provider->id );
    } );

    it( 'asks the host page to open the availability editor', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( ProvidersIndex::class )
            ->call( 'editAvailability', $provider->id )
            ->assertDispatched( 'bookings-edit-provider-availability', providerId: $provider->id );
    } );

    it( 'flips a provider between active and inactive', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'is_active' => true ] );

        Livewire::test( ProvidersIndex::class )
            ->call( 'toggleActive', $provider->id );

        expect( $provider->refresh()->is_active )->toBeFalse();
    } );

    it( 'retires a provider with a soft delete rather than erasing them', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( ProvidersIndex::class )
            ->call( 'delete', $provider->id )
            ->assertDispatched( 'bookings-provider-deleted', providerId: $provider->id );

        expect( ServiceProvider::query()->find( $provider->id ) )->toBeNull()
            ->and( ServiceProvider::withTrashed()->find( $provider->id ) )->not->toBeNull();
    } );

    it( 'brings a retired provider back', function (): void {
        $provider = ServiceProvider::factory()->create();
        $provider->delete();

        Livewire::test( ProvidersIndex::class )
            ->set( 'trashed', true )
            ->call( 'restore', $provider->id )
            ->assertDispatched( 'bookings-provider-restored', providerId: $provider->id );

        expect( ServiceProvider::query()->find( $provider->id ) )->not->toBeNull();
    } );
} );
