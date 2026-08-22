<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Contracts\SmsDriver;
use ArtisanPackUI\Bookings\Enums\NotificationStatus;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Notifications\BookingCancellation;
use ArtisanPackUI\Bookings\Notifications\BookingConfirmation;
use ArtisanPackUI\Bookings\Notifications\Channels\MailChannel;
use ArtisanPackUI\Bookings\Notifications\Channels\SmsChannel;
use ArtisanPackUI\Bookings\Notifications\Sms\NullSmsDriver;
use ArtisanPackUI\Bookings\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifier;
use Tests\Concerns\TestsWithSqlite;
use Tests\Fixtures\RecordingSmsDriver;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.bookings.notifications.channels', [ 'sms' ] );
    config()->set( 'artisanpack.bookings.notifications.database.notifiable', null );

    $this->driver  = new RecordingSmsDriver();
    $this->channel = new SmsChannel( $this->driver );

    $this->booking = Booking::factory()->create( [
        'customer_email' => 'customer@example.com',
        'customer_phone' => '+15555550123',
    ] );
} );

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.notification.channels' );
} );

describe( 'the channel', function (): void {
    it( 'is configured and logged under the sms key', function (): void {
        expect( $this->channel->key() )->toBe( 'sms' );
    } );

    it( 'carries a message when there is a number to send to', function (): void {
        expect( $this->channel->supports( NotificationType::Confirmation, $this->booking ) )->toBeTrue()
            ->and( $this->channel->recipient( NotificationType::Confirmation, $this->booking ) )
            ->toBe( '+15555550123' );
    } );

    it( 'declines a booking with no number', function (): void {
        $booking = Booking::factory()->create( [ 'customer_phone' => null ] );

        expect( $this->channel->supports( NotificationType::Confirmation, $booking ) )->toBeFalse();
    } );

    it( 'declines a number that is only whitespace', function (): void {
        $booking = Booking::factory()->create( [ 'customer_phone' => '   ' ] );

        expect( $this->channel->supports( NotificationType::Confirmation, $booking ) )->toBeFalse();
    } );

    it( 'declines a booking whose personal data has been erased', function (): void {
        // `customer_phone` holds a redaction placeholder rather than a null
        // after erasure, so a reminder cron reaching an erased booking would
        // otherwise hand the placeholder to a gateway.
        $erased = Booking::factory()->erased()->create();

        expect( $this->channel->supports( NotificationType::Confirmation, $erased ) )->toBeFalse();
    } );

    it( 'hands the number and the rendered text to the driver', function (): void {
        $this->channel->send(
            NotificationType::Confirmation,
            $this->booking,
            new BookingConfirmation( $this->booking ),
        );

        expect( $this->driver->sent )->toHaveCount( 1 )
            ->and( $this->driver->sent[ 0 ]['phone'] )->toBe( '+15555550123' )
            ->and( $this->driver->sent[ 0 ]['message'] )
            ->toContain( $this->booking->booking_number );
    } );

    it( 'sends the same wording the email carries, without the greeting', function (): void {
        $notification = new BookingCancellation( $this->booking );

        $this->channel->send( NotificationType::Cancellation, $this->booking, $notification );

        expect( $this->driver->sent[ 0 ]['message'] )->toBe( $notification->toSms() )
            ->and( $this->driver->sent[ 0 ]['message'] )->not->toContain( 'Hello' );
    } );

    it( 'refuses a notification that cannot render text', function (): void {
        // A subscriber to `ap.bookings.notification.sending` may replace the
        // notification with anything. Texting the customer the word "Array" is
        // worse than a failed row an operator can read.
        $notification = new class() extends Notification {};

        $this->channel->send( NotificationType::Confirmation, $this->booking, $notification );
    } )->throws( UnexpectedValueException::class, 'toSms()' );

    it( 'refuses a notification that renders nothing', function (): void {
        $notification = new class() extends Notification {
            public function toSms(): string
            {
                return "  \n ";
            }
        };

        $this->channel->send( NotificationType::Confirmation, $this->booking, $notification );
    } )->throws( UnexpectedValueException::class, 'empty message' );

    it( 'refuses a notification whose toSms() is not a string', function (): void {
        // A replacement notification's method carries whatever return type its
        // author gave it, including none.
        $notification = new class() extends Notification {
            public function toSms(): array
            {
                return [ 'body' => 'Your appointment is confirmed.' ];
            }
        };

        $this->channel->send( NotificationType::Confirmation, $this->booking, $notification );
    } )->throws( UnexpectedValueException::class, 'must return a string' );

    it( 'throws rather than failing quietly when the gateway does', function (): void {
        $channel = new SmsChannel( new RecordingSmsDriver( fails: true ) );

        $channel->send(
            NotificationType::Confirmation,
            $this->booking,
            new BookingConfirmation( $this->booking ),
        );
    } )->throws( RuntimeException::class );
} );

describe( 'the null driver', function (): void {
    it( 'logs the message at info level and sends nothing', function (): void {
        Log::shouldReceive( 'info' )
            ->once()
            ->with( 'A booking SMS was not sent: no SMS driver is configured.', [
                'phone'   => '+15555550123',
                'message' => 'Your appointment is confirmed.',
            ] );

        ( new NullSmsDriver() )->send( '+15555550123', 'Your appointment is confirmed.' );
    } );

    it( 'is what an unconfigured installation resolves', function (): void {
        expect( app( SmsDriver::class ) )->toBeInstanceOf( NullSmsDriver::class );
    } );

    it( 'is what an explicit null setting resolves', function (): void {
        config()->set( 'artisanpack.bookings.notifications.sms_driver', 'null' );

        expect( app( SmsDriver::class ) )->toBeInstanceOf( NullSmsDriver::class );
    } );
} );

describe( 'driver configuration', function (): void {
    it( 'resolves a driver class the setting names', function (): void {
        config()->set( 'artisanpack.bookings.notifications.sms_driver', RecordingSmsDriver::class );

        expect( app( SmsDriver::class ) )->toBeInstanceOf( RecordingSmsDriver::class );
    } );

    it( 'throws on a name that resolves to nothing', function (): void {
        // Falling back to the null driver would leave every customer
        // unreachable with nothing looking wrong, which is how a typo in a
        // class name goes unnoticed for a month.
        config()->set( 'artisanpack.bookings.notifications.sms_driver', 'App\\Sms\\Nonexistent' );

        app( SmsDriver::class );
    } )->throws( InvalidArgumentException::class, 'sms_driver' );

    it( 'throws on a class that is not a driver', function (): void {
        config()->set( 'artisanpack.bookings.notifications.sms_driver', MailChannel::class );

        app( SmsDriver::class );
    } )->throws( InvalidArgumentException::class, 'sms_driver' );

    it( 'is resolved when a text is sent rather than when the channel is built', function (): void {
        // A misconfigured gateway costs one notification — recorded as failed,
        // logged, the other channels unaffected — rather than the boot.
        config()->set( 'artisanpack.bookings.notifications.sms_driver', 'App\\Sms\\Nonexistent' );

        $channel = app( SmsChannel::class );

        expect( $channel->supports( NotificationType::Confirmation, $this->booking ) )->toBeTrue();
    } );
} );

describe( 'opting the channel in', function (): void {
    it( 'is not in the shipped channel list', function (): void {
        $shipped = require __DIR__ . '/../../../config/artisanpack/bookings.php';

        expect( $shipped['notifications']['channels'] )->not->toContain( 'sms' );
    } );

    it( 'sends when configuration names it', function (): void {
        $sent = ( new NotificationService( [ $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toHaveCount( 1 )
            ->and( $sent[ 0 ]->channel )->toBe( 'sms' )
            ->and( $sent[ 0 ]->recipient )->toBe( '+15555550123' )
            ->and( $sent[ 0 ]->status )->toBe( NotificationStatus::Sent )
            ->and( $this->driver->sent )->toHaveCount( 1 );
    } );

    it( 'sends when a subscriber adds it through the channels filter', function (): void {
        // The point of the hook: a consumer shipping its own gateway opts SMS
        // in without a core change, and can do it per event.
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail' ] );
        Notifier::fake();

        $seen = [];

        addFilter(
            'ap.bookings.notification.channels',
            function ( array $channels, string $event, Booking $booking ) use ( &$seen ): array {
                $seen[] = [ $event, $booking->getKey() ];

                if ( 'cancellation' === $event ) {
                    $channels[] = 'sms';
                }

                return $channels;
            },
        );

        $service = new NotificationService( [ new MailChannel(), $this->channel ] );

        $service->send( NotificationType::Confirmation, $this->booking );

        expect( $this->driver->sent )->toBe( [] );

        $sent = $service->send( NotificationType::Cancellation, $this->booking );

        expect( $this->driver->sent )->toHaveCount( 1 )
            ->and( $sent )->toHaveCount( 2 )
            ->and( $seen )->toBe( [
                [ 'confirmation', $this->booking->getKey() ],
                [ 'cancellation', $this->booking->getKey() ],
            ] );
    } );

    it( 'records a gateway failure against the log and lets the email through', function (): void {
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail', 'sms' ] );
        Notifier::fake();

        $sent = ( new NotificationService( [
            new MailChannel(),
            new SmsChannel( new RecordingSmsDriver( fails: true ) ),
        ] ) )->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toHaveCount( 1 )
            ->and( $sent[ 0 ]->channel )->toBe( 'mail' );

        $failed = NotificationLog::query()->where( 'channel', 'sms' )->firstOrFail();

        expect( $failed->status )->toBe( NotificationStatus::Failed );
    } );

    it( 'is registered with the service the container builds', function (): void {
        $sent = app( NotificationService::class )->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toHaveCount( 1 )
            ->and( $sent[ 0 ]->channel )->toBe( 'sms' );
    } );
} );
