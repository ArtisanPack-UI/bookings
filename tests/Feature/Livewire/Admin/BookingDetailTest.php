<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Events\BookingCancelled;
use ArtisanPackUI\Bookings\Events\BookingNoShow;
use ArtisanPackUI\Bookings\Events\BookingRescheduled;
use ArtisanPackUI\Bookings\Livewire\Admin\BookingDetail;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\IntakeSchemaVersion;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'showing a booking', function (): void {
    it( 'shows the booking and its customer', function (): void {
        $booking = Booking::factory()->create( [ 'customer_name' => 'Ada Lovelace' ] );

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->assertSee( 'Ada Lovelace' )
            ->assertSee( $booking->booking_number );
    } );

    it( 'lists a captured question the customer left unanswered', function (): void {
        $service = Service::factory()->create();

        IntakeSchemaVersion::factory()->for( $service )->create( [
            'version' => 1,
            'schema'  => [ 'fields' => [
                [ 'name' => 'goal', 'type' => 'text', 'label' => 'Goal question' ],
                [ 'name' => 'extra', 'type' => 'text', 'label' => 'Extra question' ],
            ] ],
        ] );

        $booking = Booking::factory()->for( $service, 'service' )->create( [
            'intake_schema_version' => 1,
            'intake_data'           => [ 'goal' => 'Answered goal' ],
        ] );

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->assertSee( 'Goal question' )
            ->assertSee( 'Answered goal' )
            ->assertSee( 'Extra question' );
    } );

    it( 'falls back to the raw answers when the captured version is gone', function (): void {
        $booking = Booking::factory()->create( [
            'intake_schema_version' => 99,
            'intake_data'           => [ 'freeform_note' => 'Something the customer wrote' ],
        ] );

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->assertSee( 'freeform_note' )
            ->assertSee( 'Something the customer wrote' );
    } );

    it( 'renders intake answers against the version the booking captured', function (): void {
        $service = Service::factory()->create();

        IntakeSchemaVersion::factory()->for( $service )->create( [
            'version' => 1,
            'schema'  => [ 'fields' => [ [ 'name' => 'goal', 'type' => 'text', 'label' => 'Original goal question' ] ] ],
        ] );

        IntakeSchemaVersion::factory()->for( $service )->create( [
            'version' => 2,
            'schema'  => [ 'fields' => [ [ 'name' => 'goal', 'type' => 'text', 'label' => 'Changed goal question' ] ] ],
        ] );

        $booking = Booking::factory()->for( $service, 'service' )->create( [
            'intake_schema_version' => 1,
            'intake_data'           => [ 'goal' => 'Learn Laravel' ],
        ] );

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->assertSee( 'Original goal question' )
            ->assertSee( 'Learn Laravel' )
            ->assertDontSee( 'Changed goal question' );
    } );
} );

describe( 'cancelling a booking', function (): void {
    it( 'cancels through the booking service as the admin', function (): void {
        Event::fake( [ BookingCancelled::class ] );

        $booking = Booking::factory()->confirmed()->create();

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->set( 'cancelReason', 'Rescheduled by phone.' )
            ->call( 'cancel' )
            ->assertDispatched( 'bookings-booking-updated', bookingId: $booking->id );

        expect( $booking->fresh()->status )->toBe( BookingStatus::Cancelled );

        Event::assertDispatched(
            BookingCancelled::class,
            static fn ( BookingCancelled $event ): bool => BookingActor::Admin === $event->actor
                && 'Rescheduled by phone.' === $event->reason,
        );
    } );

    it( 'fires the cancelled hook a consumer can listen on', function (): void {
        $ran = false;

        addAction( 'ap.bookings.cancelled', function () use ( &$ran ): void {
            $ran = true;
        } );

        $booking = Booking::factory()->confirmed()->create();

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->call( 'cancel' );

        expect( $ran )->toBeTrue();
    } );

    it( 'shows why a cancellation was refused rather than throwing', function (): void {
        $booking = Booking::factory()->cancelled()->create();

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->call( 'cancel' )
            ->assertHasErrors( 'booking' );
    } );
} );

describe( 'rescheduling a booking', function (): void {
    it( 'moves the booking through the booking service as the admin', function (): void {
        Event::fake( [ BookingRescheduled::class ] );

        $provider = ServiceProvider::factory()->create( [ 'timezone' => 'UTC' ] );
        $booking  = Booking::factory()->for( $provider, 'provider' )->confirmed()->create();

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->set( 'rescheduleStart', '2035-06-01T14:30' )
            ->call( 'reschedule' )
            ->assertDispatched( 'bookings-booking-updated', bookingId: $booking->id );

        expect( $booking->fresh()->start_time->utc()->format( 'Y-m-d H:i' ) )->toBe( '2035-06-01 14:30' );

        Event::assertDispatched(
            BookingRescheduled::class,
            static fn ( BookingRescheduled $event ): bool => BookingActor::Admin === $event->actor,
        );
    } );

    it( 'reads the typed time in the provider timezone', function (): void {
        // 14:30 in New York on 1 June is EDT (UTC-4), so the stored instant is 18:30 UTC.
        $provider = ServiceProvider::factory()->create( [ 'timezone' => 'America/New_York' ] );
        $booking  = Booking::factory()->for( $provider, 'provider' )->confirmed()->create();

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->set( 'rescheduleStart', '2035-06-01T14:30' )
            ->call( 'reschedule' );

        expect( $booking->fresh()->start_time->utc()->format( 'Y-m-d H:i' ) )->toBe( '2035-06-01 18:30' );
    } );

    it( 'falls back to the app timezone when the booking has no provider', function (): void {
        config()->set( 'app.timezone', 'UTC' );

        $booking = Booking::factory()->confirmed()->create( [ 'provider_id' => null ] );

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->set( 'rescheduleStart', '2035-06-01T14:30' )
            ->call( 'reschedule' );

        expect( $booking->fresh()->start_time->utc()->format( 'Y-m-d H:i' ) )->toBe( '2035-06-01 14:30' );
    } );

    it( 'shows why a malformed time was refused rather than throwing', function (): void {
        $booking = Booking::factory()->confirmed()->create();

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->set( 'rescheduleStart', 'not-a-date' )
            ->call( 'reschedule' )
            ->assertHasErrors( 'rescheduleStart' );
    } );

    it( 'fires the rescheduled hook a consumer can listen on', function (): void {
        $ran = false;

        addAction( 'ap.bookings.rescheduled', function () use ( &$ran ): void {
            $ran = true;
        } );

        $provider = ServiceProvider::factory()->create( [ 'timezone' => 'UTC' ] );
        $booking  = Booking::factory()->for( $provider, 'provider' )->confirmed()->create();

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->set( 'rescheduleStart', '2035-06-01T14:30' )
            ->call( 'reschedule' );

        expect( $ran )->toBeTrue();
    } );

    it( 'asks for a time when none is given', function (): void {
        $booking = Booking::factory()->confirmed()->create();

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->call( 'reschedule' )
            ->assertHasErrors( 'rescheduleStart' );
    } );
} );

describe( 'marking a no-show', function (): void {
    it( 'marks a no-show through the booking service as the admin', function (): void {
        Event::fake( [ BookingNoShow::class ] );

        $booking = Booking::factory()->confirmed()->create();

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->call( 'markNoShow' )
            ->assertDispatched( 'bookings-booking-updated', bookingId: $booking->id );

        expect( $booking->fresh()->status )->toBe( BookingStatus::NoShow );

        Event::assertDispatched(
            BookingNoShow::class,
            static fn ( BookingNoShow $event ): bool => BookingActor::Admin === $event->actor,
        );
    } );

    it( 'fires the no-show hook a consumer can listen on', function (): void {
        $ran = false;

        addAction( 'ap.bookings.noShow', function () use ( &$ran ): void {
            $ran = true;
        } );

        $booking = Booking::factory()->confirmed()->create();

        Livewire::test( BookingDetail::class, [ 'booking' => $booking ] )
            ->call( 'markNoShow' );

        expect( $ran )->toBeTrue();
    } );
} );
