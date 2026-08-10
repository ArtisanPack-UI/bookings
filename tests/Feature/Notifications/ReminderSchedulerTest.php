<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Notifications\BookingReminder;
use ArtisanPackUI\Bookings\Notifications\Channels\MailChannel;
use ArtisanPackUI\Bookings\Services\NotificationService;
use ArtisanPackUI\Bookings\Services\ReminderScheduler;
use ArtisanPackUI\Core\Contracts\SiteResolver;
use ArtisanPackUI\Core\MultiTenancy\SiteContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as Notifier;
use Tests\Concerns\TestsWithSqlite;
use Tests\Fixtures\FixedSiteResolver;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail' ] );
    config()->set( 'artisanpack.bookings.notifications.reminder.hours_before', [ 24 ] );
    config()->set( 'artisanpack.bookings.notifications.reminder.max_lookahead_hours', 0 );

    $this->now = CarbonImmutable::parse( '2026-06-01 12:00:00', 'UTC' );

    $this->scheduler = new ReminderScheduler(
        new NotificationService( [ new MailChannel() ] ),
    );
} );

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.reminderScheduling' );
} );

/**
 * Creates a confirmed booking starting the given number of hours from now.
 */
function reminderBooking( CarbonImmutable $now, float $hoursAway ): Booking
{
    return Booking::factory()->confirmed()->create( [
        'customer_email' => 'customer@example.com',
        'start_time'     => $now->addMinutes( (int) round( $hoursAway * 60 ) ),
        'end_time'       => $now->addMinutes( (int) round( $hoursAway * 60 ) + 30 ),
    ] );
}

describe( 'sending what is due', function (): void {
    it( 'sends a reminder once its moment has passed', function (): void {
        Notifier::fake();
        reminderBooking( $this->now, 23 );

        expect( $this->scheduler->sendDue( $this->now ) )->toBe( 1 );

        Notifier::assertSentOnDemandTimes( BookingReminder::class, 1 );
    } );

    it( 'leaves a booking alone until its moment arrives', function (): void {
        Notifier::fake();
        reminderBooking( $this->now, 30 );

        expect( $this->scheduler->sendDue( $this->now ) )->toBe( 0 )
            ->and( NotificationLog::query()->count() )->toBe( 0 );
    } );

    it( 'sends exactly once when the cron runs twice', function (): void {
        // Use case 4 from the issue, and the whole reason the claim exists.
        Notifier::fake();
        reminderBooking( $this->now, 23 );

        $first  = $this->scheduler->sendDue( $this->now );
        $second = $this->scheduler->sendDue( $this->now->addMinutes( 15 ) );

        expect( $first )->toBe( 1 )
            ->and( $second )->toBe( 0 )
            ->and( NotificationLog::query()->count() )->toBe( 1 );

        Notifier::assertSentOnDemandTimes( BookingReminder::class, 1 );
    } );

    it( 'catches up on a reminder whose moment passed while the cron was down', function (): void {
        Notifier::fake();
        reminderBooking( $this->now, 20 );

        expect( $this->scheduler->sendDue( $this->now ) )->toBe( 1 );
    } );

    it( 'does not remind anybody about an appointment already under way', function (): void {
        Notifier::fake();
        reminderBooking( $this->now, -1 );

        expect( $this->scheduler->sendDue( $this->now ) )->toBe( 0 );
    } );

    it( 'ignores a cancelled booking', function (): void {
        Notifier::fake();

        Booking::factory()->cancelled()->create( [
            'start_time' => $this->now->addHours( 23 ),
            'end_time'   => $this->now->addHours( 23 )->addMinutes( 30 ),
        ] );

        expect( $this->scheduler->sendDue( $this->now ) )->toBe( 0 );
    } );

    it( 'sends each configured window separately', function (): void {
        config()->set( 'artisanpack.bookings.notifications.reminder.hours_before', [ 48, 24 ] );
        Notifier::fake();

        reminderBooking( $this->now, 23 );

        expect( $this->scheduler->sendDue( $this->now ) )->toBe( 2 )
            ->and( NotificationLog::query()->count() )->toBe( 2 );
    } );
} );

describe( 'ap.bookings.reminderScheduling', function (): void {
    it( 'honours a subscriber that adds a window', function (): void {
        config()->set( 'artisanpack.bookings.notifications.reminder.hours_before', [ 24 ] );
        config()->set( 'artisanpack.bookings.notifications.reminder.max_lookahead_hours', 72 );
        Notifier::fake();

        addFilter( 'ap.bookings.reminderScheduling', function ( array $hours ): array {
            $hours[] = 48;

            return $hours;
        } );

        reminderBooking( $this->now, 47 );

        expect( $this->scheduler->sendDue( $this->now ) )->toBe( 1 );
    } );

    it( 'honours a subscriber that removes every window', function (): void {
        Notifier::fake();
        addFilter( 'ap.bookings.reminderScheduling', static fn (): array => [] );

        reminderBooking( $this->now, 23 );

        expect( $this->scheduler->sendDue( $this->now ) )->toBe( 0 );
    } );

    it( 'cannot produce a duplicate send by adding a window config already has', function (): void {
        // Called out explicitly in the issue. Two guards stand behind it: the
        // cadence is deduplicated before it is used, and the unique index would
        // refuse the second claim even if it were not.
        Notifier::fake();

        addFilter( 'ap.bookings.reminderScheduling', function ( array $hours ): array {
            $hours[] = 24;
            $hours[] = 24;

            return $hours;
        } );

        $booking = reminderBooking( $this->now, 23 );

        expect( $this->scheduler->momentsFor( $booking ) )->toHaveCount( 1 )
            ->and( $this->scheduler->sendDue( $this->now ) )->toBe( 1 )
            ->and( NotificationLog::query()->count() )->toBe( 1 );

        Notifier::assertSentOnDemandTimes( BookingReminder::class, 1 );
    } );

    it( 'discards a window that is not a positive number', function (): void {
        addFilter( 'ap.bookings.reminderScheduling', static fn (): array => [ 24, 0, -5, 'soon', null ] );

        expect( $this->scheduler->momentsFor( reminderBooking( $this->now, 23 ) ) )->toHaveCount( 1 );
    } );

    it( 'refuses a subscriber that returns something other than an array', function (): void {
        addFilter( 'ap.bookings.reminderScheduling', static fn (): int => 24 );

        $this->scheduler->momentsFor( reminderBooking( $this->now, 23 ) );
    } )->throws( UnexpectedValueException::class );

    it( 'orders the moments it derives', function (): void {
        addFilter( 'ap.bookings.reminderScheduling', static fn (): array => [ 2, 48, 24 ] );

        $moments = $this->scheduler->momentsFor( reminderBooking( $this->now, 60 ) );

        expect( $moments[ 0 ]->lessThan( $moments[ 1 ] ) )->toBeTrue()
            ->and( $moments[ 1 ]->lessThan( $moments[ 2 ] ) )->toBeTrue();
    } );
} );

describe( 'the reminder itself', function (): void {
    it( 'is logged against the moment it was scheduled for', function (): void {
        Notifier::fake();
        $booking = reminderBooking( $this->now, 23 );

        $this->scheduler->sendDue( $this->now );

        $log = NotificationLog::query()->firstOrFail();

        expect( $log->type )->toBe( NotificationType::Reminder )
            ->and( $log->scheduled_for )->not->toBeNull()
            ->and( $log->scheduled_for->equalTo( $booking->start_time->copy()->subHours( 24 ) ) )->toBeTrue();
    } );
} );

describe( 'across sites', function (): void {
    it( 'reminds every tenant, not only the one in context', function (): void {
        // The sweep runs from cron, where nothing has resolved a site. An
        // application whose resolver answers in console too would otherwise have
        // this quietly sweep one tenant and leave every other customer without a
        // reminder — with nothing anywhere to say so.
        Notifier::fake();

        $booking = reminderBooking( $this->now, 23 );
        $booking->forceFill( [ 'site_id' => 2 ] )->save();

        config()->set( 'artisanpack.core.multi_tenant.enabled', true );
        app()->instance( SiteResolver::class, new FixedSiteResolver( 1 ) );
        app()->instance( SiteContext::class, new SiteContext( app( SiteResolver::class ), app( 'config' ) ) );

        // Site 1 is in context; the booking belongs to site 2 and is invisible
        // to an ordinary scoped read.
        expect( Booking::query()->count() )->toBe( 0 )
            ->and( $this->scheduler->sendDue( $this->now ) )->toBe( 1 );

        Notifier::assertSentOnDemandTimes( BookingReminder::class, 1 );
    } );
} );
