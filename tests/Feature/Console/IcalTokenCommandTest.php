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

    it( 'refuses to issue a token for a provider whose feed would 404', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'slug' => 'ada', 'name' => 'Ada', 'is_active' => false ] );

        // The feed resolves through an active() query, so this would have been
        // the command printing a subscription URL that has never worked.
        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada' ] )
            ->expectsOutputToContain( 'Ada is not active' )
            ->assertFailed();

        expect( $provider->fresh()->ical_token_hash )->toBeNull();
    } );

    it( 'reads a numeric-looking slug as a slug', function (): void {
        $decoy = ServiceProvider::factory()->create( [ 'slug' => 'decoy' ] );
        $named = ServiceProvider::factory()->create( [ 'slug' => '1e3' ] );

        // is_numeric( '1e3' ) is true and (int) '1e3' is 1000, so reading the
        // argument as a number would rotate whichever provider happened to hold
        // that id — somebody else entirely.
        $this->artisan( 'bookings:ical-token', [ 'provider' => '1e3' ] )->assertSuccessful();

        expect( $named->fresh()->ical_token_hash )->not->toBeNull()
            ->and( $decoy->fresh()->ical_token_hash )->toBeNull();
    } );

    it( 'prefers a slug to an id when both could match', function (): void {
        $first = ServiceProvider::factory()->create();

        // A provider whose slug is the id of another one. The operator typed the
        // slug, so the slug is what they meant.
        $named = ServiceProvider::factory()->create( [ 'slug' => (string) $first->getKey() ] );

        $this->artisan( 'bookings:ical-token', [ 'provider' => (string) $first->getKey() ] )->assertSuccessful();

        expect( $named->fresh()->ical_token_hash )->not->toBeNull()
            ->and( $first->fresh()->ical_token_hash )->toBeNull();
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

    it( 'does not overwrite a token that appeared while the prompt was open', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'slug' => 'ada', 'name' => 'Ada' ] );

        app( IcalTokenService::class )->issueFor( $provider );

        // A second operator at another terminal, writing in the window between
        // this command reading the provider and writing to it. The read-then-write
        // this replaced would have overwritten their token without either of them
        // finding out, and they would have gone on to send a provider a URL that
        // was already dead.
        app()->instance( IcalTokenService::class, new class() extends IcalTokenService {
            public int $races = 1;

            public function issueIfUnchanged( ServiceProvider $provider, ?string $expected ): ?string
            {
                if ( $this->races > 0 ) {
                    $this->races--;

                    parent::issueFor( $provider->fresh() ?? $provider );
                }

                return parent::issueIfUnchanged( $provider, $expected );
            }
        } );

        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada' ] )
            ->expectsConfirmation( 'Replace the existing token?', 'yes' )
            ->expectsOutputToContain( 'feed token changed while that was being answered' )
            ->expectsConfirmation( 'Replace the existing token?', 'no' )
            ->expectsOutputToContain( 'Nothing was changed.' )
            ->assertSuccessful();
    } );

    it( 'gives up rather than looping forever against something that keeps rotating', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'slug' => 'ada', 'name' => 'Ada' ] );

        app( IcalTokenService::class )->issueFor( $provider );

        // Every attempt loses. A loop that could not give up would be a command
        // that hangs, and it is holding a prompt open each time round.
        app()->instance( IcalTokenService::class, new class() extends IcalTokenService {
            public function issueIfUnchanged( ServiceProvider $provider, ?string $expected ): ?string
            {
                return null;
            }
        } );

        $this->artisan( 'bookings:ical-token', [ 'provider' => 'ada', '--force' => true ] )
            ->expectsOutputToContain( 'keeps rotating that feed token' )
            ->assertFailed();
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
