<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Events\BookingReassigned;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Notifications\BookingProviderAssigned;
use ArtisanPackUI\Bookings\Notifications\BookingProviderUnassigned;
use ArtisanPackUI\Bookings\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as Notifier;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * Builds a booking sitting on a provider with a deliverable address.
 */
function reassignedBooking( ?ServiceProvider $provider = null ): Booking
{
    $provider ??= ServiceProvider::factory()->create( [ 'email' => 'current@example.test' ] );

    return Booking::factory()->confirmed()->for( $provider, 'provider' )->create();
}

it( 'tells the new provider and the previous provider about the move', function (): void {
    Notifier::fake();

    $previous = ServiceProvider::factory()->create( [ 'email' => 'previous@example.test' ] );
    $current  = ServiceProvider::factory()->create( [ 'email' => 'current@example.test' ] );

    $booking = reassignedBooking( $current );

    BookingReassigned::dispatch( $booking, $previous->getKey() );

    Notifier::assertSentOnDemandTimes( BookingProviderAssigned::class, 1 );
    Notifier::assertSentOnDemandTimes( BookingProviderUnassigned::class, 1 );

    $assignedLog = NotificationLog::query()
        ->where( 'type', NotificationType::ProviderAssigned->value )
        ->sole();

    // The log records the provider as an internal reference, not their address —
    // a provider is staff, not the subject of a customer's erasure.
    expect( $assignedLog->recipient )->toBe( ServiceProvider::class . ':' . $current->getKey() )
        ->and( NotificationLog::query()->where( 'type', NotificationType::ProviderUnassigned->value )->count() )->toBe( 1 );
} );

it( 'tells only the new provider when the booking had no previous provider', function (): void {
    Notifier::fake();

    BookingReassigned::dispatch( reassignedBooking(), null );

    Notifier::assertSentOnDemandTimes( BookingProviderAssigned::class, 1 );
    Notifier::assertSentOnDemandTimes( BookingProviderUnassigned::class, 0 );
} );

it( 'does not tell the previous provider when the move lands back on them', function (): void {
    Notifier::fake();

    $provider = ServiceProvider::factory()->create( [ 'email' => 'current@example.test' ] );
    $booking  = reassignedBooking( $provider );

    BookingReassigned::dispatch( $booking, $provider->getKey() );

    Notifier::assertSentOnDemandTimes( BookingProviderUnassigned::class, 0 );
} );

it( 'sends nothing to a provider with no deliverable address', function (): void {
    Notifier::fake();

    $current = ServiceProvider::factory()->create( [ 'email' => null ] );
    $booking = reassignedBooking( $current );

    BookingReassigned::dispatch( $booking, null );

    Notifier::assertNothingSent();

    expect( NotificationLog::query()->where( 'type', NotificationType::ProviderAssigned->value )->count() )->toBe( 0 );
} );

it( 're-notifies on each successive reassignment, not only the first', function (): void {
    // Provider notices are claimed with a null schedule, and null schedules are
    // distinct in the unique index — so a booking moved A -> B -> C tells C it
    // was assigned and B it was removed, rather than dropping every notice after
    // the first move.
    Notifier::fake();

    $a = ServiceProvider::factory()->create( [ 'email' => 'a@example.test' ] );
    $b = ServiceProvider::factory()->create( [ 'email' => 'b@example.test' ] );
    $c = ServiceProvider::factory()->create( [ 'email' => 'c@example.test' ] );

    $booking = Booking::factory()->confirmed()->for( $a, 'provider' )->create();

    $booking->provider()->associate( $b );
    $booking->save();
    BookingReassigned::dispatch( $booking->refresh()->load( 'provider' ), $a->getKey() );

    $booking->provider()->associate( $c );
    $booking->save();
    BookingReassigned::dispatch( $booking->refresh()->load( 'provider' ), $b->getKey() );

    Notifier::assertSentOnDemandTimes( BookingProviderAssigned::class, 2 );
    Notifier::assertSentOnDemandTimes( BookingProviderUnassigned::class, 2 );

    expect( NotificationLog::query()->where( 'booking_id', $booking->getKey() )->where( 'type', NotificationType::ProviderAssigned->value )->count() )->toBe( 2 )
        ->and( NotificationLog::query()->where( 'booking_id', $booking->getKey() )->where( 'type', NotificationType::ProviderUnassigned->value )->count() )->toBe( 2 );
} );

it( 'sends nothing for a booking whose personal data has been erased', function (): void {
    // The provider copy carries the customer's details, so an erased booking has
    // none safe to send — the same rail the customer channels enforce.
    Notifier::fake();

    $current  = ServiceProvider::factory()->create( [ 'email' => 'current@example.test' ] );
    $previous = ServiceProvider::factory()->create( [ 'email' => 'previous@example.test' ] );

    $booking = Booking::factory()->erased()->for( $current, 'provider' )->create();

    BookingReassigned::dispatch( $booking, $previous->getKey() );

    Notifier::assertNothingSent();

    expect( NotificationLog::query()->where( 'booking_id', $booking->getKey() )->count() )->toBe( 0 );
} );

it( 'shows the previous provider their own timezone in the removed notice', function (): void {
    // The booking now points at the new provider, so a "removed" notice rendered
    // off the booking's own provider would show the previous provider the new
    // provider's clock face under a label saying it is theirs.
    $previous = ServiceProvider::factory()->create( [ 'email' => 'previous@example.test', 'timezone' => 'Pacific/Auckland' ] );
    $current  = ServiceProvider::factory()->create( [ 'email' => 'current@example.test', 'timezone' => 'America/Chicago' ] );

    $booking = Booking::factory()->confirmed()->for( $current, 'provider' )->create();

    $html = (string) app( NotificationService::class )
        ->notificationForProvider( NotificationType::ProviderUnassigned, $booking )
        ->forProvider( $previous )
        ->toMail( null )->render();

    $inAuckland = $booking->start_time->copy()->setTimezone( 'Pacific/Auckland' )->format( 'l, j F Y \a\t H:i' );
    $inChicago  = $booking->start_time->copy()->setTimezone( 'America/Chicago' )->format( 'l, j F Y \a\t H:i' );

    expect( $html )->toContain( $inAuckland )
        ->and( $html )->not->toContain( $inChicago );
} );

it( 'does not notify a previous provider on another site', function (): void {
    // The id rides on the event raw; a foreign one must not put a customer's
    // details in front of another tenant's provider.
    Notifier::fake();

    $current  = ServiceProvider::factory()->create( [ 'email' => 'current@example.test' ] );
    $previous = ServiceProvider::factory()->create( [ 'email' => 'previous@example.test' ] );
    $previous->forceFill( [ 'site_id' => 999 ] )->save();

    $booking = Booking::factory()->confirmed()->for( $current, 'provider' )->create();
    $booking->forceFill( [ 'site_id' => 1 ] )->save();

    BookingReassigned::dispatch( $booking, $previous->getKey() );

    Notifier::assertSentOnDemandTimes( BookingProviderAssigned::class, 1 );
    Notifier::assertSentOnDemandTimes( BookingProviderUnassigned::class, 0 );
} );

it( 'honours the per-message enabled toggle', function (): void {
    config()->set( 'artisanpack.bookings.notifications.provider_assigned.enabled', false );

    Notifier::fake();

    $previous = ServiceProvider::factory()->create( [ 'email' => 'previous@example.test' ] );
    $booking  = reassignedBooking();

    BookingReassigned::dispatch( $booking, $previous->getKey() );

    Notifier::assertSentOnDemandTimes( BookingProviderAssigned::class, 0 );
    Notifier::assertSentOnDemandTimes( BookingProviderUnassigned::class, 1 );
} );
