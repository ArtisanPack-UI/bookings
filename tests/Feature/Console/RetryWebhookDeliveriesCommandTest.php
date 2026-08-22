<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\WebhookDeliveryStatus;
use ArtisanPackUI\Bookings\Jobs\DispatchWebhookDelivery;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    Queue::fake();
} );

it( 're-queues a delivery stranded past its next attempt', function (): void {
    // The crash case: a failed row with a next attempt in the past and nothing
    // in flight to pick it up.
    $stranded = WebhookDelivery::factory()->create( [
        'status'          => WebhookDeliveryStatus::Failed,
        'next_attempt_at' => now()->subMinutes( 5 ),
    ] );

    $this->artisan( 'bookings:retry-webhook-deliveries' )
        ->expectsOutputToContain( '1 webhook delivery(s) were re-queued.' )
        ->assertSuccessful();

    Queue::assertPushed(
        DispatchWebhookDelivery::class,
        static fn ( DispatchWebhookDelivery $job ): bool => $job->deliveryId === $stranded->getKey(),
    );
} );

it( 're-queues a pending delivery whose first dispatch was lost', function (): void {
    $pending = WebhookDelivery::factory()->pending()->create();

    $this->artisan( 'bookings:retry-webhook-deliveries' )->assertSuccessful();

    Queue::assertPushed(
        DispatchWebhookDelivery::class,
        static fn ( DispatchWebhookDelivery $job ): bool => $job->deliveryId === $pending->getKey(),
    );
} );

it( 'leaves a delivery whose next attempt is still in the future alone', function (): void {
    WebhookDelivery::factory()->create( [
        'status'          => WebhookDeliveryStatus::Failed,
        'next_attempt_at' => now()->addHour(),
    ] );

    $this->artisan( 'bookings:retry-webhook-deliveries' )
        ->expectsOutputToContain( 'No webhook deliveries are due to be retried.' )
        ->assertSuccessful();

    Queue::assertNothingPushed();
} );

it( 'leaves settled deliveries alone', function (): void {
    WebhookDelivery::factory()->success()->create();
    WebhookDelivery::factory()->dead()->create();

    $this->artisan( 'bookings:retry-webhook-deliveries' )->assertSuccessful();

    Queue::assertNothingPushed();
} );
