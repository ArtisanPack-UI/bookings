<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\NotificationStatus;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Notifications\BookingConfirmation;
use ArtisanPackUI\Bookings\Notifications\BookingNotification;
use ArtisanPackUI\Bookings\Notifications\Channels\MailChannel;
use ArtisanPackUI\Bookings\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifier;
use Tests\Concerns\TestsWithSqlite;
use Tests\Fixtures\RecordingNotificationChannel;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail' ] );
    config()->set( 'artisanpack.bookings.notifications.database.notifiable', null );

    $this->booking = Booking::factory()->create( [
        'customer_email' => 'customer@example.com',
        'customer_phone' => '+15555550123',
    ] );
} );

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.notification.sending' );
    removeAllFilters( 'ap.bookings.notification.channels' );
    removeAllFilters( 'ap.bookings.notification.subject' );
} );

/**
 * Builds a service over the given channels.
 *
 * @param  array<int, object>  $channels  The channels to register.
 */
function notifier( array $channels ): NotificationService
{
    return new NotificationService( $channels );
}

describe( 'sending', function (): void {
    it( 'sends over a configured channel and records the send', function (): void {
        Notifier::fake();

        $sent = notifier( [ new MailChannel() ] )->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toHaveCount( 1 );

        $log = NotificationLog::query()->firstOrFail();

        expect( $log->channel )->toBe( 'mail' )
            ->and( $log->type )->toBe( NotificationType::Confirmation )
            ->and( $log->recipient )->toBe( 'customer@example.com' )
            ->and( $log->status )->toBe( NotificationStatus::Sent )
            ->and( $log->sent_at )->not->toBeNull();

        Notifier::assertSentOnDemand( BookingConfirmation::class );
    } );

    it( 'skips a channel with nowhere to deliver, leaving no row behind', function (): void {
        // The claim sits after supports() for exactly this reason: an
        // unsupported channel today must not block a supported one tomorrow.
        $erased = Booking::factory()->erased()->create();

        $sent = notifier( [ new MailChannel() ] )->send( NotificationType::Confirmation, $erased );

        expect( $sent )->toBe( [] )
            ->and( NotificationLog::query()->count() )->toBe( 0 );
    } );

    it( 'skips a configured channel nothing is registered for', function (): void {
        // `webhook` ships in the default config ahead of the ticket that
        // implements it. An install that has not caught up still sends its mail.
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'webhook', 'mail' ] );
        Notifier::fake();

        $sent = notifier( [ new MailChannel() ] )->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toHaveCount( 1 )
            ->and( $sent[ 0 ]->channel )->toBe( 'mail' );
    } );

    it( 'honours a disabled message type', function (): void {
        config()->set( 'artisanpack.bookings.notifications.confirmation.enabled', false );

        $sent = notifier( [ new MailChannel() ] )->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toBe( [] )
            ->and( NotificationLog::query()->count() )->toBe( 0 );
    } );

    it( 'records a failed send and keeps the claim', function (): void {
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'recording' ] );

        $sent = notifier( [ new RecordingNotificationChannel( fails: true ) ] )
            ->send( NotificationType::Confirmation, $this->booking );

        $log = NotificationLog::query()->firstOrFail();

        expect( $sent )->toBe( [] )
            ->and( $log->status )->toBe( NotificationStatus::Failed )
            ->and( $log->error )->toContain( 'told to fail' );
    } );

    it( 'lets one dead channel through without costing the others', function (): void {
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'recording', 'mail' ] );
        Notifier::fake();

        $sent = notifier( [
            new RecordingNotificationChannel( fails: true ),
            new MailChannel(),
        ] )->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toHaveCount( 1 )
            ->and( $sent[ 0 ]->channel )->toBe( 'mail' )
            ->and( NotificationLog::query()->count() )->toBe( 2 );
    } );
} );

describe( 'idempotency', function (): void {
    it( 'claims a scheduled send once, however many times it is asked', function (): void {
        Notifier::fake();

        $moment  = $this->booking->start_time->copy()->subDay();
        $service = notifier( [ new MailChannel() ] );

        $first  = $service->send( NotificationType::Reminder, $this->booking, $moment );
        $second = $service->send( NotificationType::Reminder, $this->booking, $moment );

        expect( $first )->toHaveCount( 1 )
            ->and( $second )->toBe( [] )
            ->and( NotificationLog::query()->count() )->toBe( 1 );

        Notifier::assertSentOnDemandTimes( ArtisanPackUI\Bookings\Notifications\BookingReminder::class, 1 );
    } );

    it( 'claims per channel, so the same message reaches both', function (): void {
        // The reason `channel` is in the unique key: without it the mail claim
        // would lock the recording channel out of a send meant for both.
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail', 'recording' ] );
        Notifier::fake();

        $recording = new RecordingNotificationChannel();

        $sent = notifier( [ new MailChannel(), $recording ] )
            ->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toHaveCount( 2 )
            ->and( $recording->sent )->toHaveCount( 1 )
            ->and( NotificationLog::query()->pluck( 'channel' )->sort()->values()->all() )
            ->toBe( [ 'mail', 'recording' ] );
    } );

    it( 'does not deduplicate unscheduled sends', function (): void {
        // Two reschedules genuinely warrant two emails, and NULL scheduled_for
        // is distinct in a unique index on every supported engine.
        Notifier::fake();

        $service = notifier( [ new MailChannel() ] );

        $service->send( NotificationType::Reschedule, $this->booking );
        $service->send( NotificationType::Reschedule, $this->booking );

        expect( NotificationLog::query()->count() )->toBe( 2 );
    } );
} );

describe( 'ap.bookings.notification.channels', function (): void {
    it( 'honours a subscriber that adds a channel', function (): void {
        $recording = new RecordingNotificationChannel();

        addFilter( 'ap.bookings.notification.channels', function ( array $channels ): array {
            $channels[] = 'recording';

            return $channels;
        } );

        Notifier::fake();

        notifier( [ new MailChannel(), $recording ] )->send( NotificationType::Confirmation, $this->booking );

        expect( $recording->sent )->toHaveCount( 1 );
    } );

    it( 'honours a subscriber that removes one', function (): void {
        addFilter( 'ap.bookings.notification.channels', static fn (): array => [] );

        $sent = notifier( [ new MailChannel() ] )->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toBe( [] )
            ->and( NotificationLog::query()->count() )->toBe( 0 );
    } );

    it( 'is told which message is being sent', function (): void {
        $seen = null;

        addFilter( 'ap.bookings.notification.channels', function ( array $channels, string $event ) use ( &$seen ): array {
            $seen = $event;

            return $channels;
        } );

        Notifier::fake();

        notifier( [ new MailChannel() ] )->send( NotificationType::Reminder, $this->booking );

        expect( $seen )->toBe( 'reminder' );
    } );

    it( 'refuses a subscriber that returns something other than an array', function (): void {
        addFilter( 'ap.bookings.notification.channels', static fn (): string => 'mail' );

        notifier( [ new MailChannel() ] )->send( NotificationType::Confirmation, $this->booking );
    } )->throws( UnexpectedValueException::class );
} );

describe( 'ap.bookings.notification.sending', function (): void {
    it( 'lets a subscriber suppress a send, leaving no row behind', function (): void {
        addFilter( 'ap.bookings.notification.sending', static fn (): ?Notification => null );

        $sent = notifier( [ new MailChannel() ] )->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toBe( [] )
            ->and( NotificationLog::query()->count() )->toBe( 0 );
    } );

    it( 'lets a subscriber replace the notification that goes out', function (): void {
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'recording' ] );

        $replacement = new BookingConfirmation( $this->booking );
        $recording   = new RecordingNotificationChannel();

        addFilter( 'ap.bookings.notification.sending', static fn (): Notification => $replacement );

        notifier( [ $recording ] )->send( NotificationType::Reminder, $this->booking );

        expect( $recording->sent[ 0 ]['notification'] )->toBe( $replacement );
    } );

    it( 'hands the subscriber the notification and its booking', function (): void {
        $seen = null;

        addFilter(
            'ap.bookings.notification.sending',
            function ( BookingNotification $notification, Booking $booking ) use ( &$seen ): Notification {
                $seen = [ $notification->type(), $booking->id ];

                return $notification;
            },
        );

        Notifier::fake();

        notifier( [ new MailChannel() ] )->send( NotificationType::Confirmation, $this->booking );

        expect( $seen )->toBe( [ NotificationType::Confirmation, $this->booking->id ] );
    } );

    it( 'refuses a subscriber that returns something that is not a notification', function (): void {
        addFilter( 'ap.bookings.notification.sending', static fn (): string => 'nope' );

        notifier( [ new MailChannel() ] )->send( NotificationType::Confirmation, $this->booking );
    } )->throws( UnexpectedValueException::class );
} );

describe( 'ap.bookings.notification.subject', function (): void {
    it( 'honours a subscriber that rewrites the subject', function (): void {
        addFilter( 'ap.bookings.notification.subject', static fn (): string => 'Rewritten' );

        expect( ( new BookingConfirmation( $this->booking ) )->subject() )->toBe( 'Rewritten' );
    } );

    it( 'covers every type, discriminated by the notification', function (): void {
        addFilter(
            'ap.bookings.notification.subject',
            static fn ( string $subject, BookingNotification $notification ): string => $notification->type()->value,
        );

        expect( ( new BookingConfirmation( $this->booking ) )->subject() )->toBe( 'confirmation' )
            ->and( ( new ArtisanPackUI\Bookings\Notifications\BookingNoShow( $this->booking ) )->subject() )
            ->toBe( 'no_show' );
    } );

    it( 'refuses a subscriber that returns a non-string', function (): void {
        addFilter( 'ap.bookings.notification.subject', static fn (): array => [] );

        ( new BookingConfirmation( $this->booking ) )->subject();
    } )->throws( UnexpectedValueException::class );
} );

describe( 'the messages themselves', function (): void {
    it( 'renders the start in the customer\'s own timezone', function (): void {
        // The failure this guards against is silent and total: a customer told
        // the server's 09:00 misses an appointment they were never given the
        // right time for.
        $booking = Booking::factory()->create( [
            'customer_timezone' => 'Pacific/Auckland',
            'start_time'        => '2026-06-01 21:00:00',
            'end_time'          => '2026-06-01 21:30:00',
        ] );

        $mail = ( new BookingConfirmation( $booking ) )->toMail( null );
        $body = implode( "\n", $mail->introLines );

        // 21:00 UTC on 1 June is 09:00 the next morning in Auckland, so getting
        // this wrong moves the appointment a day as well as twelve hours.
        expect( $body )->toContain( '09:00' )
            ->and( $body )->toContain( '2 June' )
            ->and( $body )->not->toContain( '21:00' );
    } );

    it( 'carries identifiers rather than prose into the database payload', function (): void {
        $payload = ( new BookingConfirmation( $this->booking ) )->toArray( null );

        expect( $payload )->toMatchArray( [
            'type'       => 'confirmation',
            'booking_id' => $this->booking->id,
        ] )->and( $payload['starts_at'] )->toBeString();
    } );
} );
