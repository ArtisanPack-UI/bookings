<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Admin\WebhooksIndex;
use ArtisanPackUI\Bookings\Models\Webhook;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'listing webhooks', function (): void {
    it( 'shows the endpoints the site owns', function (): void {
        Webhook::factory()->create( [ 'name' => 'Zapier catch', 'url' => 'https://hooks.example.test/zapier' ] );

        Livewire::test( WebhooksIndex::class )
            ->assertSee( 'Zapier catch' )
            ->assertSee( 'https://hooks.example.test/zapier' );
    } );

    it( 'says the list is empty when the site has no endpoints', function (): void {
        Livewire::test( WebhooksIndex::class )
            ->assertSee( 'No webhooks yet.' );
    } );

    it( 'filters to the disabled endpoints', function (): void {
        Webhook::factory()->create( [ 'name' => 'Live endpoint' ] );
        Webhook::factory()->disabled()->create( [ 'name' => 'Retired endpoint' ] );

        Livewire::test( WebhooksIndex::class )
            ->set( 'disabledOnly', true )
            ->assertSee( 'Retired endpoint' )
            ->assertDontSee( 'Live endpoint' );
    } );

    it( 'searches by name and URL', function (): void {
        Webhook::factory()->create( [ 'name' => 'Billing sync', 'url' => 'https://billing.example.test/hook' ] );
        Webhook::factory()->create( [ 'name' => 'Analytics', 'url' => 'https://analytics.example.test/hook' ] );

        Livewire::test( WebhooksIndex::class )
            ->set( 'search', 'billing' )
            ->assertSee( 'Billing sync' )
            ->assertDontSee( 'Analytics' );
    } );

    it( 'returns to the first page when the search changes', function (): void {
        Webhook::factory()->count( 20 )->create();

        Livewire::test( WebhooksIndex::class )
            ->call( 'gotoPage', 2 )
            ->assertSet( 'paginators.page', 2 )
            ->set( 'search', 'x' )
            ->assertSet( 'paginators.page', 1 );
    } );
} );

describe( 'enabling a webhook', function (): void {
    it( 'brings a disabled endpoint back with its failures cleared', function (): void {
        $webhook = Webhook::factory()->disabled()->failing()->create();

        Livewire::test( WebhooksIndex::class )
            ->call( 'enable', $webhook->id )
            ->assertDispatched( 'bookings-webhook-enabled', webhookId: $webhook->id );

        $webhook->refresh();

        expect( $webhook->is_active )->toBeTrue()
            ->and( $webhook->isDisabled() )->toBeFalse()
            ->and( $webhook->consecutive_failure_count )->toBe( 0 );
    } );
} );

describe( 'disabling a webhook', function (): void {
    it( 'stops delivering to a live endpoint', function (): void {
        $webhook = Webhook::factory()->create();

        Livewire::test( WebhooksIndex::class )
            ->call( 'disable', $webhook->id )
            ->assertDispatched( 'bookings-webhook-disabled', webhookId: $webhook->id );

        expect( $webhook->refresh()->isDisabled() )->toBeTrue();
    } );
} );

describe( 'opening the delivery ledger', function (): void {
    it( 'hands the endpoint off to the host page', function (): void {
        $webhook = Webhook::factory()->create();

        Livewire::test( WebhooksIndex::class )
            ->call( 'viewDeliveries', $webhook->id )
            ->assertDispatched( 'bookings-view-webhook-deliveries', webhookId: $webhook->id );
    } );
} );

describe( 'tenant isolation', function (): void {
    it( 'cannot enable an endpoint belonging to another site', function (): void {
        scopeToSite( 2 );
        $theirs = Webhook::factory()->disabled()->create();

        scopeToSite( 1 );

        Livewire::test( WebhooksIndex::class )
            ->call( 'enable', $theirs->id );
    } )->throws( ModelNotFoundException::class );
} );
