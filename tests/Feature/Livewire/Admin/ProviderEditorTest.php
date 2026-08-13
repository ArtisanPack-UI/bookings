<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Admin\ProviderEditor;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'creating a provider', function (): void {
    it( 'creates a provider from the form', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Ada Lovelace' )
            ->set( 'timezone', 'America/Chicago' )
            ->set( 'roundRobinWeight', 3 )
            ->set( 'sortOrder', 2 )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->assertDispatched( 'bookings-provider-saved' );

        $provider = ServiceProvider::query()->where( 'name', 'Ada Lovelace' )->first();

        expect( $provider )->not->toBeNull()
            ->and( $provider->timezone )->toBe( 'America/Chicago' )
            ->and( $provider->round_robin_weight )->toBe( 3 )
            ->and( $provider->sort_order )->toBe( 2 );
    } );

    it( 'derives a slug from the name when none is given', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Ada Lovelace' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( ServiceProvider::query()->value( 'slug' ) )->toBe( 'ada-lovelace' );
    } );

    it( 'defaults the timezone to UTC', function (): void {
        Livewire::test( ProviderEditor::class )
            ->assertSet( 'timezone', 'UTC' );
    } );

    it( 'requires a name', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', '' )
            ->call( 'save' )
            ->assertHasErrors( [ 'name' => 'required' ] );
    } );

    it( 'refuses a duplicate slug within the site', function (): void {
        ServiceProvider::factory()->create( [ 'slug' => 'taken' ] );

        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Another' )
            ->set( 'slug', 'taken' )
            ->call( 'save' )
            ->assertHasErrors( [ 'slug' => 'unique' ] );
    } );

    it( 'rejects a timezone it does not recognise', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Zoned' )
            ->set( 'timezone', 'Not/AZone' )
            ->call( 'save' )
            ->assertHasErrors( [ 'timezone' ] );
    } );

    it( 'rejects a round-robin weight below one', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Weightless' )
            ->set( 'roundRobinWeight', 0 )
            ->call( 'save' )
            ->assertHasErrors( [ 'roundRobinWeight' ] );
    } );

    it( 'rejects a negative sort order', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Unsorted' )
            ->set( 'sortOrder', -1 )
            ->call( 'save' )
            ->assertHasErrors( [ 'sortOrder' ] );
    } );

    it( 'rejects an invalid email', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Bad email' )
            ->set( 'email', 'not-an-email' )
            ->call( 'save' )
            ->assertHasErrors( [ 'email' ] );
    } );

    it( 'requires a timezone', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Unzoned' )
            ->set( 'timezone', '' )
            ->call( 'save' )
            ->assertHasErrors( [ 'timezone' => 'required' ] );
    } );
} );

describe( 'provider metadata', function (): void {
    it( 'stores a JSON object of metadata', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'With metadata' )
            ->set( 'metadataJson', '{"specialty":"deep tissue"}' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( ServiceProvider::query()->value( 'metadata' ) )->toBe( [ 'specialty' => 'deep tissue' ] );
    } );

    it( 'rejects malformed metadata', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Broken metadata' )
            ->set( 'metadataJson', '{not json' )
            ->call( 'save' )
            ->assertHasErrors( [ 'metadataJson' ] );
    } );

    it( 'rejects metadata that is valid JSON but not an object', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Scalar metadata' )
            ->set( 'metadataJson', '"just a string"' )
            ->call( 'save' )
            ->assertHasErrors( [ 'metadataJson' ] );
    } );

    it( 'rejects metadata that is a JSON list', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'List metadata' )
            ->set( 'metadataJson', '[1, 2, 3]' )
            ->call( 'save' )
            ->assertHasErrors( [ 'metadataJson' ] );
    } );

    it( 'stores null when the metadata field is left blank', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'No metadata' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( ServiceProvider::query()->value( 'metadata' ) )->toBeNull();
    } );
} );

describe( 'the provider image', function (): void {
    it( 'stores a plain image URL when there is no media library', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Pictured' )
            ->set( 'imageUrl', 'https://example.test/ada.jpg' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( ServiceProvider::query()->value( 'image_url' ) )->toBe( 'https://example.test/ada.jpg' );
    } );

    it( 'stores an image path rooted at the site', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Rooted image' )
            ->set( 'imageUrl', '/storage/providers/ada.jpg' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( ServiceProvider::query()->value( 'image_url' ) )->toBe( '/storage/providers/ada.jpg' );
    } );

    it( 'rejects an image URL carrying a script scheme', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Dangerous image' )
            ->set( 'imageUrl', 'javascript:alert(1)' )
            ->call( 'save' )
            ->assertHasErrors( [ 'imageUrl' ] );
    } );

    it( 'rejects a media id below one', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'name', 'Bad media id' )
            ->set( 'imageMediaId', 0 )
            ->call( 'save' )
            ->assertHasErrors( [ 'imageMediaId' ] );
    } );

    it( 'asks the shared media library to open its picker', function (): void {
        Livewire::test( ProviderEditor::class )
            ->call( 'chooseImage' )
            ->assertDispatched( 'open-media-modal', context: ProviderEditor::MEDIA_CONTEXT );
    } );

    it( 'ignores an empty media selection', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'imageMediaId', 5 )
            ->dispatch( 'media-selected', media: [], context: ProviderEditor::MEDIA_CONTEXT )
            ->assertSet( 'imageMediaId', 5 );
    } );

    it( 'takes an image chosen from the media library for its own context', function (): void {
        Livewire::test( ProviderEditor::class )
            ->dispatch(
                'media-selected',
                media: [ [ 'id' => 42, 'url' => 'https://example.test/from-library.jpg' ] ],
                context: ProviderEditor::MEDIA_CONTEXT,
            )
            ->assertSet( 'imageMediaId', 42 )
            ->assertSet( 'imageUrl', 'https://example.test/from-library.jpg' );
    } );

    it( 'ignores a media selection made for another field', function (): void {
        Livewire::test( ProviderEditor::class )
            ->dispatch(
                'media-selected',
                media: [ [ 'id' => 42, 'url' => 'https://example.test/from-library.jpg' ] ],
                context: 'something-else',
            )
            ->assertSet( 'imageMediaId', null )
            ->assertSet( 'imageUrl', '' );
    } );

    it( 'clears the image back to none', function (): void {
        Livewire::test( ProviderEditor::class )
            ->set( 'imageUrl', 'https://example.test/ada.jpg' )
            ->set( 'imageMediaId', 7 )
            ->call( 'clearImage' )
            ->assertSet( 'imageUrl', '' )
            ->assertSet( 'imageMediaId', null );
    } );
} );

describe( 'editing a provider', function (): void {
    it( 'loads the provider into the form', function (): void {
        $provider = ServiceProvider::factory()->create( [
            'name'     => 'Original',
            'timezone' => 'Europe/London',
        ] );

        Livewire::test( ProviderEditor::class, [ 'providerId' => $provider->id ] )
            ->assertSet( 'name', 'Original' )
            ->assertSet( 'timezone', 'Europe/London' );
    } );

    it( 'updates the provider in place without colliding with its own slug', function (): void {
        $provider = ServiceProvider::factory()->create( [
            'name' => 'Original',
            'slug' => 'original',
        ] );

        Livewire::test( ProviderEditor::class, [ 'providerId' => $provider->id ] )
            ->set( 'name', 'Renamed' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( $provider->refresh()->name )->toBe( 'Renamed' )
            ->and( $provider->slug )->toBe( 'original' );
    } );

    it( 'loads existing metadata as a JSON string', function (): void {
        $provider = ServiceProvider::factory()->create( [
            'metadata' => [ 'specialty' => 'sports' ],
        ] );

        $component = Livewire::test( ProviderEditor::class, [ 'providerId' => $provider->id ] );

        expect( json_decode( $component->get( 'metadataJson' ), true ) )->toBe( [ 'specialty' => 'sports' ] );
    } );
} );
