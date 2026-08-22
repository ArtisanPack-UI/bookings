<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Jobs\DispatchWebhookDelivery;
use ArtisanPackUI\Bookings\Livewire\Admin\WebhookDeliveries;
use ArtisanPackUI\Bookings\Models\Webhook;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'listing deliveries', function (): void {
    it( 'shows the attempts made to the site\'s endpoints', function (): void {
        $webhook = Webhook::factory()->create( [ 'name' => 'Orders sync' ] );
        WebhookDelivery::factory()->for( $webhook )->dead()->create( [ 'event_type' => 'booking.cancelled' ] );

        Livewire::test( WebhookDeliveries::class )
            ->assertSee( 'Orders sync' )
            ->assertSee( 'booking.cancelled' );
    } );

    it( 'says nothing matches when the site has no deliveries', function (): void {
        Livewire::test( WebhookDeliveries::class )
            ->assertSee( 'No delivery attempts match these filters.' );
    } );

    it( 'narrows to one endpoint when opened for it', function (): void {
        $ours   = Webhook::factory()->create( [ 'name' => 'Ours' ] );
        $theirs = Webhook::factory()->create( [ 'name' => 'Theirs' ] );
        WebhookDelivery::factory()->for( $ours )->create( [ 'event_type' => 'ours.event' ] );
        WebhookDelivery::factory()->for( $theirs )->create( [ 'event_type' => 'theirs.event' ] );

        Livewire::test( WebhookDeliveries::class, [ 'webhookId' => $ours->id ] )
            ->assertSet( 'webhookId', $ours->id )
            ->assertSee( 'ours.event' )
            ->assertDontSee( 'theirs.event' );
    } );

    it( 'filters by status', function (): void {
        $webhook = Webhook::factory()->create();
        WebhookDelivery::factory()->for( $webhook )->dead()->create( [ 'event_type' => 'gave.up' ] );
        WebhookDelivery::factory()->for( $webhook )->success()->create( [ 'event_type' => 'went.fine' ] );

        Livewire::test( WebhookDeliveries::class )
            ->set( 'status', 'dead' )
            ->assertSee( 'gave.up' )
            ->assertDontSee( 'went.fine' );
    } );

    it( 'returns to the first page when the status changes', function (): void {
        $webhook = Webhook::factory()->create();
        WebhookDelivery::factory()->for( $webhook )->count( 25 )->create();

        Livewire::test( WebhookDeliveries::class )
            ->call( 'gotoPage', 2 )
            ->assertSet( 'paginators.page', 2 )
            ->set( 'status', 'pending' )
            ->assertSet( 'paginators.page', 1 );
    } );
} );

describe( 'inspecting a payload', function (): void {
    it( 'expands and collapses the stored payload for one delivery', function (): void {
        $webhook  = Webhook::factory()->create();
        $delivery = WebhookDelivery::factory()->for( $webhook )->create();

        Livewire::test( WebhookDeliveries::class )
            ->assertSet( 'expandedId', null )
            ->call( 'togglePayload', $delivery->id )
            ->assertSet( 'expandedId', $delivery->id )
            ->call( 'togglePayload', $delivery->id )
            ->assertSet( 'expandedId', null );
    } );
} );

describe( 'replaying a delivery', function (): void {
    it( 'queues a fresh delivery of the same event to the same endpoint', function (): void {
        Queue::fake();

        $webhook  = Webhook::factory()->create();
        $delivery = WebhookDelivery::factory()->for( $webhook )->dead()->create( [
            'event_type' => 'booking.created',
            'payload'    => [ 'id' => 42, 'type' => 'booking' ],
        ] );

        Livewire::test( WebhookDeliveries::class )
            ->call( 'replay', $delivery->id )
            ->assertDispatched( 'bookings-webhook-delivery-replayed' );

        Queue::assertPushed( DispatchWebhookDelivery::class );

        $replayed = WebhookDelivery::query()
            ->where( 'webhook_id', $webhook->id )
            ->where( 'id', '!=', $delivery->id )
            ->firstOrFail();

        expect( $replayed->event_type )->toBe( 'booking.created' )
            ->and( $replayed->payload )->toBe( [ 'id' => 42, 'type' => 'booking' ] )
            ->and( $replayed->attempt_number )->toBe( 1 );
    } );

    it( 'cannot replay a delivery whose endpoint has been deleted', function (): void {
        $webhook  = Webhook::factory()->create();
        $delivery = WebhookDelivery::factory()->for( $webhook )->dead()->create();

        $webhook->delete();

        Livewire::test( WebhookDeliveries::class )
            ->call( 'replay', $delivery->id );
    } )->throws( ModelNotFoundException::class );

    it( 'refuses to replay onto a disabled endpoint', function (): void {
        Queue::fake();

        $webhook  = Webhook::factory()->disabled()->create();
        $delivery = WebhookDelivery::factory()->for( $webhook )->dead()->create();

        Livewire::test( WebhookDeliveries::class )
            ->call( 'replay', $delivery->id )
            ->assertDispatched( 'bookings-webhook-delivery-replay-refused', deliveryId: $delivery->id );

        Queue::assertNothingPushed();

        expect( WebhookDelivery::query()->where( 'webhook_id', $webhook->id )->count() )->toBe( 1 );
    } );

    it( 'refuses to replay a delivery the consumer already accepted', function (): void {
        Queue::fake();

        $webhook  = Webhook::factory()->create();
        $delivery = WebhookDelivery::factory()->for( $webhook )->success()->create();

        Livewire::test( WebhookDeliveries::class )
            ->call( 'replay', $delivery->id )
            ->assertDispatched( 'bookings-webhook-delivery-replay-refused', deliveryId: $delivery->id );

        Queue::assertNothingPushed();

        expect( WebhookDelivery::query()->where( 'webhook_id', $webhook->id )->count() )->toBe( 1 );
    } );

    it( 'refuses to replay a delivery still pending its first attempt', function (): void {
        Queue::fake();

        $webhook  = Webhook::factory()->create();
        $delivery = WebhookDelivery::factory()->for( $webhook )->pending()->create();

        Livewire::test( WebhookDeliveries::class )
            ->call( 'replay', $delivery->id )
            ->assertDispatched( 'bookings-webhook-delivery-replay-refused', deliveryId: $delivery->id );

        Queue::assertNothingPushed();
    } );
} );

describe( 'tenant isolation', function (): void {
    it( 'cannot replay a delivery whose endpoint belongs to another site', function (): void {
        Queue::fake();

        scopeToSite( 2 );
        $theirWebhook  = Webhook::factory()->create();
        $theirDelivery = WebhookDelivery::factory()->for( $theirWebhook )->dead()->create();

        scopeToSite( 1 );

        Livewire::test( WebhookDeliveries::class )
            ->call( 'replay', $theirDelivery->id );
    } )->throws( ModelNotFoundException::class );
} );
