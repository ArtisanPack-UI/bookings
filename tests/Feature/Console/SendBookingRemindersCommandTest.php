<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Notifications\BookingReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as Notifier;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail' ] );
    config()->set( 'artisanpack.bookings.notifications.database.notifiable', null );
    config()->set( 'artisanpack.bookings.notifications.reminder.hours_before', [ 24 ] );

    Carbon::setTestNow( '2026-06-01 12:00:00' );
} );

afterEach( function (): void {
    Carbon::setTestNow();

    removeAllFilters( 'ap.bookings.reminderScheduling' );
} );

it( 'sends the reminders that are due and says how many', function (): void {
    Notifier::fake();

    Booking::factory()->confirmed()->create( [
        'customer_email' => 'customer@example.com',
        'start_time'     => Carbon::parse( '2026-06-02 11:00:00' ),
        'end_time'       => Carbon::parse( '2026-06-02 11:30:00' ),
    ] );

    $this->artisan( 'bookings:send-reminders' )
        ->expectsOutputToContain( '1 reminder(s) sent.' )
        ->assertSuccessful();

    Notifier::assertSentOnDemandTimes( BookingReminder::class, 1 );
} );

it( 'sends nothing twice when it is run twice', function (): void {
    // The command is scheduled every fifteen minutes and may overlap itself.
    // A customer noticing that is the failure the notification log prevents.
    Notifier::fake();

    Booking::factory()->confirmed()->create( [
        'customer_email' => 'customer@example.com',
        'start_time'     => Carbon::parse( '2026-06-02 11:00:00' ),
        'end_time'       => Carbon::parse( '2026-06-02 11:30:00' ),
    ] );

    $this->artisan( 'bookings:send-reminders' )->assertSuccessful();
    $this->artisan( 'bookings:send-reminders' )
        ->expectsOutputToContain( '0 reminder(s) sent.' )
        ->assertSuccessful();

    expect( NotificationLog::query()->count() )->toBe( 1 );

    Notifier::assertSentOnDemandTimes( BookingReminder::class, 1 );
} );

it( 'reports nothing to do when no reminder is due', function (): void {
    Notifier::fake();

    $this->artisan( 'bookings:send-reminders' )
        ->expectsOutputToContain( '0 reminder(s) sent.' )
        ->assertSuccessful();
} );

it( 'honours a cadence a subscriber added, through the command', function (): void {
    // ReminderSchedulerTest proves the filter shapes the cadence. This proves the
    // command an operator actually schedules is wired to the same path: a window
    // added by a subscriber has to change what the cron sends, not just what the
    // service would have computed.
    Notifier::fake();

    addFilter( 'ap.bookings.reminderScheduling', static function ( array $hours ): array {
        $hours[] = 2;

        return $hours;
    } );

    Booking::factory()->confirmed()->create( [
        'customer_email' => 'customer@example.com',
        'start_time'     => Carbon::parse( '2026-06-01 13:00:00' ),
        'end_time'       => Carbon::parse( '2026-06-01 13:30:00' ),
    ] );

    // The booking is an hour out, so both windows are behind it: the configured
    // 24-hour one and the 2-hour one the subscriber added. Two rather than the
    // one config alone would have produced is the filter taking effect.
    $this->artisan( 'bookings:send-reminders' )
        ->expectsOutputToContain( '2 reminder(s) sent.' )
        ->assertSuccessful();

    Notifier::assertSentOnDemandTimes( BookingReminder::class, 2 );
} );

it( 'sends nothing when a subscriber empties the cadence', function (): void {
    Notifier::fake();

    addFilter( 'ap.bookings.reminderScheduling', static fn (): array => [] );

    Booking::factory()->confirmed()->create( [
        'customer_email' => 'customer@example.com',
        'start_time'     => Carbon::parse( '2026-06-02 11:00:00' ),
        'end_time'       => Carbon::parse( '2026-06-02 11:30:00' ),
    ] );

    $this->artisan( 'bookings:send-reminders' )
        ->expectsOutputToContain( '0 reminder(s) sent.' )
        ->assertSuccessful();

    expect( NotificationLog::query()->count() )->toBe( 0 );
} );
