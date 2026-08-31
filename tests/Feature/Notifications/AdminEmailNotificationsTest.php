<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\NotificationAudience;
use ArtisanPackUI\Bookings\Enums\NotificationStatus;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Notifications\BookingConfirmation;
use ArtisanPackUI\Bookings\Notifications\BookingNotification;
use ArtisanPackUI\Bookings\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifier;
use Tests\Concerns\TestsWithSqlite;
use Tests\Fixtures\NotifiableAdmin;
use Tests\Fixtures\RoledAdmin;
use Tests\Fixtures\RoleFixture;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    NotifiableAdmin::createTable();

    config()->set( 'artisanpack.bookings.notifications.admin.email.enabled', true );
    config()->set( 'artisanpack.bookings.notifications.database.role', null );
    config()->set( 'artisanpack.bookings.notifications.database.notifiable', null );
    config()->set( 'artisanpack.bookings.notifications.database.ids', [] );

    $this->booking = Booking::factory()->confirmed()->create( [
        'customer_name'  => 'Sam Rivera',
        'customer_email' => 'customer@example.com',
        'customer_phone' => '+15555550123',
    ] );
} );

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.notification.sending' );
    removeAllFilters( 'ap.bookings.notification.subject' );
} );

/**
 * Names one notifiable admin and returns it.
 *
 * @return NotifiableAdmin The admin.
 */
function namedAdmin(): NotifiableAdmin
{
    $admin = NotifiableAdmin::query()->create( [ 'email' => 'admin@example.com' ] );

    config()->set( 'artisanpack.bookings.notifications.database.notifiable', NotifiableAdmin::class );
    config()->set( 'artisanpack.bookings.notifications.database.ids', [ $admin->getKey() ] );

    return $admin;
}

describe( 'the opt-in gate', function (): void {
    it( 'sends nothing when the gate is off, even with staff named', function (): void {
        config()->set( 'artisanpack.bookings.notifications.admin.email.enabled', false );
        namedAdmin();
        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins( NotificationType::Confirmation, $this->booking );

        expect( $log )->toBeNull()
            ->and( NotificationLog::query()->count() )->toBe( 0 );

        Notifier::assertNothingSent();
    } );

    it( 'sends when the gate is on and staff are named', function (): void {
        $admin = namedAdmin();
        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins( NotificationType::Confirmation, $this->booking );

        expect( $log )->not->toBeNull()
            ->and( $log->channel )->toBe( 'admin_mail' )
            ->and( $log->status )->toBe( NotificationStatus::Sent );

        Notifier::assertSentTo(
            $admin,
            BookingConfirmation::class,
            static fn ( BookingNotification $notification, array $channels ): bool =>
                [ 'mail' ] === $channels && NotificationAudience::Admin === $notification->audience(),
        );
    } );

    it( 'writes no row when the gate is on but nobody is named', function (): void {
        // The right default for an installation that turned the feature on and
        // has not yet named its staff: silence, and no claim left behind to block
        // a later send once it has.
        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins( NotificationType::Confirmation, $this->booking );

        expect( $log )->toBeNull()
            ->and( NotificationLog::query()->count() )->toBe( 0 );

        Notifier::assertNothingSent();
    } );
} );

describe( 'resolving recipients the way the database channel does', function (): void {
    it( 'emails the notifiable staff and logs an internal reference, not an address', function (): void {
        $admin = namedAdmin();
        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins( NotificationType::Confirmation, $this->booking );

        expect( $log->recipient )->toBe( NotifiableAdmin::class . ':' . $admin->getKey() )
            ->and( $log->recipient )->not->toContain( 'admin@example.com' );

        Notifier::assertSentTo( $admin, BookingConfirmation::class );
    } );

    it( 'resolves staff by the cms-framework role when the CMS centre is the bound channel', function (): void {
        // The role is consulted only when the database channel this install binds
        // is the cms-framework one — forced here with driver "cms", the way the
        // CmsFrameworkChannel tests force it, since cms-framework is a suggest and
        // absent from this suite.
        RoledAdmin::createTables();

        $role  = RoleFixture::query()->create( [ 'name' => 'administrator' ] );
        $admin = RoledAdmin::query()->create( [ 'email' => 'roled@example.com' ] );
        $admin->roles()->attach( $role->getKey() );

        config()->set( 'auth.providers.users.model', RoledAdmin::class );
        config()->set( 'artisanpack.bookings.notifications.database.driver', 'cms' );
        config()->set( 'artisanpack.bookings.notifications.database.role', 'administrator' );

        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins( NotificationType::Confirmation, $this->booking );

        expect( $log->recipient )->toBe( 'role:administrator' )
            ->and( $log->recipient )->not->toContain( '@' );

        Notifier::assertSentTo( $admin, BookingConfirmation::class );
    } );

    it( 'prefers the role over the notifiable id list under the CMS centre', function (): void {
        // A role stays right as staff join and leave; a list of ids does not.
        RoledAdmin::createTables();

        $role  = RoleFixture::query()->create( [ 'name' => 'administrator' ] );
        $roled = RoledAdmin::query()->create( [ 'email' => 'roled@example.com' ] );
        $roled->roles()->attach( $role->getKey() );

        namedAdmin();
        config()->set( 'auth.providers.users.model', RoledAdmin::class );
        config()->set( 'artisanpack.bookings.notifications.database.driver', 'cms' );
        config()->set( 'artisanpack.bookings.notifications.database.role', 'administrator' );

        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins( NotificationType::Confirmation, $this->booking );

        expect( $log->recipient )->toBe( 'role:administrator' );

        Notifier::assertSentTo( $roled, BookingConfirmation::class );
    } );

    it( 'ignores the role on a Laravel-native install, even with a roles relationship', function (): void {
        // The regression guard: a standalone app whose user model happens to carry
        // a `roles` relationship must not be emailed by role while its database
        // notice goes to the id list. The database channel there is Laravel's own,
        // which ignores the role, so the admin email does too — and falls to the
        // notifiable list the way that channel resolves its own audience.
        RoledAdmin::createTables();

        $role  = RoleFixture::query()->create( [ 'name' => 'administrator' ] );
        $roled = RoledAdmin::query()->create( [ 'email' => 'roled@example.com' ] );
        $roled->roles()->attach( $role->getKey() );

        $admin = namedAdmin();
        config()->set( 'auth.providers.users.model', RoledAdmin::class );
        config()->set( 'artisanpack.bookings.notifications.database.driver', 'laravel' );
        config()->set( 'artisanpack.bookings.notifications.database.role', 'administrator' );

        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins( NotificationType::Confirmation, $this->booking );

        expect( $log->recipient )->toBe( NotifiableAdmin::class . ':' . $admin->getKey() );

        Notifier::assertSentTo( $admin, BookingConfirmation::class );
        Notifier::assertNotSentTo( $roled, BookingConfirmation::class );
    } );

    it( 'falls back to the id list when the CMS user model cannot answer the role', function (): void {
        // Under the CMS centre but with a host user model that has no `roles`
        // relationship to filter on, the role resolves nobody — so rather than
        // emailing no one, the id list stands in.
        $admin = namedAdmin();
        config()->set( 'artisanpack.bookings.notifications.database.driver', 'cms' );
        config()->set( 'artisanpack.bookings.notifications.database.role', 'administrator' );

        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins( NotificationType::Confirmation, $this->booking );

        expect( $log->recipient )->toBe( NotifiableAdmin::class . ':' . $admin->getKey() );

        Notifier::assertSentTo( $admin, BookingConfirmation::class );
    } );
} );

describe( 'the same safety rails the customer path has', function (): void {
    it( 'honours a disabled message type', function (): void {
        namedAdmin();
        config()->set( 'artisanpack.bookings.notifications.confirmation.enabled', false );
        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins( NotificationType::Confirmation, $this->booking );

        expect( $log )->toBeNull()
            ->and( NotificationLog::query()->count() )->toBe( 0 );

        Notifier::assertNothingSent();
    } );

    it( 'does not email a lifecycle message it has no admin template for', function (): void {
        // The reminder is deliberately left out: a staff mailbox does not need a
        // nudge about a customer's appointment, and there is no admin reminder
        // view to render if it tried.
        namedAdmin();
        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins(
            NotificationType::Reminder,
            $this->booking,
        );

        expect( $log )->toBeNull()
            ->and( NotificationLog::query()->count() )->toBe( 0 );

        Notifier::assertNothingSent();
    } );

    it( 'refuses a booking whose personal data has been erased', function (): void {
        // The staff copy carries the customer's contact details, so it must not
        // go out once those details have been erased.
        namedAdmin();
        $erased = Booking::factory()->erased()->create();
        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins( NotificationType::Confirmation, $erased );

        expect( $log )->toBeNull()
            ->and( NotificationLog::query()->count() )->toBe( 0 );

        Notifier::assertNothingSent();
    } );

    it( 'lets a sending subscriber suppress the copy, leaving no row behind', function (): void {
        namedAdmin();
        addFilter( 'ap.bookings.notification.sending', static fn (): ?Notification => null );
        Notifier::fake();

        $log = app( NotificationService::class )->sendToAdmins( NotificationType::Confirmation, $this->booking );

        expect( $log )->toBeNull()
            ->and( NotificationLog::query()->count() )->toBe( 0 );

        Notifier::assertNothingSent();
    } );
} );

describe( 'claiming a channel of its own', function (): void {
    it( 'claims admin_mail alongside the customer mail rather than against it', function (): void {
        // The whole reason the admin copy logs a distinct channel: keyed on the
        // customer's `mail` channel it would race the customer's own send for the
        // same row, and one of the two would silently not go out.
        namedAdmin();
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail' ] );
        Notifier::fake();

        $service = app( NotificationService::class );
        $service->send( NotificationType::Confirmation, $this->booking );
        $log = $service->sendToAdmins( NotificationType::Confirmation, $this->booking );

        expect( $log )->not->toBeNull()
            ->and( NotificationLog::query()
                ->where( 'booking_id', $this->booking->getKey() )
                ->where( 'type', NotificationType::Confirmation->value )
                ->pluck( 'channel' )
                ->sort()
                ->values()
                ->all() )
            ->toBe( [ 'admin_mail', 'mail' ] );
    } );
} );

describe( 'through the lifecycle listener', function (): void {
    it( 'emails staff a copy when a booking is confirmed', function (): void {
        namedAdmin();
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail' ] );
        Notifier::fake();

        $booking = Booking::factory()->requested()->create( [ 'customer_email' => 'customer@example.com' ] );

        app( ArtisanPackUI\Bookings\Services\BookingService::class )->confirm( $booking );

        expect( NotificationLog::query()
            ->where( 'booking_id', $booking->getKey() )
            ->where( 'channel', 'admin_mail' )
            ->where( 'type', NotificationType::Confirmation->value )
            ->count() )->toBe( 1 );
    } );

    it( 'sends no staff copy while the gate is off', function (): void {
        config()->set( 'artisanpack.bookings.notifications.admin.email.enabled', false );
        namedAdmin();
        config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail' ] );
        Notifier::fake();

        $booking = Booking::factory()->requested()->create( [ 'customer_email' => 'customer@example.com' ] );

        app( ArtisanPackUI\Bookings\Services\BookingService::class )->confirm( $booking );

        expect( NotificationLog::query()->where( 'channel', 'admin_mail' )->count() )->toBe( 0 );
    } );
} );
