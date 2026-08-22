<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Events\BookingCancelled;
use ArtisanPackUI\Bookings\Events\BookingCompleted;
use ArtisanPackUI\Bookings\Events\BookingConfirmed;
use ArtisanPackUI\Bookings\Events\BookingNoShow;
use ArtisanPackUI\Bookings\Events\BookingReassigned;
use ArtisanPackUI\Bookings\Events\BookingRequested;
use ArtisanPackUI\Bookings\Events\BookingRescheduled;
use ArtisanPackUI\Bookings\Jobs\DispatchWebhookDelivery;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Webhook;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    Queue::fake();
} );

/**
 * Subscribes an endpoint to a set of events.
 */
function endpointFor( array $events, array $attributes = [] ): Webhook
{
    return Webhook::factory()->subscribedTo( $events )->create( $attributes );
}

it( 'fans each lifecycle event out under its own name', function ( string $name, Closure $raise ): void {
    endpointFor( [ $name ] );

    $booking = Booking::factory()->create();

    $raise( $booking );

    $delivery = WebhookDelivery::query()->sole();

    expect( $delivery->event_type )->toBe( $name )
        ->and( $delivery->payload['event'] )->toBe( $name )
        ->and( $delivery->payload['data']['booking']['id'] )->toBe( $booking->id );

    Queue::assertPushed( DispatchWebhookDelivery::class, 1 );
} )->with( [
    'booking.created'     => [
        'booking.created',
        fn ( Booking $booking ) => BookingRequested::dispatch( $booking ),
    ],
    'booking.confirmed'   => [
        'booking.confirmed',
        fn ( Booking $booking ) => BookingConfirmed::dispatch( $booking ),
    ],
    'booking.cancelled'   => [
        'booking.cancelled',
        fn ( Booking $booking ) => BookingCancelled::dispatch( $booking, BookingActor::Customer, 'Ill.' ),
    ],
    'booking.completed'   => [
        'booking.completed',
        fn ( Booking $booking ) => BookingCompleted::dispatch( $booking ),
    ],
    'booking.no_show'     => [
        'booking.no_show',
        fn ( Booking $booking ) => BookingNoShow::dispatch( $booking ),
    ],
    'booking.rescheduled' => [
        'booking.rescheduled',
        fn ( Booking $booking ) => BookingRescheduled::dispatch( $booking, new TimeRange(
            CarbonImmutable::parse( '2026-03-01 09:00' ),
            CarbonImmutable::parse( '2026-03-01 09:30' ),
        ) ),
    ],
    'booking.reassigned'  => [
        'booking.reassigned',
        fn ( Booking $booking ) => BookingReassigned::dispatch( $booking, 7 ),
    ],
] );

it( 'carries the previous provider id on a reassignment', function (): void {
    endpointFor( [ 'booking.reassigned' ] );

    $booking = Booking::factory()->create();

    BookingReassigned::dispatch( $booking, 7, BookingActor::Admin );

    $reassigned = WebhookDelivery::query()->where( 'event_type', 'booking.reassigned' )->sole();

    expect( $reassigned->payload['data']['previous_provider_id'] )->toBe( 7 )
        ->and( $reassigned->payload['data']['actor'] )->toBe( BookingActor::Admin->value );
} );

it( 'carries a null previous provider id when the booking had no provider', function (): void {
    endpointFor( [ 'booking.reassigned' ] );

    BookingReassigned::dispatch( Booking::factory()->create(), null );

    $reassigned = WebhookDelivery::query()->where( 'event_type', 'booking.reassigned' )->sole();

    expect( $reassigned->payload['data'] )->toHaveKey( 'previous_provider_id' )
        ->and( $reassigned->payload['data']['previous_provider_id'] )->toBeNull();
} );

it( 'sends nothing to an endpoint that did not subscribe to the event', function (): void {
    endpointFor( [ 'booking.cancelled' ] );

    BookingConfirmed::dispatch( Booking::factory()->create() );

    expect( WebhookDelivery::query()->count() )->toBe( 0 );

    Queue::assertNothingPushed();
} );

it( 'carries the booking, the customer, the service, and the provider', function (): void {
    endpointFor( [ 'booking.confirmed' ] );

    $booking = Booking::factory()->create( [
        'customer_name'  => 'Dana Scully',
        'customer_email' => 'dana@example.test',
    ] );

    BookingConfirmed::dispatch( $booking );

    $payload = WebhookDelivery::query()->sole()->payload['data']['booking'];

    expect( $payload['booking_number'] )->toBe( $booking->booking_number )
        ->and( $payload['status'] )->toBe( $booking->status->value )
        ->and( $payload['customer']['name'] )->toBe( 'Dana Scully' )
        ->and( $payload['customer']['email'] )->toBe( 'dana@example.test' )
        ->and( $payload['customer']['timezone'] )->toBe( $booking->customer_timezone )
        ->and( $payload['service']['id'] )->toBe( $booking->service_id )
        ->and( $payload['service']['name'] )->toBe( $booking->service->name )
        ->and( $payload['provider']['id'] )->toBe( $booking->provider_id )
        // Times go out in UTC, with the customer's own zone named beside them
        // rather than applied to them.
        ->and( $payload['start_time'] )->toBe( $booking->start_time->utc()->toIso8601String() );
} );

it( 'carries what each event adds beside the booking', function (): void {
    endpointFor( [ 'booking.cancelled', 'booking.rescheduled' ] );

    $booking = Booking::factory()->create();

    BookingCancelled::dispatch( $booking, BookingActor::Customer, 'Something came up.' );

    $cancelled = WebhookDelivery::query()->where( 'event_type', 'booking.cancelled' )->sole();

    expect( $cancelled->payload['data']['reason'] )->toBe( 'Something came up.' )
        ->and( $cancelled->payload['data']['actor'] )->toBe( BookingActor::Customer->value );

    BookingRescheduled::dispatch( $booking, new TimeRange(
        CarbonImmutable::parse( '2026-03-01 09:00', 'UTC' ),
        CarbonImmutable::parse( '2026-03-01 09:30', 'UTC' ),
    ) );

    $moved = WebhookDelivery::query()->where( 'event_type', 'booking.rescheduled' )->sole();

    expect( $moved->payload['data']['previous_period']['start'] )->toStartWith( '2026-03-01T09:00:00' );
} );

it( 'reaches every subscribed endpoint and no others', function (): void {
    $first  = endpointFor( [ 'booking.confirmed' ] );
    $second = endpointFor( [ 'booking.confirmed', 'booking.cancelled' ] );
    endpointFor( [ 'booking.confirmed' ], [ 'is_active' => false ] );
    endpointFor( [ 'booking.confirmed' ] )->disable( 'Switched off.' );

    BookingConfirmed::dispatch( Booking::factory()->create() );

    expect( WebhookDelivery::query()->pluck( 'webhook_id' )->all() )
        ->toEqualCanonicalizing( [ $first->id, $second->id ] );
} );

it( 'sends a booking only to its own site, whatever site is in context', function (): void {
    // The booking carries the site; the console has not resolved one. Left to
    // the ambient scope this would reach every tenant's endpoints, which is one
    // tenant's customer arriving at another tenant's integration.
    $ours   = endpointFor( [ 'booking.confirmed' ], [ 'site_id' => 1 ] );
    $theirs = endpointFor( [ 'booking.confirmed' ], [ 'site_id' => 2 ] );

    $booking = Booking::factory()->create();
    $booking->forceFill( [ 'site_id' => 1 ] )->save();

    BookingConfirmed::dispatch( $booking );

    $delivered = WebhookDelivery::query()->pluck( 'webhook_id' )->all();

    expect( $delivered )->toBe( [ $ours->id ] )
        ->and( $delivered )->not->toContain( $theirs->id );
} );
