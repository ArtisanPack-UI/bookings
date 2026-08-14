<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Livewire\Admin\NotificationsLog;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog as NotificationLogModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'listing notifications', function (): void {
    it( 'shows the notifications sent about the site\'s bookings', function (): void {
        $booking = Booking::factory()->create();
        NotificationLogModel::factory()->for( $booking )->sent()->create( [
            'recipient' => 'sam@example.test',
            'channel'   => 'mail',
        ] );

        Livewire::test( NotificationsLog::class )
            ->assertSee( $booking->booking_number )
            ->assertSee( 'sam@example.test' );
    } );

    it( 'says nothing matches when the site has no notifications', function (): void {
        Livewire::test( NotificationsLog::class )
            ->assertSee( 'No notifications match these filters.' );
    } );

    it( 'shows the transport error behind a failed send', function (): void {
        NotificationLogModel::factory()->failed( 'The mailbox is full.' )->create();

        Livewire::test( NotificationsLog::class )
            ->assertSee( 'Failed' )
            ->assertSee( 'The mailbox is full.' );
    } );

    it( 'filters by status', function (): void {
        NotificationLogModel::factory()->sent()->create( [ 'recipient' => 'went@example.test' ] );
        NotificationLogModel::factory()->failed()->create( [ 'recipient' => 'stuck@example.test' ] );

        Livewire::test( NotificationsLog::class )
            ->set( 'status', 'failed' )
            ->assertSee( 'stuck@example.test' )
            ->assertDontSee( 'went@example.test' );
    } );

    it( 'filters by lifecycle type', function (): void {
        NotificationLogModel::factory()->create( [
            'type'      => NotificationType::Confirmation,
            'recipient' => 'confirm@example.test',
        ] );
        NotificationLogModel::factory()->create( [
            'type'      => NotificationType::Reminder,
            'recipient' => 'remind@example.test',
        ] );

        Livewire::test( NotificationsLog::class )
            ->set( 'type', 'reminder' )
            ->assertSee( 'remind@example.test' )
            ->assertDontSee( 'confirm@example.test' );
    } );

    it( 'searches by recipient', function (): void {
        NotificationLogModel::factory()->create( [ 'recipient' => 'alpha@example.test' ] );
        NotificationLogModel::factory()->create( [ 'recipient' => 'omega@example.test' ] );

        Livewire::test( NotificationsLog::class )
            ->set( 'search', 'alpha' )
            ->assertSee( 'alpha@example.test' )
            ->assertDontSee( 'omega@example.test' );
    } );

    it( 'searches by the booking reference', function (): void {
        $booking = Booking::factory()->create();
        NotificationLogModel::factory()->for( $booking )->create( [ 'recipient' => 'mine@example.test' ] );
        NotificationLogModel::factory()->create( [ 'recipient' => 'other@example.test' ] );

        Livewire::test( NotificationsLog::class )
            ->set( 'search', $booking->booking_number )
            ->assertSee( 'mine@example.test' )
            ->assertDontSee( 'other@example.test' );
    } );

    it( 'filters by channel from the channels present in the log', function (): void {
        NotificationLogModel::factory()->onChannel( 'mail' )->create( [ 'recipient' => 'mailed@example.test' ] );
        NotificationLogModel::factory()->onChannel( 'database' )->create( [ 'recipient' => 'noticed@example.test' ] );

        Livewire::test( NotificationsLog::class )
            ->assertSee( 'mailed@example.test' )
            ->assertSee( 'noticed@example.test' )
            ->set( 'channel', 'mail' )
            ->assertSee( 'mailed@example.test' )
            ->assertDontSee( 'noticed@example.test' );
    } );

    it( 'returns to the first page when the search changes', function (): void {
        NotificationLogModel::factory()->count( 25 )->create();

        Livewire::test( NotificationsLog::class )
            ->call( 'gotoPage', 2 )
            ->assertSet( 'paginators.page', 2 )
            ->set( 'search', 'x' )
            ->assertSet( 'paginators.page', 1 );
    } );
} );

describe( 'tenant isolation', function (): void {
    it( 'does not list notifications about another site\'s bookings', function (): void {
        scopeToSite( 2 );
        $theirBooking = Booking::factory()->create();
        NotificationLogModel::factory()->for( $theirBooking )->create( [ 'recipient' => 'theirs@example.test' ] );

        scopeToSite( 1 );

        Livewire::test( NotificationsLog::class )
            ->assertDontSee( 'theirs@example.test' );
    } );
} );
