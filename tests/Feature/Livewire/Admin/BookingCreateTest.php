<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingAssignmentStrategy;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Livewire\Admin\BookingCreate;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Notifications\BookingConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as Notifier;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail' ] );
    config()->set( 'artisanpack.bookings.notifications.database.notifiable', null );
} );

afterEach( function (): void {
    removeAllActions( 'ap.bookings.creating' );
    removeAllActions( 'ap.bookings.created' );
    removeAllActions( 'ap.bookings.confirmed' );
    removeAllFilters( 'ap.bookings.notification.channels' );
} );

describe( 'creating a booking', function (): void {
    it( 'creates an admin-authored booking through the form', function (): void {
        [ $service, $providers ] = bookableService();

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'America/Chicago' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->assertDispatched( 'bookings-booking-created' );

        $booking = Booking::query()->where( 'customer_email', 'sam@example.test' )->first();

        expect( $booking )->not->toBeNull()
            ->and( $booking->service_id )->toBe( $service->getKey() )
            ->and( $booking->provider_id )->toBe( $providers[0]->getKey() )
            ->and( $booking->assignment_strategy )->toBe( BookingAssignmentStrategy::Admin )
            ->and( $booking->status )->toBe( BookingStatus::Confirmed )
            ->and( $booking->customer_timezone )->toBe( 'America/Chicago' )
            ->and( $booking->start_time->equalTo( bookingStart() ) )->toBeTrue();
    } );

    it( 'reads the appointment time in the stated timezone', function (): void {
        [ $service, $providers ] = bookableService( 1, 'America/New_York' );

        // The same wall-clock time, but stated in New York, is an hour earlier as
        // an instant than it would be in Chicago — the zone is what fixes it.
        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'America/New_York' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'save' )
            ->assertHasNoErrors();

        $booking = Booking::query()->where( 'customer_email', 'sam@example.test' )->first();

        expect( $booking->start_time->equalTo( bookingStart( '10:00', 'America/New_York' ) ) )->toBeTrue();
    } );

    it( 'defaults the timezone to the provider when one is chosen', function (): void {
        [ $service, $providers ] = bookableService( 1, 'America/New_York' );

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->assertSet( 'timezone', 'America/New_York' );
    } );

    it( 'keeps an administrator-set timezone when the provider changes', function (): void {
        [ $service, $providers ] = bookableService( 1, 'America/New_York' );

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'timezone', 'Europe/London' )
            ->set( 'providerId', $providers[0]->getKey() )
            ->assertSet( 'timezone', 'Europe/London' );
    } );
} );

describe( 'validation', function (): void {
    it( 'requires a service, a provider, a name, and an email', function (): void {
        Livewire::test( BookingCreate::class )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->call( 'save' )
            ->assertHasErrors( [
                'serviceId'     => 'required',
                'providerId'    => 'required',
                'customerName'  => 'required',
                'customerEmail' => 'required',
            ] );
    } );

    it( 'rejects a timezone it does not recognise', function (): void {
        [ $service, $providers ] = bookableService();

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'Not/AZone' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'save' )
            ->assertHasErrors( [ 'timezone' ] );
    } );

    it( 'surfaces a taken slot as an error rather than an exception', function (): void {
        [ $service, $providers ] = bookableService();

        Booking::factory()->confirmed()->create( [
            'service_id'  => $service->getKey(),
            'provider_id' => $providers[0]->getKey(),
            'start_time'  => bookingStart(),
            'end_time'    => bookingStart( '11:00' ),
        ] );

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'America/Chicago' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'save' )
            ->assertHasErrors( [ 'startTime' ] );
    } );
    it( 'surfaces a refused intake answer against its own field', function (): void {
        [ $service, $providers ] = bookableService();

        $service->update( [ 'intake_schema' => [ 'fields' => [
            [ 'name' => 'goal', 'type' => 'text', 'label' => 'Your goal', 'required' => true ],
        ] ] ] );

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'America/Chicago' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->set( 'intake.goal', '' )
            ->call( 'save' )
            ->assertHasErrors( [ 'intake.goal' ] );

        expect( Booking::query()->where( 'customer_email', 'sam@example.test' )->exists() )->toBeFalse();
    } );

    it( 'refuses a service that has been retired, even by a tampered id', function (): void {
        [ $service, $providers ] = bookableService();

        // Retired after the form loaded, then the id set straight onto the
        // property — the path a client takes past the active-only dropdown.
        $service->update( [ 'is_active' => false ] );

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'America/Chicago' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'save' )
            ->assertHasErrors( [ 'serviceId' ] );

        expect( Booking::query()->where( 'customer_email', 'sam@example.test' )->exists() )->toBeFalse();
    } );

    it( 'refuses a provider that does not offer the service', function (): void {
        [ $service, $providers ] = bookableService();

        // A real provider, but one this service was never attached to. The
        // component passes the id straight to BookingService, which refuses it —
        // surfaced as a generic message rather than the raw exception.
        $stranger = ArtisanPackUI\Bookings\Models\ServiceProvider::factory()->create();

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $stranger->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'America/Chicago' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'save' )
            ->assertHasErrors( [ 'startTime' ] );

        expect( Booking::query()->where( 'customer_email', 'sam@example.test' )->exists() )->toBeFalse();
    } );
} );

describe( 'the form lifecycle', function (): void {
    it( 'preselects a service passed to mount', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingCreate::class, [ 'serviceId' => $service->getKey() ] )
            ->assertSet( 'serviceId', $service->getKey() );
    } );

    it( 'clears the provider when the service changes', function (): void {
        [ $service, $providers ] = bookableService();
        [ $other ]               = bookableService();

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'serviceId', $other->getKey() )
            ->assertSet( 'providerId', null );
    } );

    it( 'asks the host to close when cancelled', function (): void {
        Livewire::test( BookingCreate::class )
            ->call( 'cancel' )
            ->assertDispatched( 'bookings-booking-create-cancelled' );
    } );
} );

describe( 'notifying the customer', function (): void {
    it( 'emails the customer a confirmation by default', function (): void {
        Notifier::fake();

        [ $service, $providers ] = bookableService();

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'America/Chicago' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'save' )
            ->assertHasNoErrors();

        Notifier::assertSentOnDemandTimes( BookingConfirmation::class, 1 );

        expect( NotificationLog::query()->where( 'type', NotificationType::Confirmation->value )->count() )->toBe( 1 );
    } );

    it( 'suppresses the confirmation when notify is unchecked, but still creates and confirms the booking', function (): void {
        Notifier::fake();

        $created        = new stdClass();
        $created->fired = false;

        addAction( 'ap.bookings.created', static function () use ( $created ): void {
            $created->fired = true;
        } );

        [ $service, $providers ] = bookableService();

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'America/Chicago' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->set( 'notifyCustomer', false )
            ->call( 'save' )
            ->assertHasNoErrors();

        $booking = Booking::query()->where( 'customer_email', 'sam@example.test' )->first();

        // Suppressed the send, not the booking: the row exists, it is confirmed,
        // and `ap.bookings.created` fired for anyone listening downstream.
        expect( $booking )->not->toBeNull()
            ->and( $booking->status )->toBe( BookingStatus::Confirmed )
            ->and( $created->fired )->toBeTrue();

        Notifier::assertNothingSent();

        expect( NotificationLog::query()->where( 'type', NotificationType::Confirmation->value )->count() )->toBe( 0 );
    } );

    it( 'expresses the suppression through the channels filter, as the final word', function (): void {
        Notifier::fake();

        // A consumer that insists on the mail channel. The suppression is
        // expressed the same way — through `ap.bookings.notification.channels` —
        // and registered to run last, so an unchecked "notify customer" empties
        // the list even after a subscriber has put a channel back on it.
        addFilter(
            'ap.bookings.notification.channels',
            static fn (): array => [ 'mail' ],
            1,
        );

        [ $service, $providers ] = bookableService();

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'America/Chicago' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->set( 'notifyCustomer', false )
            ->call( 'save' )
            ->assertHasNoErrors();

        Notifier::assertNothingSent();

        expect( NotificationLog::query()->where( 'type', NotificationType::Confirmation->value )->count() )->toBe( 0 );
    } );

    it( 'stops suppressing once a suppressed write has failed', function (): void {
        Notifier::fake();

        [ $service, $providers ] = bookableService();

        // The 10:00 slot is taken, so the first save — with notifications off —
        // throws inside the suppressing filter. If the `finally` did not remove
        // it, the second booking's confirmation would be silenced too.
        Booking::factory()->confirmed()->create( [
            'service_id'  => $service->getKey(),
            'provider_id' => $providers[0]->getKey(),
            'start_time'  => bookingStart(),
            'end_time'    => bookingStart( '11:00' ),
        ] );

        $component = Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'America/Chicago' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->set( 'notifyCustomer', false )
            ->call( 'save' )
            ->assertHasErrors( [ 'startTime' ] );

        Notifier::assertNothingSent();

        // A second booking in the same component, notifications on, on a free
        // slot. Its confirmation must send — proving the earlier suppression was
        // cleaned up rather than left registered.
        $component
            ->set( 'startTime', '2026-06-01T11:00' )
            ->set( 'notifyCustomer', true )
            ->call( 'save' )
            ->assertHasNoErrors();

        Notifier::assertSentOnDemandTimes( BookingConfirmation::class, 1 );
    } );
} );

describe( 'the extension hooks', function (): void {
    it( 'fires creating, created, and confirmed on the admin path', function (): void {
        $fired            = new stdClass();
        $fired->creating  = false;
        $fired->created   = false;
        $fired->confirmed = false;

        addAction( 'ap.bookings.creating', static function () use ( $fired ): void {
            $fired->creating = true;
        } );

        addAction( 'ap.bookings.created', static function ( Booking $booking ) use ( $fired ): void {
            $fired->created = 'sam@example.test' === $booking->customer_email;
        } );

        addAction( 'ap.bookings.confirmed', static function ( Booking $booking ) use ( $fired ): void {
            $fired->confirmed = 'sam@example.test' === $booking->customer_email;
        } );

        [ $service, $providers ] = bookableService();

        Livewire::test( BookingCreate::class )
            ->set( 'serviceId', $service->getKey() )
            ->set( 'providerId', $providers[0]->getKey() )
            ->set( 'startTime', '2026-06-01T10:00' )
            ->set( 'timezone', 'America/Chicago' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( $fired->creating )->toBeTrue()
            ->and( $fired->created )->toBeTrue()
            ->and( $fired->confirmed )->toBeTrue();
    } );
} );
