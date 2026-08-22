<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Support\WidgetConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );
} );

/**
 * Builds the body the widget's plain-HTML form posts.
 *
 * @param  Service  $service  The service being booked.
 * @param  array<string, mixed>  $overrides  Anything to change.
 *
 * @return array<string, mixed> The form body.
 */
function widgetFormBody( Service $service, array $overrides = [] ): array
{
    return $overrides + [
        'service_slug'      => $service->slug,
        'start_time'        => bookingStart()->toIso8601String(),
        'customer_name'     => 'Sam Rivera',
        'customer_email'    => 'sam@example.test',
        'customer_timezone' => 'Europe/Berlin',
    ];
}

it( 'creates a booking without a line of JavaScript', function (): void {
    [ $service ] = bookableService();

    $this->from( '/book' )
        ->post( '/bookings/widget', widgetFormBody( $service ) )
        ->assertRedirect( '/book' )
        ->assertSessionHas( WidgetConfirmation::SESSION_KEY );

    $booking = Booking::query()->sole();

    expect( $booking->customer_name )->toBe( 'Sam Rivera' )
        ->and( $booking->customer_timezone )->toBe( 'Europe/Berlin' );
} );

it( 'flashes the confirmation in the shape the widget renders', function (): void {
    [ $service ] = bookableService();

    $confirmation = $this->from( '/book' )
        ->post( '/bookings/widget', widgetFormBody( $service ) )
        ->getSession()
        ->get( WidgetConfirmation::SESSION_KEY );

    expect( $confirmation )->toHaveKeys( [ 'booking_number', 'service', 'starts_at', 'timezone', 'email' ] )
        ->and( $confirmation['timezone'] )->toBe( 'Europe/Berlin' )
        ->and( $confirmation['booking_number'] )->toBe( (string) Booking::query()->sole()->booking_number );
} );

it( 'states the time in the service\'s zone when the browser reported none', function (): void {
    [ $service ] = bookableService();

    $confirmation = $this->from( '/book' )
        ->post( '/bookings/widget', widgetFormBody( $service, [ 'customer_timezone' => null ] ) )
        ->getSession()
        ->get( WidgetConfirmation::SESSION_KEY );

    expect( $confirmation['timezone'] )->toBe( $service->timezone );
} );

it( 'sends a refused submission back to the form with its answers', function (): void {
    [ $service ] = bookableService();

    $this->from( '/book' )
        ->post( '/bookings/widget', widgetFormBody( $service, [ 'customer_email' => 'not-an-address' ] ) )
        ->assertRedirect( '/book' )
        ->assertSessionHasErrors( 'customer_email' );

    expect( Booking::query()->count() )->toBe( 0 );
} );

it( 'sends the customer back to choose again when the slot has gone', function (): void {
    [ $service, $providers ] = bookableService();

    $taken = bookingStart();

    Booking::factory()
        ->for( $service, 'service' )
        ->for( $providers[0], 'provider' )
        ->create( [
            'start_time' => $taken,
            'end_time'   => $taken->addMinutes( 60 ),
        ] );

    $this->from( '/book' )
        ->post( '/bookings/widget', widgetFormBody( $service ) )
        ->assertRedirect( '/book' )
        ->assertSessionHasErrors( 'start_time' );

    expect( Booking::query()->count() )->toBe( 1 );
} );

it( 'refuses a booking for a service that has been switched off', function (): void {
    $service = Service::factory()->inactive()->create();

    $this->from( '/book' )
        ->post( '/bookings/widget', widgetFormBody( $service ) )
        ->assertNotFound();
} );

it( 'is guarded by the same rate limit the API write is', function (): void {
    config()->set( 'artisanpack.bookings.public.rate_limits.post', 1 );

    [ $service ] = bookableService();

    $this->from( '/book' )->post( '/bookings/widget', widgetFormBody( $service ) )->assertRedirect( '/book' );

    $this->from( '/book' )
        ->post( '/bookings/widget', widgetFormBody( $service, [ 'start_time' => bookingStart( '11:00' )->toIso8601String() ] ) )
        ->assertTooManyRequests();

    expect( Booking::query()->count() )->toBe( 1 );
} );

it( 'never reaches for the Livewire component, so a headless install can post to it', function (): void {
    // Livewire is a suggestion in this package, and the widget route is
    // registered whether or not it is installed. A static call into the
    // component from here would autoload a class extending `Livewire\Component`
    // — a fatal error raised *after* the booking row has been written, leaving
    // the customer a 500 and an appointment nobody was told about.
    $sources = [
        'src/Http/Controllers/Public/WidgetController.php',
        'src/Support/WidgetConfirmation.php',
        'routes/widget.php',
    ];

    foreach ( $sources as $source ) {
        // Comments stripped first: the docblocks on these files explain the
        // rule, and a test that read prose would fail for saying so.
        expect( php_strip_whitespace( dirname( __DIR__, 3 ) . '/' . $source ) )
            ->not->toContain( 'Livewire' );
    }
} );
