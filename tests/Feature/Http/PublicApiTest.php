<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Http\Middleware\RateLimitBookings;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // A Monday in the middle of the window every booking helper works inside,
    // far enough ahead of it that the booking window clips nothing.
    $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );
} );

/**
 * Builds the body the public endpoint takes for a bookable slot.
 *
 * @param  Service  $service  The service being booked.
 * @param  array<string, mixed>  $overrides  Anything to change.
 *
 * @return array<string, mixed> The request body.
 */
function publicBookingBody( Service $service, array $overrides = [] ): array
{
    return $overrides + [
        'service_slug'      => $service->slug,
        'start_time'        => bookingStart()->toIso8601String(),
        'customer_name'     => 'Sam Rivera',
        'customer_email'    => 'sam@example.test',
        'customer_timezone' => 'America/Chicago',
    ];
}

describe( 'GET services', function (): void {
    it( 'lists the services a customer can book', function (): void {
        $bookable = Service::factory()->create( [ 'name' => 'Discovery Call' ] );
        Service::factory()->inactive()->create();

        $this->getJson( '/api/bookings/services' )
            ->assertOk()
            ->assertJsonCount( 1, 'data' )
            ->assertJsonPath( 'data.0.slug', $bookable->slug )
            ->assertJsonPath( 'data.0.name', 'Discovery Call' )
            ->assertJsonPath( 'data.0.duration', (int) $bookable->duration );
    } );

    it( 'keeps the columns a customer has no business reading out of the list', function (): void {
        Service::factory()->create( [ 'metadata' => [ 'internal_margin' => 42 ] ] );

        $service = $this->getJson( '/api/bookings/services' )->json( 'data.0' );

        expect( $service )->not->toHaveKey( 'metadata' )
            ->and( $service )->not->toHaveKey( 'site_id' )
            ->and( $service )->not->toHaveKey( 'default_provider_id' )
            // The widget needs these two: one to know whether to draw a provider
            // picker, the other to draw the intake form at all.
            ->and( $service )->toHaveKey( 'assignment_strategy' )
            ->and( $service )->toHaveKey( 'intake_schema' );
    } );

    it( 'resolves the timezone a service leaves unset', function (): void {
        config()->set( 'artisanpack.bookings.timezone', 'Europe/Berlin' );

        Service::factory()->create( [ 'timezone' => null ] );

        $this->getJson( '/api/bookings/services' )
            ->assertJsonPath( 'data.0.timezone', 'Europe/Berlin' );
    } );
} );

describe( 'GET service providers', function (): void {
    it( 'lists the providers a service can be booked with', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $listed = $this->getJson( '/api/bookings/services/' . $service->slug . '/providers' )
            ->assertOk()
            ->assertJsonCount( 2, 'data' )
            ->json( 'data' );

        // Compared as a set: nothing orders the relationship, so which provider
        // lands first is the database's business rather than the contract's.
        expect( array_column( $listed, 'id' ) )
            ->toEqualCanonicalizing( [ (int) $providers[0]->getKey(), (int) $providers[1]->getKey() ] );
    } );

    it( 'leaves a deactivated provider off the list', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $providers[1]->forceFill( [ 'is_active' => false ] )->save();

        $this->getJson( '/api/bookings/services/' . $service->slug . '/providers' )
            ->assertJsonCount( 1, 'data' )
            ->assertJsonPath( 'data.0.id', (int) $providers[0]->getKey() );
    } );

    it( 'keeps a provider\'s contact details off an unauthenticated endpoint', function (): void {
        [ $service ] = bookableService();

        $provider = $this->getJson( '/api/bookings/services/' . $service->slug . '/providers' )->json( 'data.0' );

        expect( $provider )->not->toHaveKey( 'email' )
            ->and( $provider )->not->toHaveKey( 'phone' );
    } );

    it( 'does not answer for a service that has been switched off', function (): void {
        $service = Service::factory()->inactive()->create();

        $this->getJson( '/api/bookings/services/' . $service->slug . '/providers' )
            ->assertNotFound();
    } );

    it( 'does not name the package\'s internals when it gives up', function (): void {
        $body = $this->getJson( '/api/bookings/services/no-such-service/providers' )
            ->assertNotFound()
            ->json();

        // What firstOrFail() would have said here is "No query results for model
        // [ArtisanPackUI\Bookings\Models\Service]", handed to anybody who
        // mistypes a slug.
        expect( $body['message'] ?? '' )->not->toContain( 'ArtisanPackUI' )
            ->and( $body['message'] ?? '' )->not->toContain( 'model' );
    } );
} );

describe( 'GET service slots', function (): void {
    it( 'resolves the bookable slots in a month', function (): void {
        [ $service ] = bookableService();

        $response = $this->getJson( '/api/bookings/services/' . $service->slug . '/slots?date=2026-06' )
            ->assertOk();

        $slots = $response->json( 'data' );

        // Five Mondays in June 2026, eight hourly slots in each nine-to-five.
        expect( $slots )->toHaveCount( 40 )
            ->and( $slots[0]['start'] )->toBe(
                CarbonImmutable::parse( '2026-06-01 09:00', 'America/Chicago' )->utc()->toIso8601String(),
            );
    } );

    it( 'narrows the slots to one provider when asked', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $slots = $this->getJson( sprintf(
            '/api/bookings/services/%s/slots?date=2026-06&provider_id=%d',
            $service->slug,
            $providers[1]->getKey(),
        ) )->assertOk()->json( 'data' );

        expect( $slots )->not->toBeEmpty()
            ->and( array_unique( array_column( $slots, 'provider_id' ) ) )
            ->toBe( [ (int) $providers[1]->getKey() ] );
    } );

    it( 'refuses a provider who does not offer the service', function (): void {
        [ $service ] = bookableService();
        $stranger    = ServiceProvider::factory()->create();

        $this->getJson( sprintf(
            '/api/bookings/services/%s/slots?date=2026-06&provider_id=%d',
            $service->slug,
            $stranger->getKey(),
        ) )->assertUnprocessable()->assertJsonValidationErrors( 'provider_id' );
    } );

    it( 'offers nothing from a month that has already been and gone', function (): void {
        [ $service ] = bookableService();

        $this->getJson( '/api/bookings/services/' . $service->slug . '/slots?date=2026-04' )
            ->assertOk()
            ->assertExactJson( [ 'data' => [] ] );
    } );

    it( 'stops at the far end of the booking window', function (): void {
        [ $service ] = bookableService();

        config()->set( 'artisanpack.bookings.booking_window.max_advance_minutes', 60 * 24 * 10 );

        // Ten days from 25 May reaches 4 June, so only the first Monday of the
        // month is inside the window.
        $slots = $this->getJson( '/api/bookings/services/' . $service->slug . '/slots?date=2026-06' )
            ->assertOk()
            ->json( 'data' );

        expect( $slots )->toHaveCount( 8 );
    } );

    it( 'offers the whole month when the maximum is non-positive', function ( int|string $value ): void {
        [ $service ] = bookableService();

        // Non-positive means "no maximum": the far end must not collapse to now
        // and empty the month. Ten days would leave 8 slots; no limit leaves more.
        config()->set( 'artisanpack.bookings.booking_window.max_advance_minutes', $value );

        $slots = $this->getJson( '/api/bookings/services/' . $service->slug . '/slots?date=2026-06' )
            ->assertOk()
            ->json( 'data' );

        expect( count( $slots ) )->toBeGreaterThan( 8 );
    } )->with( [
        'a blank string'       => '',
        'a non-numeric string' => 'off',
        'a string zero'        => '0',
        'an integer zero'      => 0,
        'a negative'           => -60,
    ] );

    it( 'offers the whole month when the maximum is missing entirely', function (): void {
        [ $service ] = bookableService();

        // A key removed from a partially published config falls to the default,
        // which must read as no maximum the same way a blank one does.
        config()->set( 'artisanpack.bookings.booking_window', [ 'min_advance_minutes' => 60 ] );

        $slots = $this->getJson( '/api/bookings/services/' . $service->slug . '/slots?date=2026-06' )
            ->assertOk()
            ->json( 'data' );

        expect( count( $slots ) )->toBeGreaterThan( 8 );
    } );

    it( 'refuses a date that is not a month', function ( string $date ): void {
        [ $service ] = bookableService();

        $this->getJson( '/api/bookings/services/' . $service->slug . '/slots?date=' . $date )
            ->assertUnprocessable()
            ->assertJsonValidationErrors( 'date' );
    } )->with( [
        'a day'              => '2026-06-01',
        'a thirteenth month' => '2026-13',
        'a two digit year'   => '26-06',
        'prose'              => 'next+month',
    ] );

    it( 'requires a date at all', function (): void {
        [ $service ] = bookableService();

        $this->getJson( '/api/bookings/services/' . $service->slug . '/slots' )
            ->assertUnprocessable()
            ->assertJsonValidationErrors( 'date' );
    } );
} );

describe( 'POST bookings', function (): void {
    it( 'creates a booking from a public submission', function (): void {
        [ $service, $providers ] = bookableService();

        $response = $this->postJson( '/api/bookings', publicBookingBody( $service ) )
            ->assertCreated()
            ->assertJsonPath( 'data.status', BookingStatus::Confirmed->value )
            ->assertJsonPath( 'data.service.slug', $service->slug )
            ->assertJsonPath( 'data.provider.id', (int) $providers[0]->getKey() );

        $booking = Booking::query()->findOrFail( $response->json( 'data.id' ) );

        expect( $booking->customer_email )->toBe( 'sam@example.test' )
            ->and( $booking->start_time->equalTo( bookingStart() ) )->toBeTrue();
    } );

    it( 'never hands back the manage token it just minted', function (): void {
        [ $service ] = bookableService();

        $response = $this->postJson( '/api/bookings', publicBookingBody( $service ) )->assertCreated();

        expect( $response->json( 'data' ) )->not->toHaveKey( 'manage_token' )
            ->and( $response->json( 'data' ) )->not->toHaveKey( 'manage_token_hash' );
    } );

    it( 'takes the provider the customer picked', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $this->postJson( '/api/bookings', publicBookingBody( $service, [
            'provider_id' => $providers[1]->getKey(),
        ] ) )
            ->assertCreated()
            ->assertJsonPath( 'data.provider.id', (int) $providers[1]->getKey() );
    } );

    it( 'refuses a provider who does not offer the service', function (): void {
        [ $service ] = bookableService();
        $stranger    = ServiceProvider::factory()->create();

        $this->postJson( '/api/bookings', publicBookingBody( $service, [
            'provider_id' => $stranger->getKey(),
        ] ) )->assertUnprocessable()->assertJsonValidationErrors( 'provider_id' );
    } );

    it( 'refuses a submission missing the customer', function (): void {
        [ $service ] = bookableService();

        $body = publicBookingBody( $service );
        unset( $body['customer_name'], $body['customer_email'] );

        $this->postJson( '/api/bookings', $body )
            ->assertUnprocessable()
            ->assertJsonValidationErrors( [ 'customer_name', 'customer_email' ] );
    } );

    it( 'strips markup out of what it stores', function (): void {
        [ $service ] = bookableService();

        $service->forceFill( [
            'intake_schema' => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'text', 'label' => 'Your goal' ],
                    [
                        'name'    => 'topics',
                        'type'    => 'checkboxes',
                        'label'   => 'Topics',
                        'options' => [ 'Fitness', 'Nutrition' ],
                    ],
                ],
            ],
        ] )->save();

        $response = $this->postJson( '/api/bookings', publicBookingBody( $service, [
            'customer_name' => '<script>alert(1)</script>Sam Rivera',
            'notes'         => 'Please <b>call</b> ahead',
            'intake_data'   => [
                'goal'   => '<i>Get</i> fitter',
                'topics' => [ '<b>Fitness</b>' ],
            ],
        ] ) )->assertCreated();

        $booking = Booking::query()->findOrFail( $response->json( 'data.id' ) );

        expect( $booking->customer_name )->toBe( 'alert(1)Sam Rivera' )
            ->and( $booking->notes )->toBe( 'Please call ahead' )
            ->and( $booking->intake_data['goal'] )->toBe( 'Get fitter' )
            // Nested answers are cleaned too; the security package's own
            // sanitizeArray() only reaches one level down.
            ->and( $booking->intake_data['topics'] )->toBe( [ 'Fitness' ] );
    } );

    it( 'refuses a name that was nothing but markup', function (): void {
        [ $service ] = bookableService();

        $this->postJson( '/api/bookings', publicBookingBody( $service, [
            'customer_name' => '<script></script>',
        ] ) )->assertUnprocessable()->assertJsonValidationErrors( 'customer_name' );
    } );

    it( 'ignores fields a customer has no business setting', function (): void {
        [ $service ] = bookableService();

        $response = $this->postJson( '/api/bookings', publicBookingBody( $service, [
            'status'       => BookingStatus::Completed->value,
            'series_id'    => 99,
            'series_index' => 7,
        ] ) )->assertCreated();

        $booking = Booking::query()->findOrFail( $response->json( 'data.id' ) );

        expect( $booking->status )->toBe( BookingStatus::Confirmed )
            ->and( $booking->series_id )->toBeNull()
            ->and( $booking->series_index )->toBeNull();
    } );

    it( 'refuses a time outside the window the installation books in', function ( string $start ): void {
        [ $service ] = bookableService();

        $this->postJson( '/api/bookings', publicBookingBody( $service, [
            'start_time' => CarbonImmutable::parse( $start, 'UTC' )->toIso8601String(),
        ] ) )->assertUnprocessable()->assertJsonValidationErrors( 'start_time' );
    } )->with( [
        'already gone'    => '2026-05-04 15:00:00',
        'inside the hour' => '2026-05-25 12:30:00',
        'years ahead'     => '2029-06-01 15:00:00',
    ] );

    it( 'takes a submission when the maximum is non-positive', function ( int|string $value ): void {
        [ $service ] = bookableService();

        // The default submission is a week out. Under a maximum that collapsed to
        // now, it would be refused as "too far ahead" for a time days away; a
        // non-positive maximum means no limit, so it is taken.
        config()->set( 'artisanpack.bookings.booking_window.max_advance_minutes', $value );

        $this->postJson( '/api/bookings', publicBookingBody( $service ) )->assertCreated();
    } )->with( [
        'a blank string'       => '',
        'a non-numeric string' => 'off',
        'a string zero'        => '0',
        'an integer zero'      => 0,
        'a negative'           => -60,
    ] );

    it( 'takes a submission when the maximum is missing entirely', function (): void {
        [ $service ] = bookableService();

        // The default booking is a week out; a missing maximum falls to the
        // default and must not refuse it as too far ahead.
        config()->set( 'artisanpack.bookings.booking_window', [ 'min_advance_minutes' => 60 ] );

        $this->postJson( '/api/bookings', publicBookingBody( $service ) )->assertCreated();
    } );

    it( 'answers a slot somebody else has taken with a conflict', function (): void {
        [ $service ] = bookableService();

        $this->postJson( '/api/bookings', publicBookingBody( $service ) )->assertCreated();

        $this->postJson( '/api/bookings', publicBookingBody( $service, [
            'customer_email' => 'alex@example.test',
        ] ) )->assertConflict();
    } );

    it( 'does not answer for a service that has been switched off', function (): void {
        [ $service ] = bookableService();

        $service->forceFill( [ 'is_active' => false ] )->save();

        $this->postJson( '/api/bookings', publicBookingBody( $service ) )->assertNotFound();
    } );

    it( 'refuses a submission carrying more answers than any form asks for', function ( array $answers ): void {
        [ $service ] = bookableService();

        $this->postJson( '/api/bookings', publicBookingBody( $service, [ 'intake_data' => $answers ] ) )
            ->assertUnprocessable()
            ->assertJsonValidationErrors( 'intake_data' );
    } )->with( [
        // Wide at the top, which the `max` rule would catch on its own.
        'a hundred and one answers' => fn (): array => array_fill( 0, 101, 'x' ),
        // One key holding the same load, which it would not: the whole point of
        // counting every value at every depth rather than the top level.
        'one answer holding them'   => fn (): array => [ 'goal' => array_fill( 0, 101, 'x' ) ],
    ] );

    it( 'reports refused intake answers against the fields they came from', function (): void {
        [ $service ] = bookableService();

        $service->forceFill( [
            'intake_schema' => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'text', 'label' => 'Your goal', 'required' => true ],
                ],
            ],
        ] )->save();

        $this->postJson( '/api/bookings', publicBookingBody( $service, [ 'intake_data' => [] ] ) )
            ->assertUnprocessable()
            ->assertJsonValidationErrors( 'intake_data.goal' );
    } );
} );

describe( 'rate limiting', function (): void {
    it( 'refuses the sixth booking attempt in a minute', function (): void {
        [ $service ] = bookableService();

        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $this->postJson( '/api/bookings', [] )->assertUnprocessable();
        }

        $this->postJson( '/api/bookings', publicBookingBody( $service ) )
            ->assertStatus( 429 )
            ->assertHeader( 'Retry-After' );
    } );

    it( 'takes the limit from configuration', function (): void {
        config()->set( 'artisanpack.bookings.public.rate_limits.post', 2 );

        $this->postJson( '/api/bookings', [] )->assertUnprocessable();
        $this->postJson( '/api/bookings', [] )->assertUnprocessable();
        $this->postJson( '/api/bookings', [] )->assertStatus( 429 );
    } );

    it( 'refuses to guard a bucket it has no limit for', function (): void {
        $middleware = app( RateLimitBookings::class );

        expect( fn (): mixed => $middleware->handle(
            Request::create( '/api/bookings', 'POST' ),
            static fn (): Response => new Response(),
            'not_a_bucket',
        ) )->toThrow( InvalidArgumentException::class );
    } );

    it( 'leaves the read endpoints alone', function (): void {
        Service::factory()->create();

        for ( $attempt = 0; $attempt < 8; $attempt++ ) {
            $this->getJson( '/api/bookings/services' )->assertOk();
        }
    } );
} );
