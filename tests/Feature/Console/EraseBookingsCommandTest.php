<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

it( 'erases a single booking by its reference number', function (): void {
    $booking = Booking::factory()->create( [
        'customer_name'  => 'Ada Lovelace',
        'customer_email' => 'ada@example.test',
        'customer_phone' => '+15551234567',
        'intake_data'    => [ 'goal' => 'Learn to book.' ],
        'notes'          => 'Called ahead.',
    ] );

    $this->artisan( 'bookings:erase', [ '--booking' => $booking->booking_number ] )
        ->expectsOutputToContain( '1 booking(s) erased.' )
        ->assertSuccessful();

    $fresh = $booking->fresh();

    expect( $fresh->isPiiErased() )->toBeTrue()
        ->and( $fresh->customer_name )->toBe( Booking::PII_PLACEHOLDER )
        ->and( $fresh->customer_email )->not->toBe( 'ada@example.test' )
        ->and( $fresh->customer_phone )->toBeNull()
        ->and( $fresh->intake_data )->toBeNull()
        ->and( $fresh->notes )->toBeNull();
} );

it( 'erases every booking made with an email address and leaves the rest', function (): void {
    $first  = Booking::factory()->create( [ 'customer_email' => 'ada@example.test' ] );
    $second = Booking::factory()->create( [ 'customer_email' => 'ada@example.test' ] );
    $other  = Booking::factory()->create( [ 'customer_email' => 'grace@example.test' ] );

    $this->artisan( 'bookings:erase', [ '--email' => 'ada@example.test' ] )
        ->expectsOutputToContain( '2 booking(s) erased.' )
        ->assertSuccessful();

    expect( $first->fresh()->isPiiErased() )->toBeTrue()
        ->and( $second->fresh()->isPiiErased() )->toBeTrue()
        ->and( $other->fresh()->isPiiErased() )->toBeFalse();
} );

it( 'reaches a soft-deleted booking that retention pruning left behind', function (): void {
    // A pruned booking still holds intact personal data; an erasure request has
    // to be able to reach it even though it has dropped out of the default scope.
    $booking = Booking::factory()->create( [ 'customer_email' => 'ada@example.test' ] );
    $booking->delete();

    $this->artisan( 'bookings:erase', [ '--email' => 'ada@example.test' ] )
        ->expectsOutputToContain( '1 booking(s) erased.' )
        ->assertSuccessful();

    expect( Booking::withTrashed()->find( $booking->getKey() )->isPiiErased() )->toBeTrue();
} );

it( 'fails when the reference number matches no booking', function (): void {
    // A missing reference is far likelier a typo than a request already met, and
    // reporting success would tell an operator data was scrubbed when it was not.
    $this->artisan( 'bookings:erase', [ '--booking' => 'DOES-NOT-EXIST' ] )
        ->expectsOutputToContain( 'No booking found with reference DOES-NOT-EXIST.' )
        ->assertFailed();
} );

it( 'reports success and changes nothing when no booking matches the email', function (): void {
    Booking::factory()->create( [ 'customer_email' => 'grace@example.test' ] );

    $this->artisan( 'bookings:erase', [ '--email' => 'nobody@example.test' ] )
        ->expectsOutputToContain( 'No bookings with intact personal data were found for nobody@example.test.' )
        ->assertSuccessful();

    expect( Booking::notPiiErased()->count() )->toBe( 1 );
} );

it( 'is a no-op on a booking that is already erased', function (): void {
    $booking = Booking::factory()->erased()->create();

    $markedAt = $booking->pii_erased_at;

    $this->artisan( 'bookings:erase', [ '--booking' => $booking->booking_number ] )
        ->expectsOutputToContain( 'was already erased' )
        ->assertSuccessful();

    expect( $booking->fresh()->pii_erased_at->equalTo( $markedAt ) )->toBeTrue();
} );

it( 'refuses to run without exactly one selector', function ( array $options ): void {
    Booking::factory()->create( [ 'customer_email' => 'ada@example.test' ] );

    $this->artisan( 'bookings:erase', $options )
        ->expectsOutputToContain( 'Pass exactly one of --booking or --email.' )
        ->assertFailed();

    expect( Booking::notPiiErased()->count() )->toBe( 1 );
} )->with( [
    'neither'     => [ [] ],
    'both'        => [ [ '--booking' => 'ABC-123', '--email' => 'ada@example.test' ] ],
    'empty email' => [ [ '--email' => '' ] ],
] );

it( 'reports without changing anything on a dry run', function (): void {
    $booking = Booking::factory()->create( [ 'customer_email' => 'ada@example.test' ] );

    $this->artisan( 'bookings:erase', [ '--email' => 'ada@example.test', '--dry-run' => true ] )
        ->expectsOutputToContain( '1 booking(s) would be erased.' )
        ->assertSuccessful();

    expect( $booking->fresh()->isPiiErased() )->toBeFalse();
} );
