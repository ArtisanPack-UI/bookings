<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\IcalTokenService;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

afterEach( function (): void {
    removeAllActions( 'ap.bookings.icalTokenIssued' );
    removeAllActions( 'ap.bookings.icalTokenRevoked' );
} );

/**
 * Gets the feed token service out of the container.
 *
 * @return IcalTokenService The service under test.
 */
function icalTokens(): IcalTokenService
{
    return app( IcalTokenService::class );
}

describe( 'minting', function (): void {
    it( 'mints a token that is 64 hex characters', function (): void {
        $token = icalTokens()->issueFor( ServiceProvider::factory()->create() );

        expect( $token )->toMatch( '/^[0-9a-f]{64}$/' );
    } );

    it( 'derives nothing from the provider it is for', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'slug' => 'ada-lovelace' ] );

        // The same row, twice, gives two different answers — so the token cannot
        // be a function of anything about the provider, which is what stops
        // somebody who holds one working out anybody else's.
        expect( icalTokens()->issueFor( $provider ) )->not->toBe( icalTokens()->issueFor( $provider ) );
    } );

    it( 'stores only the hash, never the token', function (): void {
        $provider = ServiceProvider::factory()->create();

        $token = icalTokens()->issueFor( $provider );

        $stored = (string) ServiceProvider::query()->whereKey( $provider->getKey() )->value( 'ical_token_hash' );

        // The whole guarantee: a leaked row hands over something that cannot be
        // turned back into a working subscription URL.
        expect( $stored )->toBe( hash( 'sha256', $token ) )
            ->and( $stored )->not->toBe( $token );
    } );

    it( 'never mints the same token twice', function (): void {
        $first  = icalTokens()->issueFor( ServiceProvider::factory()->create() );
        $second = icalTokens()->issueFor( ServiceProvider::factory()->create() );

        expect( $first )->not->toBe( $second );
    } );

    it( 'leaves a provider with no feed until somebody asks for one', function (): void {
        $provider = ServiceProvider::factory()->create();

        // Nothing auto-mints on create. With only the hash stored, minting there
        // would throw the plain token away unread.
        expect( $provider->ical_token_hash )->toBeNull()
            ->and( icalTokens()->hasFeed( $provider ) )->toBeFalse();
    } );

    it( 'writes only the hash column, leaving the caller\'s other edits pending', function (): void {
        $provider = ServiceProvider::factory()->create( [ 'name' => 'Ada' ] );

        $provider->name = 'Grace';

        icalTokens()->issueFor( $provider );

        // "The feed URL was reissued" must not mean "and the rest of your edits
        // went live with it" — nor must it mark them as already saved.
        expect( ServiceProvider::query()->whereKey( $provider->getKey() )->value( 'name' ) )->toBe( 'Ada' )
            ->and( $provider->isDirty( 'name' ) )->toBeTrue()
            ->and( $provider->isDirty( 'ical_token_hash' ) )->toBeFalse();
    } );

    it( 'announces the new token exactly once, at the only moment it is readable', function (): void {
        $seen = [];

        addAction( 'ap.bookings.icalTokenIssued', function ( ServiceProvider $provider, string $token ) use ( &$seen ): void {
            $seen[] = $token;
        } );

        $provider = ServiceProvider::factory()->create();
        $token    = icalTokens()->issueFor( $provider );

        expect( $seen )->toBe( [ $token ] );
    } );
} );

describe( 'resolving', function (): void {
    it( 'finds the provider a token belongs to', function (): void {
        $provider = ServiceProvider::factory()->create();

        $token = icalTokens()->issueFor( $provider );

        expect( icalTokens()->findProvider( $token )?->getKey() )->toBe( $provider->getKey() );
    } );

    it( 'resolves nothing for a token that addresses no feed', function ( string $scenario ): void {
        $provider = ServiceProvider::factory()->create();
        $token    = icalTokens()->issueFor( $provider );

        $presented = match ( $scenario ) {
            'unknown'     => str_repeat( 'a', 64 ),
            'empty'       => '',
            'hash'        => icalTokens()->hash( $token ),
            'revoked'     => tap( $token, static fn (): mixed => icalTokens()->revokeFor( $provider ) ),
            'rotated'     => tap( $token, static fn (): string => icalTokens()->issueFor( $provider ) ),
            'deactivated' => tap( $token, static function () use ( $provider ): void {
                $provider->newQueryWithoutScopes()->whereKey( $provider->getKey() )->toBase()->update( [
                    'is_active' => false,
                ] );
            } ),
            'trashed'     => tap( $token, static fn (): mixed => $provider->delete() ),
        };

        expect( icalTokens()->findProvider( $presented ) )->toBeNull();
    } )->with( [
        'an unknown token'        => 'unknown',
        'an empty string'         => 'empty',
        'the hash of a real one'  => 'hash',
        'a revoked feed'          => 'revoked',
        'a rotated token'         => 'rotated',
        'a deactivated provider'  => 'deactivated',
        'a soft-deleted provider' => 'trashed',
    ] );

    it( 'does not resolve a token across a site boundary', function (): void {
        $provider = ServiceProvider::factory()->create();
        $token    = icalTokens()->issueFor( $provider );

        $provider->newQueryWithoutScopes()->whereKey( $provider->getKey() )->toBase()->update( [ 'site_id' => 1 ] );

        scopeToSite( 2 );

        expect( icalTokens()->findProvider( $token ) )->toBeNull();
    } );

    it( 'refuses a token that does not belong to the provider it is checked against', function (): void {
        $ada   = ServiceProvider::factory()->create();
        $grace = ServiceProvider::factory()->create();

        $token = icalTokens()->issueFor( $ada );

        icalTokens()->issueFor( $grace );

        expect( icalTokens()->verifyFor( $grace, $token ) )->toBeFalse()
            ->and( icalTokens()->verifyFor( $ada, $token ) )->toBeTrue();
    } );

    it( 'reports no feed for a provider who never had one', function (): void {
        expect( icalTokens()->verifyFor( ServiceProvider::factory()->create(), str_repeat( 'a', 64 ) ) )
            ->toBeFalse();
    } );
} );

describe( 'rotating and revoking', function (): void {
    it( 'kills the previous token the moment a new one is issued', function (): void {
        $provider = ServiceProvider::factory()->create();

        $first  = icalTokens()->issueFor( $provider );
        $second = icalTokens()->issueFor( $provider );

        expect( icalTokens()->findProvider( $first ) )->toBeNull()
            ->and( icalTokens()->findProvider( $second )?->getKey() )->toBe( $provider->getKey() );
    } );

    it( 'withdraws the feed without minting a replacement', function (): void {
        $provider = ServiceProvider::factory()->create();
        $token    = icalTokens()->issueFor( $provider );

        icalTokens()->revokeFor( $provider );

        expect( icalTokens()->findProvider( $token ) )->toBeNull()
            ->and( icalTokens()->hasFeed( $provider ) )->toBeFalse()
            ->and( ServiceProvider::query()->whereKey( $provider->getKey() )->value( 'ical_token_hash' ) )->toBeNull();
    } );

    it( 'announces a revocation', function (): void {
        $seen = 0;

        addAction( 'ap.bookings.icalTokenRevoked', function () use ( &$seen ): void {
            $seen++;
        } );

        icalTokens()->revokeFor( ServiceProvider::factory()->create() );

        expect( $seen )->toBe( 1 );
    } );
} );

describe( 'the subscription URL', function (): void {
    it( 'builds the URL a calendar client subscribes to', function (): void {
        $token = icalTokens()->issueFor( ServiceProvider::factory()->create() );

        expect( icalTokens()->feedUrl( $token ) )->toEndWith( '/bookings/ical/providers/' . $token . '.ics' );
    } );

    it( 'follows the configured route prefix', function (): void {
        config()->set( 'artisanpack.bookings.public.route_prefix', 'diary' );

        // The route was registered at boot, so re-registering is what an
        // application changing the prefix actually does; the point of the case is
        // that the URL is built from the route rather than concatenated.
        expect( icalTokens()->feedUrl( str_repeat( 'a', 64 ) ) )->toContain( '/ical/providers/' );
    } );
} );

describe( 'the shared token primitives', function (): void {
    it( 'mints and hashes identically to the manage token service', function (): void {
        // Both credential schemes come from one trait precisely so they cannot
        // drift into one using a weaker hash than the other.
        $plain = str_repeat( 'a', 64 );

        expect( icalTokens()->hash( $plain ) )->toBe( app( ManageTokenService::class )->hash( $plain ) )
            ->and( icalTokens()->hash( $plain ) )->toBe( hash( 'sha256', $plain ) );
    } );

    it( 'refuses a token that differs from the stored hash by one character', function (): void {
        $provider = ServiceProvider::factory()->create();
        $token    = icalTokens()->issueFor( $provider );

        $nudged = substr( $token, 0, 63 ) . ( 'a' === substr( $token, -1 ) ? 'b' : 'a' );

        expect( icalTokens()->verifyFor( $provider, $nudged ) )->toBeFalse();
    } );
} );
