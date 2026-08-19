<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Console\Commands\PruneNotificationLogCommand;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Enums\WebhookDeliveryStatus;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarEvent;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    Carbon::setTestNow( '2026-06-01 12:00:00' );
} );

afterEach( function (): void {
    Carbon::setTestNow();

    // Pest runs every file in one process, so a zone left set by the timezone
    // case would follow every later test in the suite.
    date_default_timezone_set( 'UTC' );
} );

describe( 'bookings:prune-notification-log', function (): void {
    it( 'removes rows past the retention window and keeps the rest', function (): void {
        config()->set( 'artisanpack.bookings.retention.notification_log_days', 90 );

        $booking = Booking::factory()->create();

        $stale = NotificationLog::factory()->for( $booking )->create( [ 'type' => NotificationType::Reminder ] );
        $kept  = NotificationLog::factory()->for( $booking )->create( [ 'type' => NotificationType::Confirmation ] );

        bookingRowAgedTo( $stale, '-100 days' );
        bookingRowAgedTo( $kept, '-10 days' );

        $this->artisan( 'bookings:prune-notification-log' )
            ->expectsOutputToContain( '1 notification log row(s) removed.' )
            ->assertSuccessful();

        expect( NotificationLog::query()->pluck( 'id' )->all() )->toBe( [ $kept->getKey() ] );
    } );

    it( 'reports without deleting on a dry run', function (): void {
        config()->set( 'artisanpack.bookings.retention.notification_log_days', 90 );

        $stale = NotificationLog::factory()->for( Booking::factory() )->create();
        bookingRowAgedTo( $stale, '-100 days' );

        $this->artisan( 'bookings:prune-notification-log', [ '--dry-run' => true ] )
            ->expectsOutputToContain( '1 notification log row(s) would be removed.' )
            ->assertSuccessful();

        expect( NotificationLog::query()->count() )->toBe( 1 );
    } );

    it( 'prunes nothing when the window is zero or missing', function ( mixed $window ): void {
        // Zero is far likelier to be an empty environment variable than somebody's
        // retention policy, and reading it as "keep nothing" would delete the log
        // that stops a reminder being sent twice.
        config()->set( 'artisanpack.bookings.retention.notification_log_days', $window );

        $stale = NotificationLog::factory()->for( Booking::factory() )->create();
        bookingRowAgedTo( $stale, '-1000 days' );

        $this->artisan( 'bookings:prune-notification-log' )
            ->expectsOutputToContain( 'No notification log retention window is configured' )
            ->assertSuccessful();

        expect( NotificationLog::query()->count() )->toBe( 1 );
    } )->with( [ 'zero' => 0, 'negative' => -1, 'null' => null, 'nonsense' => 'soon' ] );
} );

describe( 'bookings:prune', function (): void {
    it( 'soft-deletes bookings past the window and keeps the rest, personal data intact', function (): void {
        config()->set( 'artisanpack.bookings.retention.prune_after_days', 365 );

        $stale = Booking::factory()->create( [
            'customer_name' => 'Ada Lovelace',
            'start_time'    => Carbon::parse( '-400 days' ),
            'end_time'      => Carbon::parse( '-400 days' )->addHour(),
        ] );
        $kept = Booking::factory()->create( [
            'start_time' => Carbon::parse( '-10 days' ),
            'end_time'   => Carbon::parse( '-10 days' )->addHour(),
        ] );

        $this->artisan( 'bookings:prune' )
            ->expectsOutputToContain( '1 booking(s) soft-deleted.' )
            ->assertSuccessful();

        expect( Booking::query()->pluck( 'id' )->all() )->toBe( [ $kept->getKey() ] );

        // The row stays, PII intact, for the legal record — a soft delete is not
        // an erasure, and pruning must not touch either column.
        $trashed = Booking::withTrashed()->find( $stale->getKey() );

        expect( $trashed->trashed() )->toBeTrue()
            ->and( $trashed->isPiiErased() )->toBeFalse()
            ->and( $trashed->customer_name )->toBe( 'Ada Lovelace' );
    } );

    it( 'keeps a future booking however old its row is', function (): void {
        // The window is measured from the booking's end time, not the row's own
        // age: a booking taken well ahead of time is not old the day it is made,
        // and pruning by `created_at` would soft-delete a live future booking.
        config()->set( 'artisanpack.bookings.retention.prune_after_days', 365 );

        $future = Booking::factory()->create( [
            'start_time' => Carbon::parse( '+30 days' ),
            'end_time'   => Carbon::parse( '+30 days' )->addHour(),
        ] );

        bookingRowAgedTo( $future, '-1000 days' );

        $this->artisan( 'bookings:prune' )
            ->expectsOutputToContain( '0 booking(s) soft-deleted.' )
            ->assertSuccessful();

        expect( Booking::query()->count() )->toBe( 1 );
    } );

    it( 'keeps a booking just inside the window under a non-UTC app timezone', function (): void {
        // The cutoff comes back in the application's zone, but this prune compares
        // against `end_time`, which the package writes as UTC. Without the
        // `->utc()` at the call site, Asia/Tokyo prunes nine hours' worth early.
        config()->set( 'artisanpack.bookings.retention.prune_after_days', 30 );

        date_default_timezone_set( 'Asia/Tokyo' );
        Carbon::setTestNow( Carbon::parse( '2026-06-01 12:00:00', 'UTC' )->setTimezone( 'Asia/Tokyo' ) );

        // Ended 30 days ago minus four hours — inside the window by four hours,
        // and outside it by five if the cutoff is read as Tokyo wall clock.
        Booking::factory()->create( [
            'start_time' => Carbon::parse( '2026-06-01 12:00:00', 'UTC' )->subDays( 30 )->addHours( 3 ),
            'end_time'   => Carbon::parse( '2026-06-01 12:00:00', 'UTC' )->subDays( 30 )->addHours( 4 ),
        ] );

        $this->artisan( 'bookings:prune' )
            ->expectsOutputToContain( '0 booking(s) soft-deleted.' )
            ->assertSuccessful();

        expect( Booking::query()->count() )->toBe( 1 );
    } );

    it( 'reports without deleting on a dry run', function (): void {
        config()->set( 'artisanpack.bookings.retention.prune_after_days', 365 );

        Booking::factory()->create( [
            'start_time' => Carbon::parse( '-400 days' ),
            'end_time'   => Carbon::parse( '-400 days' )->addHour(),
        ] );

        $this->artisan( 'bookings:prune', [ '--dry-run' => true ] )
            ->expectsOutputToContain( '1 booking(s) would be soft-deleted.' )
            ->assertSuccessful();

        expect( Booking::query()->count() )->toBe( 1 );
    } );

    it( 'prunes nothing when the window is zero or missing', function ( mixed $window ): void {
        config()->set( 'artisanpack.bookings.retention.prune_after_days', $window );

        Booking::factory()->create( [
            'start_time' => Carbon::parse( '-1000 days' ),
            'end_time'   => Carbon::parse( '-1000 days' )->addHour(),
        ] );

        $this->artisan( 'bookings:prune' )
            ->expectsOutputToContain( 'No booking retention window is configured' )
            ->assertSuccessful();

        expect( Booking::query()->count() )->toBe( 1 );
    } )->with( [ 'zero' => 0, 'negative' => -1, 'null' => null, 'nonsense' => 'soon' ] );
} );

describe( 'bookings:prune-webhook-deliveries', function (): void {
    it( 'removes settled attempts past the retention window', function (): void {
        config()->set( 'artisanpack.bookings.retention.webhook_delivery_days', null );
        config()->set( 'artisanpack.bookings.webhooks.delivery_retention_days', 30 );

        $stale = WebhookDelivery::factory()->create( [ 'status' => WebhookDeliveryStatus::Success ] );
        $kept  = WebhookDelivery::factory()->create( [ 'status' => WebhookDeliveryStatus::Success ] );

        bookingRowAgedTo( $stale, '-40 days' );
        bookingRowAgedTo( $kept, '-10 days' );

        $this->artisan( 'bookings:prune-webhook-deliveries' )
            ->expectsOutputToContain( '1 webhook delivery attempt(s) removed.' )
            ->assertSuccessful();

        expect( WebhookDelivery::query()->pluck( 'id' )->all() )->toBe( [ $kept->getKey() ] );
    } );

    it( 'never removes a delivery that is still pending, but says it kept it', function (): void {
        // Deleting it makes the delivery stop existing rather than fail, and the
        // endpoint that should have received the event never hears about it. But
        // a payload holds the customer's name, email, and appointment, so a row
        // exempted from retention silently is the window quietly not applying —
        // and the whole reason for keeping it is that somebody should look.
        config()->set( 'artisanpack.bookings.retention.webhook_delivery_days', 30 );

        $pending = WebhookDelivery::factory()->create( [ 'status' => WebhookDeliveryStatus::Pending ] );
        bookingRowAgedTo( $pending, '-400 days' );

        $this->artisan( 'bookings:prune-webhook-deliveries' )
            ->expectsOutputToContain( '1 pending delivery attempt(s) are past the retention window and were kept.' )
            ->expectsOutputToContain( '0 webhook delivery attempt(s) removed.' )
            ->assertSuccessful();

        expect( WebhookDelivery::query()->count() )->toBe( 1 );
    } );

    it( 'says nothing about pending deliveries that are inside the window', function (): void {
        config()->set( 'artisanpack.bookings.retention.webhook_delivery_days', 30 );

        $pending = WebhookDelivery::factory()->create( [ 'status' => WebhookDeliveryStatus::Pending ] );
        bookingRowAgedTo( $pending, '-2 days' );

        $this->artisan( 'bookings:prune-webhook-deliveries' )
            ->doesntExpectOutputToContain( 'past the retention window and were kept' )
            ->assertSuccessful();

        expect( WebhookDelivery::query()->count() )->toBe( 1 );
    } );

    it( 'lets the retention block override the webhook setting', function (): void {
        config()->set( 'artisanpack.bookings.retention.webhook_delivery_days', 5 );
        config()->set( 'artisanpack.bookings.webhooks.delivery_retention_days', 365 );

        $stale = WebhookDelivery::factory()->create( [ 'status' => WebhookDeliveryStatus::Dead ] );
        bookingRowAgedTo( $stale, '-10 days' );

        $this->artisan( 'bookings:prune-webhook-deliveries' )
            ->expectsOutputToContain( '1 webhook delivery attempt(s) removed.' )
            ->assertSuccessful();

        expect( WebhookDelivery::query()->count() )->toBe( 0 );
    } );

    it( 'prunes nothing when the window is switched off, whatever the webhook block says', function ( mixed $window ): void {
        // The fallback fires on an *absent* setting only. `retentionDays()` reads
        // a zero, a negative, and a typo all as null, so deferring on null alone
        // would turn switching this off into pruning at the webhook block's
        // thirty days — silently, destructively, and against what the config
        // comment and the README both promise.
        config()->set( 'artisanpack.bookings.retention.webhook_delivery_days', $window );
        config()->set( 'artisanpack.bookings.webhooks.delivery_retention_days', 30 );

        $stale = WebhookDelivery::factory()->create( [ 'status' => WebhookDeliveryStatus::Success ] );
        bookingRowAgedTo( $stale, '-400 days' );

        $this->artisan( 'bookings:prune-webhook-deliveries' )
            ->expectsOutputToContain( 'No webhook delivery retention window is configured' )
            ->assertSuccessful();

        expect( WebhookDelivery::query()->count() )->toBe( 1 );
    } )->with( [ 'zero' => 0, 'negative' => -1, 'nonsense' => 'soon' ] );

    it( 'reports without deleting on a dry run', function (): void {
        config()->set( 'artisanpack.bookings.retention.webhook_delivery_days', 30 );

        $stale = WebhookDelivery::factory()->create( [ 'status' => WebhookDeliveryStatus::Dead ] );
        bookingRowAgedTo( $stale, '-40 days' );

        $this->artisan( 'bookings:prune-webhook-deliveries', [ '--dry-run' => true ] )
            ->expectsOutputToContain( '1 webhook delivery attempt(s) would be removed.' )
            ->assertSuccessful();

        expect( WebhookDelivery::query()->count() )->toBe( 1 );
    } );
} );

describe( 'bookings:prune-calendar-events', function (): void {
    it( 'removes mappings for bookings that ended past the window', function (): void {
        config()->set( 'artisanpack.bookings.retention.calendar_events_ttl_days', 30 );

        CalendarEvent::factory()->for(
            Booking::factory()->create( [
                'start_time' => Carbon::parse( '-41 days' ),
                'end_time'   => Carbon::parse( '-41 days' )->addHour(),
            ] ),
        )->create();

        $recent = CalendarEvent::factory()->for(
            Booking::factory()->create( [
                'start_time' => Carbon::parse( '-2 days' ),
                'end_time'   => Carbon::parse( '-2 days' )->addHour(),
            ] ),
        )->create();

        $this->artisan( 'bookings:prune-calendar-events' )
            ->expectsOutputToContain( '1 calendar event mapping(s) removed.' )
            ->assertSuccessful();

        expect( CalendarEvent::query()->pluck( 'id' )->all() )->toBe( [ $recent->getKey() ] );
    } );

    it( 'keeps the mapping for a future booking however old the row is', function (): void {
        // Pruning by the row's own age would delete exactly the mappings a
        // reschedule still needs to follow.
        config()->set( 'artisanpack.bookings.retention.calendar_events_ttl_days', 30 );

        $mapping = CalendarEvent::factory()->for(
            Booking::factory()->create( [
                'start_time' => Carbon::parse( '+60 days' ),
                'end_time'   => Carbon::parse( '+60 days' )->addHour(),
            ] ),
        )->create();

        bookingRowAgedTo( $mapping, '-400 days' );

        $this->artisan( 'bookings:prune-calendar-events' )
            ->expectsOutputToContain( '0 calendar event mapping(s) removed.' )
            ->assertSuccessful();

        expect( CalendarEvent::query()->count() )->toBe( 1 );
    } );

    it( 'keeps a mapping just inside the window under a non-UTC app timezone', function (): void {
        // The cutoff comes back in the application's zone, because that is what
        // `created_at` is stored in — but this prune compares against `end_time`,
        // which the package writes as UTC. Without the `->utc()` at the call
        // site, Asia/Tokyo prunes nine hours' worth of mappings early.
        config()->set( 'artisanpack.bookings.retention.calendar_events_ttl_days', 30 );

        date_default_timezone_set( 'Asia/Tokyo' );
        Carbon::setTestNow( Carbon::parse( '2026-06-01 12:00:00', 'UTC' )->setTimezone( 'Asia/Tokyo' ) );

        // Ended 30 days ago minus four hours — inside the window by four hours,
        // and outside it by five if the cutoff is read as Tokyo wall clock.
        CalendarEvent::factory()->for(
            Booking::factory()->create( [
                'start_time' => Carbon::parse( '2026-06-01 12:00:00', 'UTC' )->subDays( 30 )->addHours( 3 ),
                'end_time'   => Carbon::parse( '2026-06-01 12:00:00', 'UTC' )->subDays( 30 )->addHours( 4 ),
            ] ),
        )->create();

        $this->artisan( 'bookings:prune-calendar-events' )
            ->expectsOutputToContain( '0 calendar event mapping(s) removed.' )
            ->assertSuccessful();

        expect( CalendarEvent::query()->count() )->toBe( 1 );
    } );

    it( 'reaches a soft-deleted booking\'s mapping', function (): void {
        config()->set( 'artisanpack.bookings.retention.calendar_events_ttl_days', 30 );

        $booking = Booking::factory()->create( [
            'start_time' => Carbon::parse( '-41 days' ),
            'end_time'   => Carbon::parse( '-41 days' )->addHour(),
        ] );

        CalendarEvent::factory()->for( $booking )->create();

        $booking->delete();

        $this->artisan( 'bookings:prune-calendar-events' )
            ->expectsOutputToContain( '1 calendar event mapping(s) removed.' )
            ->assertSuccessful();

        expect( CalendarEvent::query()->count() )->toBe( 0 );
    } );

    it( 'reports without deleting on a dry run', function (): void {
        config()->set( 'artisanpack.bookings.retention.calendar_events_ttl_days', 30 );

        CalendarEvent::factory()->for(
            Booking::factory()->create( [
                'start_time' => Carbon::parse( '-41 days' ),
                'end_time'   => Carbon::parse( '-41 days' )->addHour(),
            ] ),
        )->create();

        $this->artisan( 'bookings:prune-calendar-events', [ '--dry-run' => true ] )
            ->expectsOutputToContain( '1 calendar event mapping(s) would be removed.' )
            ->assertSuccessful();

        expect( CalendarEvent::query()->count() )->toBe( 1 );
    } );
} );

it( 'deletes more rows than one chunk holds', function (): void {
    // A prune deletes by a page of primary keys rather than in one statement, so
    // the loop turning the pages has to terminate — and has to keep going — on
    // more rows than a page holds. Five rows against a page of two.
    config()->set( 'artisanpack.bookings.retention.notification_log_days', 30 );

    NotificationLog::factory()->for( Booking::factory() )->count( 5 )->create();

    NotificationLog::query()->update( [ 'created_at' => Carbon::parse( '-100 days' ) ] );

    $this->app->make( Kernel::class )->registerCommand(
        new class() extends PruneNotificationLogCommand {
            protected int $pruneChunkSize = 2;
        },
    );

    $this->artisan( 'bookings:prune-notification-log' )
        ->expectsOutputToContain( '5 notification log row(s) removed.' )
        ->assertSuccessful();

    expect( NotificationLog::query()->count() )->toBe( 0 );
} );
