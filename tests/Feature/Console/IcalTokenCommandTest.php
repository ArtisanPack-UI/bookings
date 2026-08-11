<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\IcalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

afterEach( function (): void {
    removeAllActions( 'ap.bookings.icalTokenIssued' );
} );

describe( 'issuing', function (): void {
    it( 'prints a subscription URL for a provider with no feed', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'slug' => 'ada' ] );

        $issued = null;

        addAction( 'ap.bookings.icalTokenIssued', function ( ServiceProvider $p, string $token ) use ( &$issued ): void {
            $issued = $token;
        } );

        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada' ] )
            ->expectsOutputToContain( '/bookings/ical/providers/' )
            ->expectsOutputToContain( 'shown once and cannot be recovered' )
            ->assertSuccessful();

        expect( $issued )->toMatch( '/^[0-9a-f]{64}$/' )
            ->and( app( IcalTokenService::class )->findProvider( (string) $issued )?->getKey() )
            ->toBe( $provider->getKey() );
    } );

    it( 'takes the provider by id as well as by slug', function (): void {
        $provider = ServiceProvider::factory()->create();

        $this->artisan( 'bookings:ical-token', [ 'provider' => (string) $provider->getKey() ] )
            ->assertSuccessful();

        expect( $provider->fresh()->ical_token_hash )->not->toBeNull();
    } );

    it( 'does not ask before issuing a first token', function (): void {
        ServiceProvider::factory()->create( [ 'slug' => 'ada' ] );

        // There is nothing to lose yet, so a confirmation here would be a prompt
        // that never has a reason to be answered no.
        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada' ] )->assertSuccessful();
    } );

    it( 'gives up on a provider nothing answers to', function (): void {
        $this->artisan( 'bookings:ical-token', [ 'provider' => 'nobody-here' ] )
            ->expectsOutputToContain( 'No provider was found for that id or slug.' )
            ->assertFailed();
    } );
} );

describe( 'rotating', function (): void {
    it( 'warns and asks before replacing a token that is already in use', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'slug' => 'ada', 'name' => 'Ada' ] );
        $token    = app( IcalTokenService::class )->issueFor( $provider );

        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada' ] )
            ->expectsConfirmation( 'Replace the existing token?', 'no' )
            ->expectsOutputToContain( 'Nothing was changed.' )
            ->assertSuccessful();

        // Declining has to leave the existing subscriptions working.
        expect( app( IcalTokenService::class )->findProvider( $token )?->getKey() )->toBe( $provider->getKey() );
    } );

    it( 'kills the old token once the replacement is confirmed', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'slug' => 'ada' ] );
        $token    = app( IcalTokenService::class )->issueFor( $provider );

        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada' ] )
            ->expectsConfirmation( 'Replace the existing token?', 'yes' )
            ->assertSuccessful();

        expect( app( IcalTokenService::class )->findProvider( $token ) )->toBeNull();
    } );

    it( 'skips the confirmation under --force', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'slug' => 'ada' ] );
        $token    = app( IcalTokenService::class )->issueFor( $provider );

        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada', '--force' => true ] )
            ->assertSuccessful();

        expect( app( IcalTokenService::class )->findProvider( $token ) )->toBeNull();
    } );
} );

describe( 'revoking', function (): void {
    it( 'withdraws the feed once confirmed', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'slug' => 'ada', 'name' => 'Ada' ] );
        $token    = app( IcalTokenService::class )->issueFor( $provider );

        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada', '--revoke' => true ] )
            ->expectsConfirmation( 'Revoke Ada\'s feed? Every calendar subscribed to it stops updating.', 'yes' )
            ->expectsOutputToContain( 'The feed for Ada has been revoked.' )
            ->assertSuccessful();

        expect( app( IcalTokenService::class )->findProvider( $token ) )->toBeNull()
            ->and( $provider->fresh()->ical_token_hash )->toBeNull();
    } );

    it( 'leaves the feed alone when the confirmation is declined', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'slug' => 'ada', 'name' => 'Ada' ] );
        $token    = app( IcalTokenService::class )->issueFor( $provider );

        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada', '--revoke' => true ] )
            ->expectsConfirmation( 'Revoke Ada\'s feed? Every calendar subscribed to it stops updating.', 'no' )
            ->expectsOutputToContain( 'Nothing was changed.' )
            ->assertSuccessful();

        expect( app( IcalTokenService::class )->findProvider( $token )?->getKey() )->toBe( $provider->getKey() );
    } );

    it( 'says so plainly when there is no feed to revoke', function (): void {
        ServiceProvider::factory()->create( [ 'slug' => 'ada', 'name' => 'Ada' ] );

        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada', '--revoke' => true ] )
            ->expectsOutputToContain( 'Ada has no feed to revoke.' )
            ->assertSuccessful();
    } );

    it( 'still reaches a provider who has been deactivated', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'slug' => 'ada', 'name' => 'Ada', 'is_active' => false ] );

        app( IcalTokenService::class )->issueFor( $provider );

        // Revoking the token of somebody who has just left is exactly the thing
        // an operator needs to be able to do, and the feed already 404s for them.
        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada', '--revoke' => true, '--force' => true ] )
            ->assertSuccessful();

        expect( $provider->fresh()->ical_token_hash )->toBeNull();
    } );
} );
