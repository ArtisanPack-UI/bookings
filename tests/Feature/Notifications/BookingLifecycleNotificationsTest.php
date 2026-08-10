<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Notifications\BookingCancellation;
use ArtisanPackUI\Bookings\Notifications\BookingConfirmation;
use ArtisanPackUI\Bookings\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as Notifier;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail' ] );
    config()->set( 'artisanpack.bookings.notifications.database.notifiable', null );
} );

describe( 'the lifecycle listener', function (): void {
    it( 'sends a confirmation when a booking is confirmed', function (): void {
        Notifier::fake();

        $booking = Booking::factory()->requested()->create( [ 'customer_email' => 'customer@example.com' ] );

        app( BookingService::class )->confirm( $booking );

        Notifier::assertSentOnDemandTimes( BookingConfirmation::class, 1 );

        expect( NotificationLog::query()->where( 'type', NotificationType::Confirmation->value )->count() )
            ->toBe( 1 );
    } );

    it( 'sends a cancellation notice when a booking is cancelled', function (): void {
        Notifier::fake();

        $booking = Booking::factory()->confirmed()->create( [ 'customer_email' => 'customer@example.com' ] );

        app( BookingService::class )->cancel( $booking, BookingActor::Customer );

        Notifier::assertSentOnDemandTimes( BookingCancellation::class, 1 );
    } );

    it( 'does not confirm-mail a booking that is merely requested', function (): void {
        // A requested booking is not yet an appointment. Telling the customer it
        // is confirmed before anybody has confirmed it is the failure here.
        Notifier::fake();

        Booking::factory()->requested()->create( [ 'customer_email' => 'customer@example.com' ] );

        Notifier::assertNothingSent();
    } );

    it( 'leaves the booking committed when a channel throws', function (): void {
        // The listener runs after commit, and a failed send is recorded rather
        // than rethrown — a booking must not be undone because SMTP is down.
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail' ] );

        Notifier::fake();
        Notifier::shouldReceive( 'route' )->andThrow( new RuntimeException( 'smtp is down' ) );

        $booking = Booking::factory()->requested()->create( [ 'customer_email' => 'customer@example.com' ] );

        app( BookingService::class )->confirm( $booking );

        expect( $booking->fresh()->status->value )->toBe( 'confirmed' );
    } );
} );
