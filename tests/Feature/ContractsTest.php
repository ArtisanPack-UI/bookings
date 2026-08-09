<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Contracts\CalendarSyncDriver;
use ArtisanPackUI\Bookings\Contracts\NotificationChannel;
use ArtisanPackUI\Bookings\Contracts\RoundRobinStrategy;
use ArtisanPackUI\Bookings\Contracts\SlotResolver;
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\TestsWithSqlite;
use Tests\Fixtures\FixedSlotResolver;
use Tests\Fixtures\InMemoryCalendarSyncDriver;
use Tests\Fixtures\LeastBusyRoundRobinStrategy;
use Tests\Fixtures\RecordingNotificationChannel;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'SlotResolver', function (): void {
    it( 'is satisfied by an application implementation', function (): void {
        $resolver = new FixedSlotResolver( 45 );
        $service  = Service::factory()->create();
        $provider = ServiceProvider::factory()->create();

        $slots = $resolver->resolve( $service, $provider, contractWindow() );

        expect( $resolver )->toBeInstanceOf( SlotResolver::class )
            ->and( $slots )->toHaveCount( 1 )
            ->and( $slots[ 0 ] )->toBeInstanceOf( Slot::class )
            ->and( $slots[ 0 ]->providerId )->toBe( $provider->id )
            ->and( $slots[ 0 ]->period->minutes() )->toBe( 45 );
    } );

    it( 'may resolve without a provider', function (): void {
        $slots = ( new FixedSlotResolver() )->resolve( Service::factory()->create(), null, contractWindow() );

        expect( $slots[ 0 ]->providerId )->toBeNull();
    } );

    it( 'returns nothing when no slot fits the window', function (): void {
        $window = new TimeRange(
            Carbon::parse( '2026-04-06 09:00:00', 'UTC' ),
            Carbon::parse( '2026-04-06 09:10:00', 'UTC' ),
        );

        expect( ( new FixedSlotResolver() )->resolve( Service::factory()->create(), null, $window ) )->toBe( [] );
    } );
} );

describe( 'RoundRobinStrategy', function (): void {
    it( 'is satisfied by an application implementation', function (): void {
        $service = Service::factory()->create();
        $busy    = ServiceProvider::factory()->create();
        $idle    = ServiceProvider::factory()->create();

        Booking::factory()->count( 2 )->for( $busy, 'provider' )->create();

        $slot = new Slot( contractWindow() );

        $selected = ( new LeastBusyRoundRobinStrategy() )->select( [ $busy, $idle ], $service, $slot );

        expect( new LeastBusyRoundRobinStrategy() )->toBeInstanceOf( RoundRobinStrategy::class )
            ->and( $selected?->id )->toBe( $idle->id );
    } );
} );

describe( 'NotificationChannel', function (): void {
    it( 'is satisfied by an application implementation', function (): void {
        $channel = new RecordingNotificationChannel();
        $booking = Booking::factory()->create( [ 'customer_phone' => '+15555550123' ] );

        expect( $channel )->toBeInstanceOf( NotificationChannel::class )
            ->and( $channel->key() )->toBe( 'recording' )
            ->and( $channel->supports( NotificationType::Confirmation, $booking ) )->toBeTrue();

        $channel->send( NotificationType::Confirmation, $booking );

        expect( $channel->sent )->toHaveCount( 1 )
            ->and( $channel->sent[ 0 ]['booking_id'] )->toBe( $booking->id );
    } );

    it( 'declines a booking it has nowhere to send to', function (): void {
        $booking = Booking::factory()->create( [ 'customer_phone' => null ] );

        expect( ( new RecordingNotificationChannel() )->supports( NotificationType::Reminder, $booking ) )->toBeFalse();
    } );

    it( 'throws rather than failing quietly', function (): void {
        $channel = new RecordingNotificationChannel( fails: true );

        $channel->send( NotificationType::Reminder, Booking::factory()->create() );
    } )->throws( RuntimeException::class );
} );

describe( 'CalendarSyncDriver', function (): void {
    it( 'is satisfied by an application implementation', function (): void {
        $driver     = new InMemoryCalendarSyncDriver();
        $connection = CalendarConnection::factory()->create();
        $booking    = Booking::factory()->create();

        $externalEventId = $driver->createEvent( $connection, $booking );

        expect( $driver )->toBeInstanceOf( CalendarSyncDriver::class )
            ->and( $driver->driver() )->toBe( CalendarDriver::Ical )
            ->and( $driver->eventCount() )->toBe( 1 )
            ->and( $driver->events[ $externalEventId ]->start->equalTo( $booking->start_time ) )->toBeTrue();
    } );

    it( 'writes the same event twice without duplicating it', function (): void {
        $driver     = new InMemoryCalendarSyncDriver();
        $connection = CalendarConnection::factory()->create();
        $booking    = Booking::factory()->create();

        $driver->createEvent( $connection, $booking );
        $driver->createEvent( $connection, $booking );

        expect( $driver->eventCount() )->toBe( 1 );
    } );

    it( 'treats deleting a missing event as a success', function (): void {
        $driver = new InMemoryCalendarSyncDriver();

        $driver->deleteEvent( CalendarConnection::factory()->create(), 'evt-does-not-exist' );

        expect( $driver->eventCount() )->toBe( 0 );
    } );

    it( 'reads back only the busy periods touching the window', function (): void {
        $driver       = new InMemoryCalendarSyncDriver();
        $driver->busy = [
            new TimeRange( Carbon::parse( '2026-04-06 10:00:00', 'UTC' ), Carbon::parse( '2026-04-06 11:00:00', 'UTC' ) ),
            new TimeRange( Carbon::parse( '2026-04-07 10:00:00', 'UTC' ), Carbon::parse( '2026-04-07 11:00:00', 'UTC' ) ),
        ];

        $busy = $driver->busyPeriods( CalendarConnection::factory()->create(), contractWindow() );

        expect( $busy )->toHaveCount( 1 )
            ->and( $busy[ 0 ]->start->toDateString() )->toBe( '2026-04-06' );
    } );
} );
