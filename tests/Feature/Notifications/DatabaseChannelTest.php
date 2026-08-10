<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Notifications\BookingConfirmation;
use ArtisanPackUI\Bookings\Notifications\Channels\DatabaseChannel;
use ArtisanPackUI\Bookings\Notifications\Channels\MailChannel;
use ArtisanPackUI\Bookings\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as Notifier;
use Tests\Concerns\TestsWithSqlite;
use Tests\Fixtures\NotifiableAdmin;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    NotifiableAdmin::createTable();

    config()->set( 'artisanpack.bookings.notifications.channels', [ 'database' ] );
    config()->set( 'artisanpack.bookings.notifications.database.notifiable', NotifiableAdmin::class );
    config()->set( 'artisanpack.bookings.notifications.database.ids', [] );

    $this->booking = Booking::factory()->create( [ 'customer_email' => 'customer@example.com' ] );
    $this->channel = new DatabaseChannel();
} );

describe( 'when the application has not named its admins', function (): void {
    it( 'reports itself unsupported rather than guessing', function (): void {
        expect( $this->channel->supports( NotificationType::Confirmation, $this->booking ) )->toBeFalse();
    } );

    it( 'reports itself unsupported when no notifiable class is set', function (): void {
        config()->set( 'artisanpack.bookings.notifications.database.notifiable', null );
        config()->set( 'artisanpack.bookings.notifications.database.ids', [ 1 ] );

        expect( $this->channel->supports( NotificationType::Confirmation, $this->booking ) )->toBeFalse();
    } );

    it( 'reports itself unsupported when the class does not exist', function (): void {
        // A config copied between projects, or a model since renamed. The cost
        // of being wrong here is an admin row that does not appear; throwing
        // would cost the customer their confirmation.
        config()->set( 'artisanpack.bookings.notifications.database.notifiable', 'App\\Models\\Nonexistent' );
        config()->set( 'artisanpack.bookings.notifications.database.ids', [ 1 ] );

        expect( $this->channel->supports( NotificationType::Confirmation, $this->booking ) )->toBeFalse();
    } );

    it( 'still lets the customer be emailed', function (): void {
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail', 'database' ] );
        Notifier::fake();

        $sent = ( new NotificationService( [ new MailChannel(), $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toHaveCount( 1 )
            ->and( $sent[ 0 ]->channel )->toBe( 'mail' );
    } );
} );

describe( 'when admins are configured', function (): void {
    beforeEach( function (): void {
        $this->admin = NotifiableAdmin::query()->create( [ 'email' => 'admin@example.com' ] );

        config()->set( 'artisanpack.bookings.notifications.database.ids', [ $this->admin->getKey() ] );
    } );

    it( 'reports itself supported', function (): void {
        expect( $this->channel->supports( NotificationType::Confirmation, $this->booking ) )->toBeTrue();
    } );

    it( 'records an internal reference rather than a staff address', function (): void {
        // The erasure sweep redacts `recipient` for every row of an erased
        // booking. Staff are not the subject of that erasure, and their address
        // has no business in a column documented as customer contact details.
        $recipient = $this->channel->recipient( NotificationType::Confirmation, $this->booking );

        expect( $recipient )->toBe( NotifiableAdmin::class . ':' . $this->admin->getKey() )
            ->and( $recipient )->not->toContain( 'admin@example.com' );
    } );

    it( 'writes a database notification for the admin', function (): void {
        ( new NotificationService( [ $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        $stored = DB::table( 'notifications' )->get();

        expect( $stored )->toHaveCount( 1 );

        $payload = json_decode( (string) $stored[ 0 ]->data, true );

        expect( $stored[ 0 ]->type )->toBe( BookingConfirmation::class )
            ->and( $payload['booking_id'] )->toBe( $this->booking->id )
            ->and( $payload['type'] )->toBe( 'confirmation' );
    } );

    it( 'does not email the admin the customer\'s appointment', function (): void {
        // The notifications declare `mail` in via(); routing the admin copy by
        // that list would send every administrator the customer's message. The
        // channel forces `database`, and that forcing is what this asserts.
        Notifier::fake();

        ( new NotificationService( [ $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        Notifier::assertSentTo(
            $this->admin,
            BookingConfirmation::class,
            static fn ( object $notification, array $channels ): bool => [ 'database' ] === $channels,
        );
    } );

    it( 'summarises a recipient list too long for the column', function (): void {
        // A truncated reference would be worse than a summary: the column would
        // cut it mid-key, or a strict-mode connection would reject the insert
        // and turn a wide notification into a failed send.
        $ids = [];

        foreach ( range( 1, 200 ) as $index ) {
            $ids[] = NotifiableAdmin::query()->create( [ 'email' => "admin{$index}@example.com" ] )->getKey();
        }

        config()->set( 'artisanpack.bookings.notifications.database.ids', $ids );

        $recipient = $this->channel->recipient( NotificationType::Confirmation, $this->booking );

        expect( mb_strlen( $recipient ) )->toBeLessThanOrEqual( 255 )
            ->and( $recipient )->toContain( 'recipients' )
            ->and( $recipient )->toStartWith( NotifiableAdmin::class );
    } );

    it( 'claims alongside mail rather than against it', function (): void {
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail', 'database' ] );
        Notifier::fake();

        $sent = ( new NotificationService( [ new MailChannel(), $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toHaveCount( 2 )
            ->and( NotificationLog::query()->pluck( 'channel' )->sort()->values()->all() )
            ->toBe( [ 'database', 'mail' ] );
    } );
} );
