<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\ServiceAssignmentStrategy;
use ArtisanPackUI\Bookings\Livewire\Admin\ServiceEditor;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'creating a service', function (): void {
    it( 'creates a service from the form', function (): void {
        Livewire::test( ServiceEditor::class )
            ->set( 'name', 'Deep Tissue Massage' )
            ->set( 'duration', 60 )
            ->set( 'price', '80.00' )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->assertDispatched( 'bookings-service-saved' );

        $service = Service::query()->where( 'name', 'Deep Tissue Massage' )->first();

        expect( $service )->not->toBeNull()
            ->and( $service->duration )->toBe( 60 )
            ->and( (string) $service->price )->toBe( '80.00' );
    } );

    it( 'derives a slug from the name when none is given', function (): void {
        Livewire::test( ServiceEditor::class )
            ->set( 'name', 'Deep Tissue Massage' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( Service::query()->value( 'slug' ) )->toBe( 'deep-tissue-massage' );
    } );

    it( 'clears the price for a free service', function (): void {
        Livewire::test( ServiceEditor::class )
            ->set( 'name', 'Intro call' )
            ->set( 'price', '25.00' )
            ->set( 'isFree', true )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( Service::query()->value( 'price' ) )->toBeNull();
    } );

    it( 'requires a name', function (): void {
        Livewire::test( ServiceEditor::class )
            ->set( 'name', '' )
            ->call( 'save' )
            ->assertHasErrors( [ 'name' => 'required' ] );
    } );

    it( 'refuses a duplicate slug within the site', function (): void {
        Service::factory()->create( [ 'slug' => 'taken' ] );

        Livewire::test( ServiceEditor::class )
            ->set( 'name', 'Another' )
            ->set( 'slug', 'taken' )
            ->call( 'save' )
            ->assertHasErrors( [ 'slug' => 'unique' ] );
    } );

    it( 'rejects a colour that is not a hex code', function (): void {
        Livewire::test( ServiceEditor::class )
            ->set( 'name', 'Coloured' )
            ->set( 'color', 'blue' )
            ->call( 'save' )
            ->assertHasErrors( [ 'color' ] );
    } );

    it( 'rejects a price that is not a number', function (): void {
        Livewire::test( ServiceEditor::class )
            ->set( 'name', 'Priced' )
            ->set( 'price', 'lots' )
            ->call( 'save' )
            ->assertHasErrors( [ 'price' ] );
    } );

    it( 'rejects a timezone it does not recognise', function (): void {
        Livewire::test( ServiceEditor::class )
            ->set( 'name', 'Zoned' )
            ->set( 'timezone', 'Not/AZone' )
            ->call( 'save' )
            ->assertHasErrors( [ 'timezone' ] );
    } );
} );

describe( 'the default provider', function (): void {
    it( 'rejects a provider that does not exist', function (): void {
        Livewire::test( ServiceEditor::class )
            ->set( 'name', 'Bad provider' )
            ->set( 'defaultProviderId', 999999 )
            ->call( 'save' )
            ->assertHasErrors( [ 'defaultProviderId' ] );
    } );

    it( 'refuses the default-provider strategy with no provider chosen', function (): void {
        Livewire::test( ServiceEditor::class )
            ->set( 'name', 'Strategy without provider' )
            ->set( 'assignmentStrategy', ServiceAssignmentStrategy::DefaultProvider->value )
            ->set( 'defaultProviderId', null )
            ->call( 'save' )
            ->assertHasErrors( [ 'defaultProviderId' ] );
    } );

    it( 'clears the default provider back to none', function (): void {
        $provider = ServiceProvider::factory()->create();
        $service  = Service::factory()->create( [ 'default_provider_id' => $provider->id ] );

        Livewire::test( ServiceEditor::class, [ 'serviceId' => $service->id ] )
            ->assertSet( 'defaultProviderId', $provider->id )
            ->set( 'defaultProviderId', null )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( $service->refresh()->default_provider_id )->toBeNull();
    } );
} );

describe( 'editing a service', function (): void {
    it( 'loads the service into the form', function (): void {
        $service = Service::factory()->create( [
            'name'     => 'Original',
            'duration' => 45,
        ] );

        Livewire::test( ServiceEditor::class, [ 'serviceId' => $service->id ] )
            ->assertSet( 'name', 'Original' )
            ->assertSet( 'duration', 45 );
    } );

    it( 'updates the service in place without collising with its own slug', function (): void {
        $service = Service::factory()->create( [
            'name' => 'Original',
            'slug' => 'original',
        ] );

        Livewire::test( ServiceEditor::class, [ 'serviceId' => $service->id ] )
            ->set( 'name', 'Renamed' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( $service->refresh()->name )->toBe( 'Renamed' )
            ->and( $service->slug )->toBe( 'original' );
    } );

    it( 'assigns a default provider', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( ServiceEditor::class )
            ->set( 'name', 'With provider' )
            ->set( 'assignmentStrategy', ServiceAssignmentStrategy::DefaultProvider->value )
            ->set( 'defaultProviderId', $provider->id )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( Service::query()->value( 'default_provider_id' ) )->toBe( $provider->id );
    } );
} );
