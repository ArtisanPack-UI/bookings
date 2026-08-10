<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

afterEach( function (): void {
    removeAllActions( 'ap.bookings.manageTokenReissued' );
    removeAllActions( 'ap.bookings.manageTokensReissued' );
} );

/**
 * Resolves the service under test.
 */
function manageTokens(): ManageTokenService
{
    return app( ManageTokenService::class );
}

describe( 'minting', function (): void {
    it( 'mints a 64 character token alongside its sha256 hash', function (): void {
        $minted = manageTokens()->mint();

        expect( $minted['token'] )->toHaveLength( 64 )
            ->and( $minted['token'] )->toMatch( '/^[0-9a-f]{64}$/' )
            ->and( $minted['hash'] )->toBe( hash( 'sha256', $minted['token'] ) );
    } );

    it( 'never mints the same token twice', function (): void {
        $tokens = [];

        for ( $i = 0; $i < 50; $i++ ) {
            $tokens[] = manageTokens()->mint()['token'];
        }

        expect( array_unique( $tokens ) )->toHaveCount( 50 );
    } );

    it( 'is what the model mints on create', function (): void {
        // One implementation, not two: a booking minted through Eloquent has to
        // carry a token this service would recognise, or a manage link would
        // work in some parts of the package and not others.
        $booking = Booking::factory()->create();
        $token   = $booking->pullPlainManageToken();

        expect( $token )->toHaveLength( 64 )
            ->and( manageTokens()->verifyFor( $booking, $token ) )->toBeTrue();
    } );
} );

describe( 'verification', function (): void {
    it( 'accepts the token its hash was made from', function (): void {
        $minted = manageTokens()->mint();

        expect( manageTokens()->verify( $minted['token'], $minted['hash'] ) )->toBeTrue();
    } );

    it( 'rejects any other token', function ( string $wrong ): void {
        $minted = manageTokens()->mint();

        expect( manageTokens()->verify( $wrong, $minted['hash'] ) )->toBeFalse();
    } )->with( [
        'a different token' => [ '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef' ],
        'an empty string'   => [ '' ],
        'a truncation'      => [ '0123456789abcdef' ],
    ] );

    it( 'rejects the stored hash presented as the token', function (): void {
        // Somebody who has read the column must not be able to replay it as a
        // manage link — which is the entire reason the column holds a hash.
        $minted = manageTokens()->mint();

        expect( manageTokens()->verify( $minted['hash'], $minted['hash'] ) )->toBeFalse();
    } );

    it( 'compares in constant time rather than with ==', function (): void {
        // A `==` comparison of two hash strings in PHP is type juggling waiting
        // to happen: "0e123" == "0e456" is true for any two numeric-exponent
        // strings, and sha256 output is hex, so such a pair is reachable. This
        // asserts the guard rather than the timing, which cannot be measured
        // reliably in a test.
        $source = new ReflectionMethod( ManageTokenService::class, 'verify' );
        $body   = implode( '', array_slice(
            file( $source->getFileName() ),
            $source->getStartLine() - 1,
            $source->getEndLine() - $source->getStartLine() + 1,
        ) );

        expect( $body )->toContain( 'hash_equals' );
    } );
} );

describe( 'finding a booking', function (): void {
    it( 'finds the booking a token manages', function (): void {
        $booking = Booking::factory()->create();
        $token   = $booking->pullPlainManageToken();

        expect( manageTokens()->findBooking( $token )?->is( $booking ) )->toBeTrue();
    } );

    it( 'returns null for a token nobody was issued', function (): void {
        Booking::factory()->create();

        expect( manageTokens()->findBooking( 'not-a-real-token' ) )->toBeNull();
    } );
} );

describe( 'issuing a replacement', function (): void {
    it( 'replaces the stored hash and hands back the new plain token', function (): void {
        $booking = Booking::factory()->create();
        $before  = $booking->manage_token_hash;
        $old     = $booking->pullPlainManageToken();

        $new = manageTokens()->issueFor( $booking );

        expect( $new )->not->toBe( $old )
            ->and( $booking->fresh()->manage_token_hash )->not->toBe( $before )
            ->and( manageTokens()->findBooking( $new )?->is( $booking ) )->toBeTrue()
            ->and( manageTokens()->findBooking( $old ) )->toBeNull();
    } );

    it( 'does not persist whatever else the caller had pending', function (): void {
        // "The manage link was reissued" must not also mean "and the half-filled
        // form you had open went live with it".
        $booking = Booking::factory()->create();

        $booking->customer_name = 'Not saved yet';

        manageTokens()->issueFor( $booking );

        expect( $booking->fresh()->customer_name )->not->toBe( 'Not saved yet' )
            ->and( $booking->isDirty( 'customer_name' ) )->toBeTrue();

        // And the pending edit is still savable afterwards.
        $booking->save();

        expect( $booking->fresh()->customer_name )->toBe( 'Not saved yet' );
    } );

    it( 'gives an unsaved booking the token it will be created with', function (): void {
        $booking = Booking::factory()->make();

        $token = manageTokens()->issueFor( $booking );

        $booking->save();

        expect( manageTokens()->findBooking( $token )?->is( $booking ) )->toBeTrue();
    } );
} );

describe( 'the emergency reissue', function (): void {
    it( 'rotates every booking and reports how many', function (): void {
        $bookings = Booking::factory()->count( 3 )->create();
        $tokens   = $bookings->map( fn ( Booking $booking ): string => $booking->pullPlainManageToken() );
        $hashes   = $bookings->pluck( 'manage_token_hash' );

        $rotated = manageTokens()->reissueAll();

        expect( $rotated )->toBe( 3 );

        foreach ( $tokens as $token ) {
            expect( manageTokens()->findBooking( $token ) )->toBeNull();
        }

        expect( Booking::query()->pluck( 'manage_token_hash' )->intersect( $hashes ) )->toBeEmpty();
    } );

    it( 'rotates across every site, not only the one in context', function (): void {
        scopeToSite( 1 );
        $mine = Booking::factory()->create();

        scopeToSite( 2 );
        $theirs = Booking::factory()->create();

        $mineHash   = $mine->manage_token_hash;
        $theirsHash = $theirs->manage_token_hash;

        expect( manageTokens()->reissueAll() )->toBe( 2 )
            ->and( $mine->fresh()->manage_token_hash )->not->toBe( $mineHash )
            ->and( $theirs->fresh()->manage_token_hash )->not->toBe( $theirsHash );
    } );

    it( 'rotates every booking when the chunk size is smaller than the table', function (): void {
        $bookings = Booking::factory()->count( 5 )->create();
        $hashes   = $bookings->pluck( 'manage_token_hash' );

        expect( manageTokens()->reissueAll( 2 ) )->toBe( 5 )
            ->and( Booking::query()->pluck( 'manage_token_hash' )->intersect( $hashes ) )->toBeEmpty();
    } );

    it( 'hands each new plain token to listeners so the links can be re-sent', function (): void {
        $booking = Booking::factory()->create();
        $seen    = [];

        addAction( 'ap.bookings.manageTokenReissued', function ( Booking $rotated, string $token ) use ( &$seen ): void {
            $seen[ $rotated->getKey() ] = $token;
        } );

        manageTokens()->reissueAll();

        expect( $seen )->toHaveKey( $booking->getKey() )
            ->and( manageTokens()->findBooking( $seen[ $booking->getKey() ] )?->is( $booking ) )->toBeTrue();
    } );

    it( 'announces the total once it has finished', function (): void {
        Booking::factory()->count( 2 )->create();
        $announced = null;

        addAction( 'ap.bookings.manageTokensReissued', function ( int $count ) use ( &$announced ): void {
            $announced = $count;
        } );

        manageTokens()->reissueAll();

        expect( $announced )->toBe( 2 );
    } );

    it( 'leaves updated_at alone', function (): void {
        // A rotation is not an edit of the appointment, and an admin list sorted
        // by last-changed should not be reordered wholesale by one.
        $booking = Booking::factory()->create();
        $touched = $booking->updated_at;

        manageTokens()->reissueAll();

        expect( $booking->fresh()->updated_at->equalTo( $touched ) )->toBeTrue();
    } );

    it( 'counts nothing when there are no bookings', function (): void {
        expect( manageTokens()->reissueAll() )->toBe( 0 );
    } );
} );
