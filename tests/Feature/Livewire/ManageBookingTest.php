<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Events\BookingCancelled;
use ArtisanPackUI\Bookings\Events\BookingRescheduled;
use ArtisanPackUI\Bookings\Livewire\Public\ManageBooking;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use ArtisanPackUI\Bookings\Support\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // The Monday every booking helper works around, far enough ahead of the
    // 1 June diary that the twenty-four hour change window is wide open.
    $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );
} );

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.availableSlots' );
    removeAllFilters( 'ap.bookings.slotBookable' );
} );

/**
 * Books an appointment and hands back the link its customer would be emailed.
 *
 * @param  string  $start  The provider-local clock face to book.
 *
 * @return array{0: Booking, 1: string, 2: Service} The booking, its plain manage
 *                                                  token, and the service.
 */
function manageableBooking( string $start = '10:00' ): array
{
    [ $service ] = bookableService();

    $booking = bookingService()->create( bookingCustomer( [
        'service'    => $service,
        'start_time' => bookingStart( $start ),
    ] ) );

    return [ $booking, app( ManageTokenService::class )->issueFor( $booking ), $service ];
}

describe( 'reading the booking', function (): void {
    it( 'shows the appointment the token was minted for', function (): void {
        [ $booking, $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->assertSee( $booking->booking_number )
            ->assertSee( 'Monday 1 June 2026, 10:00 am' )
            ->assertSee( 'America/Chicago' )
            ->assertSee( 'Reschedule' )
            ->assertSee( 'Cancel appointment' )
            // Twenty-four hours before the appointment, in the customer's zone.
            ->assertSee( 'Sunday 31 May 2026, 10:00 am' );
    } );

    it( 'states the times in the browser\'s zone once the browser has said', function (): void {
        [ , $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->set( 'timezone', 'Europe/Berlin' )
            ->assertSee( 'Europe/Berlin' )
            ->assertSee( 'Monday 1 June 2026, 5:00 pm' );
    } );

    it( 'refuses a timezone the machine has never heard of', function (): void {
        [ , $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->set( 'timezone', 'Moon/Tranquility' )
            ->assertSet( 'timezone', '' );
    } );

    it( 'says the same thing to every token that does not manage a booking', function (): void {
        manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => str_repeat( 'a', 64 ) ] )
            ->assertSee( 'That booking link is no longer valid.' )
            ->assertDontSee( 'Cancel appointment' );
    } );

    it( 'stops answering for a token that has been reissued', function (): void {
        [ $booking, $token ] = manageableBooking();

        app( ManageTokenService::class )->issueFor( $booking );

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->assertSee( 'That booking link is no longer valid.' );
    } );

    it( 'refuses to be mounted anywhere a token cannot be found', function (): void {
        // Blank rather than loud would look exactly like an expired link, which
        // is a misconfiguration nobody ever reports.
        Livewire::test( ManageBooking::class );
        // Livewire renders inside a view, so the throw arrives wrapped.
    } )->throws( ViewException::class, 'ManageBooking was mounted without a manage token' );

    it( 'refuses a token that is not shaped like one before it queries', function (): void {
        Livewire::test( ManageBooking::class, [ 'token' => 'not-a-token' ] );
    } )->throws( ViewException::class, 'ManageBooking was mounted without a manage token' );
} );

describe( 'cancelling', function (): void {
    it( 'cancels through the domain service, as the customer', function (): void {
        Event::fake( [ BookingCancelled::class ] );

        [ $booking, $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startCancel' )
            ->assertSee( 'Cancel this appointment?' )
            ->set( 'reason', 'Something came up' )
            ->call( 'cancel' )
            ->assertHasNoErrors()
            ->assertSee( 'Your appointment has been cancelled.' )
            ->assertSee( 'Status: Cancelled' )
            // The same render, not the next one: a page offering to cancel an
            // appointment that has just been cancelled is worse than no page.
            ->assertDontSee( 'Cancel appointment' )
            ->assertDontSee( 'Reschedule' );

        expect( $booking->refresh()->status )->toBe( BookingStatus::Cancelled );

        Event::assertDispatched(
            BookingCancelled::class,
            static fn ( BookingCancelled $event ): bool => BookingActor::Customer === $event->actor
                && 'Something came up' === $event->reason,
        );
    } );

    it( 'fires the cancellation hook a consumer registered', function (): void {
        [ , $token ] = manageableBooking();

        $seen = null;

        addAction( 'ap.bookings.cancelled', static function ( Booking $booking ) use ( &$seen ): void {
            $seen = $booking->getKey();
        } );

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'cancel' )
            ->assertHasNoErrors();

        expect( $seen )->toBe( Booking::query()->sole()->getKey() );

        removeAllActions( 'ap.bookings.cancelled' );
    } );

    it( 'cleans the reason the way the API endpoint cleans it', function (): void {
        Event::fake( [ BookingCancelled::class ] );

        [ , $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->set( 'reason', '  <b>Ill</b>  ' )
            ->call( 'cancel' )
            ->assertHasNoErrors();

        Event::assertDispatched(
            BookingCancelled::class,
            static fn ( BookingCancelled $event ): bool => 'Ill' === $event->reason,
        );
    } );

    it( 'refuses a reason longer than the column will take', function (): void {
        [ $booking, $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->set( 'reason', str_repeat( 'a', 1001 ) )
            ->call( 'cancel' )
            ->assertHasErrors( [ 'reason' => 'max' ] );

        expect( $booking->refresh()->status )->toBe( BookingStatus::Confirmed );
    } );

    it( 'refuses a cancellation inside the minimum notice period', function (): void {
        [ $booking, $token ] = manageableBooking();

        // Three hours before a fifteen-hundred appointment, against a
        // twenty-four hour window.
        $this->travelTo( CarbonImmutable::parse( '2026-06-01 12:00:00', 'UTC' ) );

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->assertDontSee( 'Cancel appointment' )
            ->assertSee( 'This appointment can no longer be changed online.' )
            ->call( 'cancel' )
            ->assertHasErrors( 'cancel' )
            ->assertSee( 'It is too close to your appointment to cancel it online.' );

        expect( $booking->refresh()->status )->toBe( BookingStatus::Confirmed );
    } );

    it( 'refuses a cancellation the installation does not offer at all', function (): void {
        config()->set( 'artisanpack.bookings.cancellation.allowed', false );

        [ $booking, $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->assertDontSee( 'Cancel appointment' )
            // Rescheduling is governed separately, and is still on offer.
            ->assertSee( 'Reschedule' )
            ->call( 'cancel' )
            ->assertHasErrors( 'cancel' )
            ->assertSee( 'Bookings cannot be cancelled from this link.' );

        expect( $booking->refresh()->status )->toBe( BookingStatus::Confirmed );
    } );

    it( 'refuses to cancel a booking that has already gone', function (): void {
        [ $booking, $token ] = manageableBooking();

        $page = Livewire::test( ManageBooking::class, [ 'token' => $token ] );

        // Cancelled from the other half of the API while the page sat open.
        bookingService()->cancel( $booking, BookingActor::Admin );

        $page->call( 'cancel' )
            ->assertHasErrors( 'cancel' )
            ->assertSee( 'That booking can no longer be cancelled.' );
    } );

    it( 'spends the rate limit on a refusal, as the middleware does', function (): void {
        config()->set( 'artisanpack.bookings.public.rate_limits.post', 1 );
        config()->set( 'artisanpack.bookings.cancellation.allowed', false );

        [ , $token ] = manageableBooking();

        // `bookings.rate-limit:post` is middleware on the endpoint this mirrors,
        // so it runs before the policy does. Spent only on the way to a write, a
        // refused cancellation would be a free round trip for whoever holds a
        // leaked link.
        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'cancel' )
            ->assertSee( 'Bookings cannot be cancelled from this link.' )
            ->call( 'cancel' )
            ->assertSee( 'Too many requests.' );
    } );

    it( 'spends the same rate limit bucket the reschedule does', function (): void {
        config()->set( 'artisanpack.bookings.public.rate_limits.post', 1 );

        [ $booking, $token ] = manageableBooking();

        // One allowance across the page rather than one per action, exactly as
        // the two endpoints behind it share the `post` bucket.
        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->call( 'chooseSlot', bookingStart( '11:00' )->toIso8601String() )
            ->call( 'reschedule' )
            ->assertHasNoErrors()
            ->call( 'cancel' )
            ->assertHasErrors( 'cancel' )
            ->assertSee( 'Too many requests.' );

        expect( $booking->refresh()->status )->toBe( BookingStatus::Confirmed );
    } );
} );

describe( 'rescheduling', function (): void {
    it( 'moves the booking to a slot on the same service and provider', function (): void {
        Event::fake( [ BookingRescheduled::class ] );

        [ $booking, $token ] = manageableBooking();

        $wanted = bookingStart( '11:00' );

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->assertSee( 'Choose a new time' )
            ->call( 'chooseDate', '2026-06-01' )
            ->assertSee( '11:00 am' )
            ->call( 'chooseSlot', $wanted->toIso8601String() )
            ->call( 'reschedule' )
            ->assertHasNoErrors()
            ->assertSee( 'Your appointment has been moved to' )
            ->assertSee( 'Monday 1 June 2026, 11:00 am' );

        $booking->refresh();

        expect( $booking->start_time->toIso8601String() )->toBe( $wanted->toIso8601String() )
            ->and( $booking->status )->toBe( BookingStatus::Confirmed );

        Event::assertDispatched(
            BookingRescheduled::class,
            static fn ( BookingRescheduled $event ): bool => BookingActor::Customer === $event->actor,
        );
    } );

    it( 'offers only days the provider actually works', function (): void {
        [ , $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->assertSee( 'Mon 8' )
            ->assertDontSee( 'Tue 2' );
    } );

    it( 'will not offer a slot a consumer filtered out of availability', function (): void {
        [ $booking, $token ] = manageableBooking();

        $refused = bookingStart( '11:00' );

        addFilter( 'ap.bookings.availableSlots', static fn ( array $slots ): array => array_values( array_filter(
            $slots,
            static fn ( Slot $slot ): bool => ! $slot->period->start->equalTo( $refused ),
        ) ) );

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->call( 'chooseDate', '2026-06-01' )
            ->assertDontSee( '11:00 am' )
            ->assertSee( '12:00 pm' )
            // Not merely absent from the list: a client that sends the instant
            // anyway is refused against the diary as the filter leaves it.
            ->call( 'chooseSlot', $refused->toIso8601String() )
            ->call( 'reschedule' )
            ->assertHasErrors( 'slotStart' )
            ->assertSee( 'That appointment time is not available.' );

        expect( $booking->refresh()->start_time->toIso8601String() )
            ->toBe( bookingStart()->toIso8601String() );
    } );

    it( 'refuses a time nobody is free at, however it was submitted', function (): void {
        [ $booking, $token ] = manageableBooking();

        // A Tuesday: the provider works Mondays.
        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->call( 'chooseSlot', CarbonImmutable::parse( '2026-06-02 10:00', 'America/Chicago' )->utc()->toIso8601String() )
            ->call( 'reschedule' )
            ->assertHasErrors( 'slotStart' );

        expect( $booking->refresh()->start_time->toIso8601String() )
            ->toBe( bookingStart()->toIso8601String() );
    } );

    it( 'refuses a time beyond the window bookings are taken in', function (): void {
        // A fortnight, against a diary sitting a week out.
        config()->set( 'artisanpack.bookings.booking_window.max_advance_minutes', 60 * 24 * 3 );

        [ $booking, $token ] = manageableBooking();

        // The endpoint enforces this through RescheduleBookingRequest, and there
        // is no request object on this side — so a client setting the property by
        // hand must still be refused, not merely offered nothing.
        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->assertSee( 'There are no appointments available this month.' )
            ->call( 'chooseSlot', bookingStart( '11:00' )->toIso8601String() )
            ->call( 'reschedule' )
            ->assertHasErrors( 'slotStart' )
            ->assertSee( 'That appointment time is not available.' );

        expect( $booking->refresh()->start_time->toIso8601String() )
            ->toBe( bookingStart()->toIso8601String() );
    } );

    it( 'refuses a time too soon to be booked', function (): void {
        [ $booking, $token ] = manageableBooking();

        // Everything inside the next fortnight is off the table, which puts the
        // whole of the 1 June diary out of reach.
        config()->set( 'artisanpack.bookings.booking_window.min_advance_minutes', 60 * 24 * 14 );

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->call( 'chooseSlot', bookingStart( '11:00' )->toIso8601String() )
            ->call( 'reschedule' )
            ->assertHasErrors( 'slotStart' );

        expect( $booking->refresh()->start_time->toIso8601String() )
            ->toBe( bookingStart()->toIso8601String() );
    } );

    it( 'treats a zero maximum as no limit rather than a shut window', function (): void {
        // Zero on the maximum reads the same way zero reads on the minimum: no
        // constraint. It must open the window, not collapse it — a blank setting
        // that emptied every calendar and blamed the customer's chosen time is the
        // failure this bound is meant not to have.
        config()->set( 'artisanpack.bookings.booking_window.max_advance_minutes', 0 );

        [ $booking, $token ] = manageableBooking();

        $wanted = bookingStart( '11:00' );

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->call( 'chooseSlot', $wanted->toIso8601String() )
            ->call( 'reschedule' )
            ->assertHasNoErrors();

        expect( $booking->refresh()->start_time->toIso8601String() )
            ->toBe( $wanted->toIso8601String() );
    } );

    it( 'refuses a move to the time the booking already has', function (): void {
        [ , $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->call( 'reschedule' )
            ->assertHasErrors( 'slotStart' )
            ->assertSee( 'Your booking is already at that time.' );
    } );

    it( 'refuses a confirmation with no time chosen', function (): void {
        [ , $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->call( 'reschedule' )
            ->assertHasErrors( 'slotStart' )
            ->assertSee( 'Choose a new time before confirming.' );
    } );

    it( 'refuses a reschedule inside the minimum notice period', function (): void {
        [ $booking, $token ] = manageableBooking();

        $this->travelTo( CarbonImmutable::parse( '2026-06-01 12:00:00', 'UTC' ) );

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->assertDontSee( 'Reschedule' )
            ->call( 'startReschedule' )
            ->assertHasErrors( 'slotStart' )
            ->assertSee( 'It is too close to your appointment to reschedule it online.' );

        expect( $booking->refresh()->start_time->toIso8601String() )
            ->toBe( bookingStart()->toIso8601String() );
    } );

    it( 'stops offering new time on a service that has been withdrawn', function (): void {
        [ $booking, $token, $service ] = manageableBooking();

        $service->forceFill( [ 'is_active' => false ] )->save();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->assertDontSee( 'Reschedule' )
            // The link goes on working for the part a customer whose service was
            // retired most needs.
            ->assertSee( 'Cancel appointment' )
            ->call( 'startReschedule' )
            ->assertHasErrors( 'slotStart' )
            ->assertSee( 'That service is no longer taking bookings.' );

        expect( $booking->refresh()->start_time->toIso8601String() )
            ->toBe( bookingStart()->toIso8601String() );
    } );

    it( 'discloses no availability for a booking it will not move', function (): void {
        [ , $token, $service ] = manageableBooking();

        $service->forceFill( [ 'is_active' => false ] )->save();

        // `choosingTime` is an ordinary public property, so a client can set it
        // without going through startReschedule(). The calendar behind it has to
        // be gated on the policy rather than on the flag: a withdrawn service's
        // availability is not otherwise public, since ServiceController resolves
        // only active ones.
        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->set( 'choosingTime', true )
            ->call( 'chooseDate', '2026-06-01' )
            // Not the booking's own 10:00, which the details above still state.
            ->assertDontSee( '11:00 am' )
            ->assertSee( 'There are no appointments available this month.' );
    } );

    it( 'refuses to move a booking that has already gone', function (): void {
        [ $booking, $token ] = manageableBooking();

        $page = Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->call( 'chooseSlot', bookingStart( '11:00' )->toIso8601String() );

        bookingService()->cancel( $booking, BookingActor::Admin );

        $page->call( 'reschedule' )
            ->assertHasErrors( 'slotStart' )
            ->assertSee( 'That booking can no longer be rescheduled.' );
    } );

    it( 'sends the customer back to the list when the slot has gone', function (): void {
        [ $booking, $token, $service ] = manageableBooking();

        $wanted = bookingStart( '11:00' );

        $page = Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->call( 'chooseDate', '2026-06-01' )
            ->call( 'chooseSlot', $wanted->toIso8601String() );

        // Booked out from under the page between the customer picking the time
        // and confirming it.
        Booking::factory()
            ->for( $service, 'service' )
            ->for( $booking->provider, 'provider' )
            ->create( [
                'start_time' => $wanted,
                'end_time'   => $wanted->addMinutes( 60 ),
            ] );

        $page->call( 'reschedule' )
            ->assertHasErrors( 'slotStart' )
            ->assertSet( 'slotStart', '' );

        expect( $booking->refresh()->start_time->toIso8601String() )
            ->toBe( bookingStart()->toIso8601String() );
    } );

    it( 'spends the same rate limit bucket the API endpoint does', function (): void {
        config()->set( 'artisanpack.bookings.public.rate_limits.post', 1 );

        [ $booking, $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->call( 'chooseSlot', bookingStart( '11:00' )->toIso8601String() )
            ->call( 'reschedule' )
            ->assertHasNoErrors()
            ->call( 'startReschedule' )
            ->call( 'chooseSlot', bookingStart( '12:00' )->toIso8601String() )
            ->call( 'reschedule' )
            ->assertHasErrors( 'slotStart' )
            ->assertSee( 'Too many requests.' );

        expect( $booking->refresh()->start_time->toIso8601String() )
            ->toBe( bookingStart( '11:00' )->toIso8601String() );
    } );

    it( 'leaves the appointment alone when the customer backs out', function (): void {
        [ , $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->call( 'chooseDate', '2026-06-01' )
            ->call( 'chooseSlot', bookingStart( '11:00' )->toIso8601String() )
            ->call( 'stopReschedule' )
            ->assertSet( 'choosingTime', false )
            ->assertSet( 'slotStart', '' )
            ->assertSet( 'date', '' )
            ->assertSee( 'Reschedule' );
    } );
} );

describe( 'input nothing else would survive', function (): void {
    it( 'renders rather than raises when the day is not a day', function (): void {
        [ , $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->set( 'date', '../../etc/passwd' )
            ->assertSet( 'date', '' )
            ->assertSee( 'Available days' );
    } );

    it( 'renders rather than raises when the time is not an instant', function (): void {
        [ , $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->set( 'slotStart', 'whenever' )
            ->assertSet( 'slotStart', '' )
            ->assertSee( 'Available days' );
    } );

    it( 'clamps a month shift a client sent from outside the template', function (): void {
        [ , $token ] = manageableBooking();

        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->call( 'startReschedule' )
            ->assertSet( 'month', '2026-06' )
            ->call( 'shiftMonth', PHP_INT_MAX )
            ->assertSet( 'month', '2027-06' )
            // The heading too, not only the property: everything dated is
            // computed and memoised, so a shift that forgot to invalidate them
            // moves the property and redraws the month just left.
            ->assertSee( 'June 2027' )
            ->call( 'shiftMonth', PHP_INT_MIN )
            ->assertSet( 'month', '2026-06' )
            ->assertSee( 'June 2026' );
    } );

    it( 'will not let a client point the page at somebody else\'s booking', function (): void {
        [ , $token ] = manageableBooking();

        // `#[Locked]`: the token is the credential, and a client that could set
        // it could walk every booking whose token it had guessed.
        Livewire::test( ManageBooking::class, [ 'token' => $token ] )
            ->set( 'token', str_repeat( 'b', 64 ) );
    } )->throws( Exception::class );
} );

describe( 'the timezone script', function (): void {
    it( 'hands Alpine an expression rather than a declaration', function (): void {
        [ , $token ] = manageableBooking();

        $html = Livewire::test( ManageBooking::class, [ 'token' => $token ] )->html();

        // Livewire runs the block through Alpine.evaluate(), which compiles it
        // as an expression rather than as a statement list — so a top-level
        // declaration is a SyntaxError, the block never runs, and every time on
        // the page silently stays in the wrong zone with nothing but a console
        // warning to say so. Nothing else in the suite executes JavaScript, so
        // this is the only place that failure can be caught.
        expect( browserTimezoneScript( $html ) )->toStartWith( '(' );
    } );
} );
