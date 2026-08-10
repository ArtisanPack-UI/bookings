<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

it( 'rotates every manage token once confirmed', function (): void {
    $booking = Booking::factory()->create();
    $token   = $booking->pullPlainManageToken();

    $this->artisan( 'bookings:reissue-detached-manage-tokens' )
        ->expectsConfirmation( 'Rotate every manage token?', 'yes' )
        ->expectsOutputToContain( '1 manage token(s) rotated.' )
        ->assertSuccessful();

    expect( app( ManageTokenService::class )->findBooking( $token ) )->toBeNull();
} );

it( 'leaves every token alone when the confirmation is declined', function (): void {
    $booking = Booking::factory()->create();
    $token   = $booking->pullPlainManageToken();

    $this->artisan( 'bookings:reissue-detached-manage-tokens' )
        ->expectsConfirmation( 'Rotate every manage token?', 'no' )
        ->expectsOutputToContain( 'Nothing was rotated.' )
        ->assertSuccessful();

    expect( app( ManageTokenService::class )->findBooking( $token )?->is( $booking ) )->toBeTrue();
} );

it( 'skips the confirmation under --force', function (): void {
    $booking = Booking::factory()->create();
    $token   = $booking->pullPlainManageToken();

    $this->artisan( 'bookings:reissue-detached-manage-tokens', [ '--force' => true ] )
        ->assertSuccessful();

    expect( app( ManageTokenService::class )->findBooking( $token ) )->toBeNull();
} );

it( 'rotates in the chunk size it was given', function (): void {
    $bookings = Booking::factory()->count( 4 )->create();
    $hashes   = $bookings->pluck( 'manage_token_hash' );

    $this->artisan( 'bookings:reissue-detached-manage-tokens', [ '--force' => true, '--chunk' => '2' ] )
        ->expectsOutputToContain( '4 manage token(s) rotated.' )
        ->assertSuccessful();

    expect( Booking::query()->pluck( 'manage_token_hash' )->intersect( $hashes ) )->toBeEmpty();
} );

it( 'still rotates when the chunk option is nonsense', function (): void {
    // Somebody running this is dealing with a leak. A typo in a tuning knob is
    // no reason to refuse to rotate.
    $booking = Booking::factory()->create();
    $token   = $booking->pullPlainManageToken();

    $this->artisan( 'bookings:reissue-detached-manage-tokens', [ '--force' => true, '--chunk' => 'lots' ] )
        ->expectsOutputToContain( '1 manage token(s) rotated.' )
        ->assertSuccessful();

    expect( app( ManageTokenService::class )->findBooking( $token ) )->toBeNull();
} );
