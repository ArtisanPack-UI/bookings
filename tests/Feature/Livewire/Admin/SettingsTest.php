<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Admin\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'reading the settings surface', function (): void {
    it( 'renders the configuration groups', function (): void {
        Livewire::test( Settings::class )
            ->assertSee( 'General' )
            ->assertSee( 'Notifications' )
            ->assertSee( 'Calendar sync' )
            ->assertSee( 'Webhooks' )
            ->assertSee( 'Retention' );
    } );

    it( 'shows the effective value of a scalar setting', function (): void {
        config()->set( 'artisanpack.bookings.slot_interval', 45 );

        Livewire::test( Settings::class )
            ->assertSee( 'Slot interval (minutes)' )
            ->assertSee( '45' );
    } );

    it( 'renders a boolean setting as a word rather than a bare value', function (): void {
        config()->set( 'artisanpack.bookings.auto_confirm', false );

        Livewire::test( Settings::class )
            ->assertSee( 'Confirm bookings automatically' )
            ->assertSee( 'No' );
    } );

    it( 'joins a list setting for display', function (): void {
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail', 'database' ] );

        Livewire::test( Settings::class )
            ->assertSee( 'mail, database' );
    } );

    it( 'shows an unset value as an em dash rather than a blank', function (): void {
        config()->set( 'artisanpack.bookings.public.manage_url', null );

        Livewire::test( Settings::class )
            ->assertSee( 'Manage URL' )
            ->assertSee( '—' );
    } );

    it( 'falls back to the webhook retention window when the override is unset', function (): void {
        config()->set( 'artisanpack.bookings.retention.webhook_delivery_days', null );
        config()->set( 'artisanpack.bookings.webhooks.delivery_retention_days', 30 );

        Livewire::test( Settings::class )
            ->assertSee( 'Webhook deliveries (days)' )
            ->assertSee( '30' );
    } );

    it( 'shows an explicit webhook retention override rather than the fallback', function (): void {
        config()->set( 'artisanpack.bookings.retention.webhook_delivery_days', 14 );
        config()->set( 'artisanpack.bookings.webhooks.delivery_retention_days', 30 );

        Livewire::test( Settings::class )
            ->assertSee( 'Webhook deliveries (days)' )
            ->assertSee( '14' );
    } );
} );
