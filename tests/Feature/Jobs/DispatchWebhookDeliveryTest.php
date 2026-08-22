<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\WebhookDeliveryStatus;
use ArtisanPackUI\Bookings\Jobs\DispatchWebhookDelivery;
use ArtisanPackUI\Bookings\Models\Webhook;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * Runs the job for a delivery, the way a queue worker would.
 */
function runDeliveryJob( WebhookDelivery $delivery ): void
{
    app()->call( [ new DispatchWebhookDelivery( (int) $delivery->getKey() ), 'handle' ] );
}

it( 'attempts the delivery and queues nothing more when it succeeds', function (): void {
    Http::fake( [ '*' => Http::response( 'ok', 200 ) ] );
    Queue::fake();

    $delivery = WebhookDelivery::factory()->pending()->create();

    runDeliveryJob( $delivery );

    expect( $delivery->refresh()->status )->toBe( WebhookDeliveryStatus::Success );

    Queue::assertNothingPushed();
} );

it( 'queues its own retry with the delay the ledger scheduled', function (): void {
    Http::fake( [ '*' => Http::response( 'Internal Server Error', 500 ) ] );
    Queue::fake();
    config()->set( 'artisanpack.bookings.webhooks.delivery_backoff_minutes', [ 5 ] );

    $delivery = WebhookDelivery::factory()->pending()->create();

    runDeliveryJob( $delivery );

    expect( $delivery->refresh()->status )->toBe( WebhookDeliveryStatus::Failed );

    Queue::assertPushed(
        DispatchWebhookDelivery::class,
        static fn ( DispatchWebhookDelivery $job ): bool => $job->deliveryId === $delivery->id
            // Five minutes, within the second the attempt itself took.
            && $job->delay >= 295 && $job->delay <= 300,
    );
} );

it( 'queues nothing more once the delivery is dead', function (): void {
    Http::fake( [ '*' => Http::response( 'Internal Server Error', 500 ) ] );
    Queue::fake();
    config()->set( 'artisanpack.bookings.webhooks.delivery_backoff_minutes', [] );

    $delivery = WebhookDelivery::factory()->pending()->create();

    runDeliveryJob( $delivery );

    expect( $delivery->refresh()->status )->toBe( WebhookDeliveryStatus::Dead );

    Queue::assertNothingPushed();
} );

it( 'does nothing for a delivery that has been pruned away', function (): void {
    Http::fake( [ '*' => Http::response( 'ok', 200 ) ] );
    Queue::fake();

    // A queued job outliving its row is what retention pruning produces, and it
    // has to be nothing to do rather than a failed job.
    app()->call( [ new DispatchWebhookDelivery( 999999 ), 'handle' ] );

    Http::assertNothingSent();
    Queue::assertNothingPushed();
} );

it( 'does nothing for a delivery it cannot claim', function (): void {
    Http::fake( [ '*' => Http::response( 'ok', 200 ) ] );
    Queue::fake();

    // A failed delivery whose next attempt is still in the future is not due, so
    // the claim fails and the job steps aside — this is what stops the retry
    // sweep and an in-flight attempt from both sending the same event.
    $delivery = WebhookDelivery::factory()->create( [
        'status'          => WebhookDeliveryStatus::Failed,
        'next_attempt_at' => now()->addHour(),
    ] );

    runDeliveryJob( $delivery );

    Http::assertNothingSent();
    Queue::assertNothingPushed();
} );

it( 'does nothing for a delivery that already succeeded', function (): void {
    Http::fake( [ '*' => Http::response( 'ok', 200 ) ] );
    Queue::fake();

    $delivery = WebhookDelivery::factory()->success()->create();

    runDeliveryJob( $delivery );

    Http::assertNothingSent();
    Queue::assertNothingPushed();
} );

it( 'pushes onto the configured webhook queue', function (): void {
    Queue::fake();
    config()->set( 'artisanpack.bookings.webhooks.queue', 'webhooks' );

    $webhook = Webhook::factory()->subscribedTo( [ 'booking.confirmed' ] )->create();

    app( ArtisanPackUI\Bookings\Services\WebhookDispatcher::class )
        ->queue( $webhook, 'booking.confirmed', [ 'id' => 1 ] );

    Queue::assertPushedOn( 'webhooks', DispatchWebhookDelivery::class );
} );
