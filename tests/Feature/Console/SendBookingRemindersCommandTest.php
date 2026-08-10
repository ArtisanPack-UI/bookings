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
