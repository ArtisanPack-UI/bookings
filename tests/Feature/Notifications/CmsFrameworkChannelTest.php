<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Contracts\NotificationChannel;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Notifications\Channels\CmsFrameworkChannel;
use ArtisanPackUI\Bookings\Notifications\Channels\DatabaseChannel;
use ArtisanPackUI\Bookings\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // The channel reaches cms-framework through its global helpers, which is how
    // that package publishes its API. Absent here, so the test defines them once
    // and records what it was handed — the same shape a real install provides.
    if ( ! function_exists( 'apSendNotification' ) ) {
        require __DIR__ . '/../../Fixtures/cms_notification_helpers.php';
    }

    cmsNotificationSpy()->reset();

    config()->set( 'artisanpack.bookings.notifications.channels', [ 'database' ] );
    config()->set( 'artisanpack.bookings.notifications.database.role', null );
    config()->set( 'artisanpack.bookings.notifications.database.ids', [] );

    $this->booking = Booking::factory()->create( [
        'customer_name'     => 'Sam Rivera',
        'customer_email'    => 'sam@example.test',
        'customer_timezone' => 'America/Chicago',
    ] );

    $this->channel = new CmsFrameworkChannel();
} );

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.notification.subject' );
} );

describe( 'the channel it answers to', function (): void {
    it( 'answers to the same key as the standalone channel', function (): void {
        // Configuration naming `database` should not have to know which
        // implementation the installation got.
        expect( $this->channel->key() )->toBe( 'database' )
            ->and( ( new DatabaseChannel() )->key() )->toBe( 'database' );
    } );
} );

describe( 'when nobody is configured', function (): void {
    it( 'reports itself unsupported rather than guessing', function (): void {
        expect( $this->channel->supports( NotificationType::Confirmation, $this->booking ) )->toBeFalse();
    } );
} );

describe( 'sending by role', function (): void {
    beforeEach( function (): void {
        config()->set( 'artisanpack.bookings.notifications.database.role', 'administrator' );
    } );

    it( 'reports itself supported', function (): void {
        expect( $this->channel->supports( NotificationType::Confirmation, $this->booking ) )->toBeTrue();
    } );

    it( 'hands the notice to the CMS notification centre, keyed by role', function (): void {
        ( new NotificationService( [ $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        $sent = cmsNotificationSpy()->byRole;

        expect( $sent )->toHaveCount( 1 )
            ->and( $sent[ 0 ]['key'] )->toBe( 'bookings.confirmation' )
            ->and( $sent[ 0 ]['role'] )->toBe( 'administrator' );
    } );

    it( 'prefers the role over an explicit id list', function (): void {
        // A role stays right as staff join and leave; a list of ids does not,
        // and nothing notices when it stops being right.
        config()->set( 'artisanpack.bookings.notifications.database.ids', [ 7 ] );

        ( new NotificationService( [ $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        expect( cmsNotificationSpy()->byRole )->toHaveCount( 1 )
            ->and( cmsNotificationSpy()->byIds )->toBe( [] );
    } );

    it( 'logs the audience rather than a staff address', function (): void {
        ( new NotificationService( [ $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        $log = NotificationLog::query()->firstOrFail();

        expect( $log->channel )->toBe( 'database' )
            ->and( $log->recipient )->toBe( 'role:administrator' )
            ->and( $log->recipient )->not->toContain( '@' );
    } );

    it( 'carries the booking identifiers in metadata, not only in prose', function (): void {
        ( new NotificationService( [ $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        $overrides = cmsNotificationSpy()->byRole[ 0 ]['overrides'];

        expect( $overrides['metadata'] )->toMatchArray( [
            'booking_id' => $this->booking->id,
            'type'       => 'confirmation',
        ] )->and( $overrides['content'] )->toContain( 'Sam Rivera' );
    } );

    it( 'reuses the filtered subject as the title', function (): void {
        // An application that rewrote the subject gets the rewrite in the
        // notification centre too, not only in the customer's inbox.
        addFilter( 'ap.bookings.notification.subject', static fn (): string => 'Rewritten subject' );

        ( new NotificationService( [ $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        expect( cmsNotificationSpy()->byRole[ 0 ]['overrides']['title'] )->toBe( 'Rewritten subject' );
    } );

    it( 'treats a preference-suppressed delivery as sent rather than failed', function (): void {
        // cms-framework returns null when every candidate has switched the
        // notification off. That is a delivery that correctly did not happen —
        // marking it failed would have the reminder cron retry it forever for
        // staff who have said they do not want it.
        cmsNotificationSpy()->returnNull = true;

        $sent = ( new NotificationService( [ $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        expect( $sent )->toHaveCount( 1 )
            ->and( NotificationLog::query()->firstOrFail()->status->value )->toBe( 'sent' );
    } );
} );

describe( 'sending by explicit ids', function (): void {
    it( 'falls back to the id list when no role is set', function (): void {
        config()->set( 'artisanpack.bookings.notifications.database.ids', [ 7, 12 ] );

        ( new NotificationService( [ $this->channel ] ) )
            ->send( NotificationType::Reminder, $this->booking, $this->booking->start_time->copy()->subDay() );

        $sent = cmsNotificationSpy()->byIds;

        expect( $sent )->toHaveCount( 1 )
            ->and( $sent[ 0 ]['key'] )->toBe( 'bookings.reminder' )
            ->and( $sent[ 0 ]['ids'] )->toBe( [ 7, 12 ] )
            ->and( NotificationLog::query()->firstOrFail()->recipient )->toBe( 'users:7,12' );
    } );

    it( 'summarises an id list too long for the log column', function (): void {
        // `recipient` is a varchar(255). Overrunning it truncates the reference
        // mid-id on a lenient connection and rejects the insert on a strict one
        // — a wide audience turning into a failed send, invisible until the
        // audience grows.
        config()->set( 'artisanpack.bookings.notifications.database.ids', range( 1, 200 ) );

        $recipient = $this->channel->recipient( NotificationType::Confirmation, $this->booking );

        expect( mb_strlen( $recipient ) )->toBeLessThanOrEqual( 255 )
            ->and( $recipient )->toContain( 'recipients' );
    } );

    it( 'discards an id that is not a positive number', function (): void {
        config()->set( 'artisanpack.bookings.notifications.database.ids', [ 7, 0, -2, 'nobody' ] );

        ( new NotificationService( [ $this->channel ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        expect( cmsNotificationSpy()->byIds[ 0 ]['ids'] )->toBe( [ 7 ] );
    } );
} );

describe( 'which implementation an installation gets', function (): void {
    it( 'uses Laravel notifications when cms-framework is absent', function (): void {
        // Which is the case in this suite: cms-framework is a `suggest`, so the
        // gate finds nothing and `auto` falls to the standalone channel.
        config()->set( 'artisanpack.bookings.notifications.database.driver', 'auto' );

        expect( app( NotificationChannel::class ) )->toBeInstanceOf( DatabaseChannel::class );
    } );

    it( 'can be forced to the CMS centre', function (): void {
        // The branch detection would take on an install that has cms-framework.
        // Driven by config rather than by aliasing a class onto the probe name,
        // because `class_alias` is process-wide and irreversible — one test doing
        // that would make every later test in the run believe cms-framework is
        // installed, and the failures would land nowhere near the cause.
        config()->set( 'artisanpack.bookings.notifications.database.driver', 'cms' );

        expect( app( NotificationChannel::class ) )->toBeInstanceOf( CmsFrameworkChannel::class );
    } );

    it( 'can be forced back to Laravel notifications', function (): void {
        // For an installation running the CMS admin shell that still wants
        // booking notices in Laravel's own table.
        config()->set( 'artisanpack.bookings.notifications.database.driver', 'laravel' );

        expect( app( NotificationChannel::class ) )->toBeInstanceOf( DatabaseChannel::class );
    } );

    it( 'sends through whichever it resolved', function (): void {
        config()->set( 'artisanpack.bookings.notifications.database.driver', 'cms' );
        config()->set( 'artisanpack.bookings.notifications.database.role', 'administrator' );

        ( new NotificationService( [ app( NotificationChannel::class ) ] ) )
            ->send( NotificationType::Confirmation, $this->booking );

        expect( cmsNotificationSpy()->byRole )->toHaveCount( 1 );
    } );
} );
