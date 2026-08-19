<?php

declare( strict_types=1 );

it( 'schedules the reminder and completion sweeps out of the box', function (): void {
    $schedule = bookingSchedule();

    expect( $schedule )->toHaveKey( 'bookings:send-reminders' )
        ->and( $schedule['bookings:send-reminders'] )->toBe( '*/15 * * * *' )
        ->and( $schedule )->toHaveKey( 'bookings:complete-past' )
        ->and( $schedule['bookings:complete-past'] )->toBe( '0 * * * *' );
} );

it( 'schedules every prune daily', function (): void {
    $schedule = bookingSchedule();

    expect( $schedule['bookings:prune'] )->toBe( '0 3 * * *' )
        ->and( $schedule['bookings:prune-notification-log'] )->toBe( '10 3 * * *' )
        ->and( $schedule['bookings:prune-webhook-deliveries'] )->toBe( '20 3 * * *' )
        ->and( $schedule['bookings:prune-calendar-events'] )->toBe( '30 3 * * *' );
} );

it( 'never schedules the erasure command', function (): void {
    // Erasure answers a person's request against a named booking or address, so
    // it is something an operator runs in front of the output — never something
    // a clock decides to do.
    expect( bookingSchedule() )->not->toHaveKey( 'bookings:erase' );
} );

it( 'never schedules the manage token rotation', function (): void {
    // It invalidates every manage link the package has ever sent. That is
    // something an operator does in response to a leak, and nothing a clock
    // should ever decide to do.
    expect( bookingSchedule() )->not->toHaveKey( 'bookings:reissue-detached-manage-tokens' );
} );

it( 'schedules no calendar sweeps while every driver is off', function (): void {
    $schedule = bookingSchedule();

    expect( $schedule )->not->toHaveKey( 'bookings:calendar-refresh' )
        ->and( $schedule )->not->toHaveKey( 'bookings:calendar-watch-renew' )
        ->and( $schedule )->not->toHaveKey( 'bookings:calendar-apple-poll' );
} );

it( 'schedules the push-driven sweeps when a push driver is on', function (): void {
    config()->set( 'artisanpack.bookings.calendar.drivers.google.enabled', true );

    $schedule = bookingSchedule();

    expect( $schedule['bookings:calendar-refresh'] )->toBe( '0 0 * * *' )
        ->and( $schedule['bookings:calendar-watch-renew'] )->toBe( '0 * * * *' )
        ->and( $schedule )->not->toHaveKey( 'bookings:calendar-apple-poll' );
} );

it( 'polls Apple every fifteen minutes, and only for Apple', function (): void {
    // CalDAV has no push mechanism, so polling is the only thing standing
    // between a provider blocking out their afternoon and a customer booking
    // into it — and the interval is how wrong availability is allowed to get.
    config()->set( 'artisanpack.bookings.calendar.drivers.apple.enabled', true );

    $schedule = bookingSchedule();

    expect( $schedule['bookings:calendar-apple-poll'] )->toBe( '*/15 * * * *' )
        ->and( $schedule['bookings:calendar-refresh'] )->toBe( '0 0 * * *' )
        ->and( $schedule )->not->toHaveKey( 'bookings:calendar-watch-renew' );
} );

it( 'drops the reminder sweep when reminders are switched off', function (): void {
    config()->set( 'artisanpack.bookings.notifications.reminder.enabled', false );

    $schedule = bookingSchedule();

    expect( $schedule )->not->toHaveKey( 'bookings:send-reminders' )
        ->and( $schedule )->toHaveKey( 'bookings:complete-past' );
} );

it( 'guards every scheduled command against overlapping itself', function (): void {
    config()->set( 'artisanpack.bookings.calendar.drivers.google.enabled', true );
    config()->set( 'artisanpack.bookings.calendar.drivers.apple.enabled', true );

    $events = bookingScheduleEvents();

    expect( $events )->toHaveCount( 9 );

    foreach ( $events as $event ) {
        expect( $event->withoutOverlapping )->toBeTrue();
    }
} );

it( 'sizes every overlap lock to its own cadence rather than a day', function (): void {
    // The lock is released when a run finishes, so its expiry only matters when
    // one never does — a SIGKILL, an OOM kill, a container replaced mid-sweep.
    // The framework's 1440-minute default means one of those silences the
    // command until tomorrow, which for the Apple poll is exactly the failure
    // polling exists to prevent: CalDAV has no push to fall back on.
    config()->set( 'artisanpack.bookings.calendar.drivers.google.enabled', true );
    config()->set( 'artisanpack.bookings.calendar.drivers.apple.enabled', true );

    foreach ( bookingScheduleEvents() as $event ) {
        expect( $event->expiresAt )->toBeLessThan( 1440 );
    }
} );
