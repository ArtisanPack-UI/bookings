<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\BookingAssignmentStrategy;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Enums\ServiceAssignmentStrategy;
use ArtisanPackUI\Bookings\Events\BookingCancelled;
use ArtisanPackUI\Bookings\Events\BookingCompleted;
use ArtisanPackUI\Bookings\Events\BookingConfirmed;
use ArtisanPackUI\Bookings\Events\BookingNoShow;
use ArtisanPackUI\Bookings\Events\BookingReassigned;
use ArtisanPackUI\Bookings\Events\BookingRequested;
use ArtisanPackUI\Bookings\Events\BookingRescheduled;
use ArtisanPackUI\Bookings\Exceptions\IntakeValidationException;
use ArtisanPackUI\Bookings\Exceptions\InvalidBookingTransitionException;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Core\MultiTenancy\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

afterEach( function (): void {
    removeAllActions( 'ap.bookings.creating' );
    removeAllActions( 'ap.bookings.reassigned' );
    removeAllFilters( 'ap.bookings.availableProviders' );
    removeAllFilters( 'ap.bookings.roundRobin.selectProvider' );
} );

describe( 'create', function (): void {
    it( 'writes a booking against the provider the customer picked', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $booking = bookingService()->create( bookingCustomer( [
            'service'     => $service,
            'provider_id' => $providers[1]->getKey(),
            'start_time'  => bookingStart(),
        ] ) );

        expect( $booking->provider_id )->toBe( $providers[1]->id )
            ->and( $booking->assignment_strategy )->toBe( BookingAssignmentStrategy::Customer )
            ->and( $booking->start_time->equalTo( bookingStart() ) )->toBeTrue()
            ->and( $booking->end_time->equalTo( bookingStart( '11:00' ) ) )->toBeTrue()
            ->and( $booking->booking_number )->toStartWith( 'BK-' );
    } );

    it( 'confirms the booking it just created', function (): void {
        Event::fake( [ BookingRequested::class, BookingConfirmed::class ] );

        [ $service ] = bookableService();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $booking->status )->toBe( BookingStatus::Confirmed );

        Event::assertDispatched( BookingRequested::class );
        Event::assertDispatched(
            BookingConfirmed::class,
            static fn ( BookingConfirmed $event ): bool => BookingActor::System === $event->actor,
        );
    } );

    it( 'leaves the booking awaiting approval when automatic confirmation is off', function (): void {
        // A requested booking still holds the slot, which is the whole reason
        // switching this off is safe: nobody else can take the appointment while
        // somebody decides about it.
        config()->set( 'artisanpack.bookings.auto_confirm', false );

        [ $service ] = bookableService();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $booking->status )->toBe( BookingStatus::Requested )
            ->and( $booking->occupiesSlot() )->toBeTrue();
    } );

    it( 'declines to confirm a booking a listener cancelled on request', function (): void {
        // The veto BookingRequested carries. The event fires once the row exists
        // — a slot has to be held while an approval is pending — so a listener
        // that objects cancels it, and confirmation must notice.
        [ $service ] = bookableService();

        Event::listen( BookingRequested::class, function ( BookingRequested $event ): void {
            bookingService()->cancel( $event->booking, BookingActor::System, 'Blocked by policy.' );
        } );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $booking->status )->toBe( BookingStatus::Cancelled );
    } );

    it( 'refuses a slot nobody is free for', function (): void {
        [ $service ] = bookableService();

        // 03:00 local is outside the nine-to-five window every provider works.
        expect( fn () => bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '03:00' ),
        ] ) ) )->toThrow( SlotUnavailableException::class );
    } );

    it( 'refuses a provider who does not offer the service', function (): void {
        [ $service ] = bookableService();
        $stranger    = ServiceProvider::factory()->create();

        expect( fn () => bookingService()->create( bookingCustomer( [
            'service'     => $service,
            'provider_id' => $stranger->getKey(),
            'start_time'  => bookingStart(),
        ] ) ) )->toThrow( InvalidArgumentException::class );
    } );

    it( 'refuses a booking with nobody to send the confirmation to', function ( array $missing ): void {
        // The columns are NOT NULL, which sounds like enough and is not: an
        // empty string satisfies the database and produces a booking whose
        // manage link goes to an empty address and which no search an
        // administrator would run will ever surface.
        [ $service ] = bookableService();

        expect( fn () => bookingService()->create( array_merge( bookingCustomer(), $missing, [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) ) )->toThrow( InvalidArgumentException::class );

        expect( Booking::query()->count() )->toBe( 0 );
    } )->with( [
        'no name'     => [ [ 'customer_name' => '' ] ],
        'blank name'  => [ [ 'customer_name' => '   ' ] ],
        'no email'    => [ [ 'customer_email' => '' ] ],
        'blank email' => [ [ 'customer_email' => "\t" ] ],
    ] );

    it( 'books against the fallback provider when the service names one', function (): void {
        $provider = ServiceProvider::factory()->inTimezone( 'America/Chicago' )->create();
        $service  = Service::factory()->create( [
            'duration'            => 60,
            'buffer_before'       => 0,
            'buffer_after'        => 0,
            'assignment_strategy' => ServiceAssignmentStrategy::DefaultProvider,
            'default_provider_id' => $provider->getKey(),
        ] );

        config()->set( 'artisanpack.bookings.slot_interval', 60 );
        bookingsSchedule( $service, $provider );
        $service->providers()->detach( $provider->getKey() );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $booking->provider_id )->toBe( $provider->id )
            ->and( $booking->assignment_strategy )->toBe( BookingAssignmentStrategy::DefaultProvider );
    } );
} );

describe( 'round-robin assignment', function (): void {
    it( 'gives the slot to whoever has waited longest', function (): void {
        [ $service, $providers ] = bookableService( 3 );

        $providers[0]->forceFill( [ 'round_robin_last_assigned_at' => now()->subHour() ] )->save();
        $providers[1]->forceFill( [ 'round_robin_last_assigned_at' => now()->subDay() ] )->save();
        $providers[2]->forceFill( [ 'round_robin_last_assigned_at' => now()->subMinute() ] )->save();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $booking->provider_id )->toBe( $providers[1]->id )
            ->and( $booking->assignment_strategy )->toBe( BookingAssignmentStrategy::RoundRobin );
    } );

    it( 'puts a provider who has never been assigned anything at the front', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $providers[0]->forceFill( [ 'round_robin_last_assigned_at' => now()->subYear() ] )->save();
        $providers[1]->forceFill( [ 'round_robin_last_assigned_at' => null ] )->save();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $booking->provider_id )->toBe( $providers[1]->id );
    } );

    it( 'breaks a dead heat on weight', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $providers[0]->forceFill( [ 'round_robin_weight' => 1 ] )->save();
        $providers[1]->forceFill( [ 'round_robin_weight' => 5 ] )->save();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $booking->provider_id )->toBe( $providers[1]->id );
    } );

    it( 'moves the cursor on so the next booking goes elsewhere', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $first = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '10:00' ),
        ] ) );

        $second = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '13:00' ),
        ] ) );

        expect( $first->provider_id )->not->toBe( $second->provider_id )
            ->and( $providers[0]->fresh()->round_robin_last_assigned_at )->not->toBeNull()
            ->and( $providers[1]->fresh()->round_robin_last_assigned_at )->not->toBeNull();
    } );

    it( 'leaves the cursor alone when the customer picked the provider themselves', function (): void {
        // Choosing a name off a list is not taking a turn in the rota, and
        // crediting it as one would push that provider to the back of a queue
        // they were never in.
        [ $service, $providers ] = bookableService( 2 );

        bookingService()->create( bookingCustomer( [
            'service'     => $service,
            'provider_id' => $providers[0]->getKey(),
            'start_time'  => bookingStart(),
        ] ) );

        expect( $providers[0]->fresh()->round_robin_last_assigned_at )->toBeNull();
    } );

    it( 'falls through to the next candidate when it loses the race', function (): void {
        // The lost race, staged. The competing row is written straight through
        // the query builder from inside the lock, after availability has already
        // been read and immediately before the insert — which is exactly the
        // window the partial unique index exists to close.
        [ $service, $providers ] = bookableService( 2 );

        $providers[0]->forceFill( [ 'round_robin_last_assigned_at' => now()->subDay() ] )->save();
        $providers[1]->forceFill( [ 'round_robin_last_assigned_at' => now()->subHour() ] )->save();

        $attempts = 0;

        addAction( 'ap.bookings.creating', function ( array $attributes ) use ( &$attempts, $providers ): void {
            $attempts++;

            if ( 1 !== $attempts ) {
                return;
            }

            DB::table( 'bookings' )->insert( [
                'booking_number'        => 'BK-RACEWINNER1',
                'service_id'            => $attributes['service_id'],
                'provider_id'           => $providers[0]->getKey(),
                'customer_name'         => 'Someone Faster',
                'customer_email'        => 'faster@example.test',
                'customer_timezone'     => 'UTC',
                'start_time'            => $attributes['start_time'],
                'end_time'              => $attributes['end_time'],
                'status'                => BookingStatus::Confirmed->value,
                'assignment_strategy'   => BookingAssignmentStrategy::Customer->value,
                'intake_schema_version' => 1,
                'manage_token_hash'     => str_repeat( 'a', 64 ),
                'created_at'            => now(),
                'updated_at'            => now(),
            ] );
        } );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $attempts )->toBe( 2 )
            ->and( $booking->provider_id )->toBe( $providers[1]->id )
            ->and( Booking::query()->count() )->toBe( 2 );
    } );

    it( 'gives up once every candidate has lost the race', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        addAction( 'ap.bookings.creating', function ( array $attributes ) use ( $providers ): void {
            foreach ( $providers as $index => $provider ) {
                if ( (int) $provider->getKey() !== (int) $attributes['provider_id'] ) {
                    continue;
                }

                DB::table( 'bookings' )->insert( [
                    'booking_number'        => 'BK-BLOCKER' . $index,
                    'service_id'            => $attributes['service_id'],
                    'provider_id'           => $provider->getKey(),
                    'customer_name'         => 'Someone Faster',
                    'customer_email'        => 'faster@example.test',
                    'customer_timezone'     => 'UTC',
                    'start_time'            => $attributes['start_time'],
                    'end_time'              => $attributes['end_time'],
                    'status'                => BookingStatus::Confirmed->value,
                    'assignment_strategy'   => BookingAssignmentStrategy::Customer->value,
                    'intake_schema_version' => 1,
                    'manage_token_hash'     => str_repeat( (string) $index, 64 ),
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ] );
            }
        } );

        expect( fn () => bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) ) )->toThrow( SlotUnavailableException::class );
    } );

    it( 'refuses a slot that overlaps one already taken, not just an identical start', function (): void {
        // The hole a per-instant lock leaves. With the default fifteen-minute
        // interval a sixty-minute service offers a slot every quarter hour, each
        // overlapping the last three — so 09:00 and 09:15 are different start
        // times and the unique index, which keys on the start time, accepts
        // both. Only the day lock and the overlap check inside it refuse this.
        [ $service, $providers ] = bookableService( 1 );

        // After bookableService(), which sets its own interval. Set before it
        // and the quarter-hour slots this case is about would never be
        // generated, and the refusal below would be an ordinary "no such slot".
        config()->set( 'artisanpack.bookings.slot_interval', 15 );

        // Warm the resolver's cache for the day *before* the competing booking
        // exists. Without this the resolver recomputes, sees the clash itself,
        // and the overlap check is never reached — the test would pass with the
        // guard removed and prove nothing.
        availability()->resolve(
            $service,
            $providers[0],
            localDayWindow( '2026-06-01', 'America/Chicago' ),
        );

        // Written straight through the query builder, so no model event fires
        // and the cache stamp never moves: the resolver goes on reporting the
        // stale answer, which is exactly what a competing commit on another
        // machine looks like from here.
        DB::table( 'bookings' )->insert( [
            'booking_number'        => 'BK-OVERLAPPING1',
            'service_id'            => $service->getKey(),
            'provider_id'           => $providers[0]->getKey(),
            'customer_name'         => 'Already There',
            'customer_email'        => 'there@example.test',
            'customer_timezone'     => 'UTC',
            'start_time'            => bookingStart( '09:00' ),
            'end_time'              => bookingStart( '10:00' ),
            'status'                => BookingStatus::Confirmed->value,
            'assignment_strategy'   => BookingAssignmentStrategy::Customer->value,
            'intake_schema_version' => 1,
            'manage_token_hash'     => str_repeat( 'c', 64 ),
            'created_at'            => now(),
            'updated_at'            => now(),
        ] );

        expect( fn () => bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '09:15' ),
        ] ) ) )->toThrow( SlotUnavailableException::class );

        expect( Booking::query()->where( 'provider_id', $providers[0]->getKey() )->count() )->toBe( 1 );
    } );

    it( 'never double-books a provider the database has already given away', function (): void {
        [ $service, $providers ] = bookableService( 1 );

        bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( fn () => bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) ) )->toThrow( SlotUnavailableException::class );

        expect( Booking::query()->where( 'provider_id', $providers[0]->getKey() )->count() )->toBe( 1 );
    } );
} );

describe( 'intake data', function (): void {
    it( 'snapshots the schema version and keeps only the answers the form asked for', function (): void {
        [ $service ] = bookableService();

        $service->forceFill( [
            'intake_schema' => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'textarea', 'required' => true ],
                ],
            ],
            'intake_schema_version' => 4,
        ] )->save();

        $booking = bookingService()->create( bookingCustomer( [
            'service'     => $service,
            'start_time'  => bookingStart(),
            'intake_data' => [ 'goal' => 'Learn to juggle', 'smuggled' => 'not on the form' ],
        ] ) );

        expect( $booking->intake_schema_version )->toBe( 4 )
            ->and( $booking->intake_data )->toBe( [ 'goal' => 'Learn to juggle' ] );
    } );

    it( 'refuses a booking whose intake answers do not satisfy the form', function (): void {
        [ $service ] = bookableService();

        $service->forceFill( [
            'intake_schema' => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'textarea', 'required' => true ],
                ],
            ],
        ] )->save();

        expect( fn () => bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) ) )->toThrow( IntakeValidationException::class );

        expect( Booking::query()->count() )->toBe( 0 );
    } );
} );

describe( 'lifecycle', function (): void {
    it( 'moves a booking to a new time and reports where it came from', function (): void {
        Event::fake( [ BookingRescheduled::class ] );

        [ $service ] = bookableService();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '10:00' ),
        ] ) );

        bookingService()->reschedule( $booking, bookingStart( '14:00' ), BookingActor::Customer );

        expect( $booking->fresh()->start_time->equalTo( bookingStart( '14:00' ) ) )->toBeTrue()
            ->and( $booking->fresh()->end_time->equalTo( bookingStart( '15:00' ) ) )->toBeTrue();

        Event::assertDispatched(
            BookingRescheduled::class,
            static fn ( BookingRescheduled $event ): bool => $event->previousPeriod->start->equalTo( bookingStart( '10:00' ) )
                && BookingActor::Customer === $event->actor,
        );
    } );

    it( 'refuses to move a booking onto time the provider has already sold', function (): void {
        [ $service, $providers ] = bookableService( 1 );

        $first = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '10:00' ),
        ] ) );

        Booking::factory()
            ->for( $service )
            ->for( $providers[0], 'provider' )
            ->confirmed()
            ->startingAt( bookingStart( '14:00' ), 60 )
            ->create();

        expect( fn () => bookingService()->reschedule( $first, bookingStart( '14:00' ) ) )
            ->toThrow( SlotUnavailableException::class );

        expect( $first->fresh()->start_time->equalTo( bookingStart( '10:00' ) ) )->toBeTrue();
    } );

    it( 'leaves the refused time nowhere on the booking it refused', function (): void {
        // A caller that catches the refusal still holds the booking, and saving
        // it later must not resurrect the time that was just refused.
        //
        // This covers the clash branch, which returns before the instance is
        // touched at all. The other refusal path — the unique index firing on
        // `save()` — does mutate the instance first and calls `refresh()` to put
        // it back, and that branch is deliberately not staged here: it is only
        // reachable when a competing write bypassed the slot lock, and there is
        // no seam between the clash check and the save to insert one from. A
        // test that appeared to cover it would be passing for the wrong reason.
        [ $service, $providers ] = bookableService( 1 );

        $first = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '10:00' ),
        ] ) );

        // Written straight through the query builder so availability never sees
        // it — the unique index is what has to refuse the move, which is the
        // path that leaves the instance dirty.
        DB::table( 'bookings' )->insert( [
            'booking_number'        => 'BK-OCCUPIED0001',
            'service_id'            => $service->getKey(),
            'provider_id'           => $providers[0]->getKey(),
            'customer_name'         => 'Already There',
            'customer_email'        => 'there@example.test',
            'customer_timezone'     => 'UTC',
            'start_time'            => bookingStart( '14:00' ),
            'end_time'              => bookingStart( '15:00' ),
            'status'                => BookingStatus::Confirmed->value,
            'assignment_strategy'   => BookingAssignmentStrategy::Customer->value,
            'intake_schema_version' => 1,
            'manage_token_hash'     => str_repeat( 'b', 64 ),
            'created_at'            => now(),
            'updated_at'            => now(),
        ] );

        expect( fn () => bookingService()->reschedule( $first, bookingStart( '14:00' ) ) )
            ->toThrow( SlotUnavailableException::class );

        expect( $first->start_time->equalTo( bookingStart( '10:00' ) ) )->toBeTrue()
            ->and( $first->isDirty() )->toBeFalse();

        // The proof that matters: saving what the caller still holds cannot
        // resurrect the refused time.
        $first->save();

        expect( $first->fresh()->start_time->equalTo( bookingStart( '10:00' ) ) )->toBeTrue();
    } );

    it( 'lets a booking move onto time it was itself occupying', function (): void {
        // The case a naive availability re-check gets wrong: a half-hour shift
        // overlaps the booking's own current position, and reading that as a
        // clash would make every small reschedule impossible.
        [ $service ] = bookableService();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '10:00' ),
        ] ) );

        bookingService()->reschedule( $booking, bookingStart( '10:30' ) );

        expect( $booking->fresh()->start_time->equalTo( bookingStart( '10:30' ) ) )->toBeTrue();
    } );

    it( 'cancels a booking and frees the slot it held', function (): void {
        Event::fake( [ BookingCancelled::class ] );

        [ $service ] = bookableService();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        bookingService()->cancel( $booking, BookingActor::Customer, 'Something came up.' );

        expect( $booking->fresh()->status )->toBe( BookingStatus::Cancelled )
            ->and( $booking->fresh()->occupiesSlot() )->toBeFalse();

        Event::assertDispatched(
            BookingCancelled::class,
            static fn ( BookingCancelled $event ): bool => BookingActor::Customer === $event->actor
                && 'Something came up.' === $event->reason,
        );
    } );

    it( 'lets the freed slot be booked again', function (): void {
        [ $service ] = bookableService( 1 );

        $first = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        bookingService()->cancel( $first, BookingActor::Customer );

        $second = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        expect( $second->id )->not->toBe( $first->id )
            ->and( $second->status )->toBe( BookingStatus::Confirmed );
    } );

    it( 'marks a booking delivered', function (): void {
        Event::fake( [ BookingCompleted::class ] );

        [ $service ] = bookableService();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        bookingService()->complete( $booking, BookingActor::Provider );

        expect( $booking->fresh()->status )->toBe( BookingStatus::Completed );

        Event::assertDispatched( BookingCompleted::class );
    } );

    it( 'marks a booking as a no-show', function (): void {
        Event::fake( [ BookingNoShow::class ] );

        [ $service ] = bookableService();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        bookingService()->markNoShow( $booking, BookingActor::Admin );

        expect( $booking->fresh()->status )->toBe( BookingStatus::NoShow );

        Event::assertDispatched( BookingNoShow::class );
    } );

    it( 'refuses a transition the booking has already made', function (): void {
        // The guarantee every listener downstream is written against: one
        // action per transition. Cancelling a cancelled booking would fire the
        // cancellation twice for one cancellation.
        [ $service ] = bookableService();

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        bookingService()->cancel( $booking, BookingActor::Customer );

        expect( fn () => bookingService()->cancel( $booking, BookingActor::Customer ) )
            ->toThrow( InvalidBookingTransitionException::class );
        expect( fn () => bookingService()->complete( $booking ) )
            ->toThrow( InvalidBookingTransitionException::class );
        expect( fn () => bookingService()->markNoShow( $booking ) )
            ->toThrow( InvalidBookingTransitionException::class );
        expect( fn () => bookingService()->reschedule( $booking, bookingStart( '14:00' ) ) )
            ->toThrow( InvalidBookingTransitionException::class );
        expect( fn () => bookingService()->confirm( $booking ) )
            ->toThrow( InvalidBookingTransitionException::class );
    } );
} );

describe( 'reassign', function (): void {
    it( 'moves a booking to another provider free at the same time', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        $original = $booking->provider_id;

        $reassigned = bookingService()->reassign( $booking );

        $others = array_values( array_filter(
            $providers,
            static fn ( ServiceProvider $provider ): bool => $provider->id !== $original,
        ) );

        expect( $reassigned->provider_id )->not->toBe( $original )
            ->and( $reassigned->provider_id )->toBe( $others[0]->id )
            ->and( $reassigned->assignment_strategy )->toBe( BookingAssignmentStrategy::RoundRobin )
            ->and( $reassigned->start_time->equalTo( bookingStart() ) )->toBeTrue()
            ->and( $booking->fresh()->provider_id )->toBe( $others[0]->id );
    } );

    it( 'announces the reassignment with the provider it left', function (): void {
        Event::fake( [ BookingReassigned::class ] );

        [ $service ] = bookableService( 2 );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        $original = (int) $booking->provider_id;

        $ran = false;

        addAction( 'ap.bookings.reassigned', function ( $reassigned, $previousProviderId ) use ( &$ran, $original ): void {
            $ran = ( $previousProviderId === $original );
        } );

        bookingService()->reassign( $booking, BookingActor::Admin );

        expect( $ran )->toBeTrue();

        Event::assertDispatched(
            BookingReassigned::class,
            static fn ( BookingReassigned $event ): bool => $event->previousProviderId === $original
                && BookingActor::Admin === $event->actor,
        );
    } );

    it( 'fires the availability hooks once for the booking it reassigns', function (): void {
        [ $service ] = bookableService( 2 );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        $available  = 0;
        $roundRobin = 0;

        addFilter( 'ap.bookings.availableProviders', function ( array $providers ) use ( &$available ): array {
            $available++;

            return $providers;
        } );

        addFilter( 'ap.bookings.roundRobin.selectProvider', function ( $selected ) use ( &$roundRobin ) {
            $roundRobin++;

            return $selected;
        } );

        bookingService()->reassign( $booking );

        expect( $available )->toBe( 1 )
            ->and( $roundRobin )->toBe( 1 );
    } );

    it( 'refuses to reassign when no other provider is free', function (): void {
        [ $service ] = bookableService( 1 );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        $original = $booking->provider_id;

        expect( fn () => bookingService()->reassign( $booking ) )
            ->toThrow( SlotUnavailableException::class );

        expect( $booking->fresh()->provider_id )->toBe( $original );
    } );

    it( 'refuses to reassign a booking that no longer holds a slot', function (): void {
        [ $service ] = bookableService( 2 );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart(),
        ] ) );

        bookingService()->cancel( $booking, BookingActor::Admin );

        expect( fn () => bookingService()->reassign( $booking->fresh() ) )
            ->toThrow( InvalidBookingTransitionException::class );
    } );
} );

describe( 'site scoping', function (): void {
    it( 'stamps the booking with the service\'s site, not whichever one is in context', function (): void {
        // The two paths the package supports for crossing sites — a console
        // command looping with SiteContext::forSite(), and a maintenance query
        // using acrossAllSites() — both leave the ambient site disagreeing with
        // the service being booked. A booking stamped from the ambient site
        // would carry this customer's name, email, and phone into another
        // tenant, and hide the appointment from the tenant that owns the service.
        scopeToSite( 7 );

        [ $service ] = bookableService();

        scopeToSite( 9 );

        $from = Service::query()->acrossAllSites()->findOrFail( $service->getKey() );

        $booking = bookingService()->create( bookingCustomer( [
            'service'    => $from,
            'start_time' => bookingStart(),
        ] ) );

        // The returned instance, and the row as any tenant would read it back.
        // Without the pin the booking would land under site 9 — and the
        // provider lookup would not even have found the site-7 provider from
        // site 9, so the create would more likely have thrown first.
        $stored = Booking::query()->acrossAllSites()->findOrFail( $booking->getKey() );

        expect( (int) $booking->getAttribute( 'site_id' ) )->toBe( 7 )
            ->and( (int) $stored->getAttribute( 'site_id' ) )->toBe( 7 );
    } );

    it( 'still refuses a bare service id resolved from another site', function (): void {
        // The service is resolved before the pin, so a bare id from another site
        // remains invisible — the documented limit that pushes cross-site
        // callers to hand over the model they already hold.
        scopeToSite( 7 );

        [ $service ] = bookableService();

        scopeToSite( 9 );

        expect( fn () => bookingService()->create( bookingCustomer( [
            'service_id' => $service->getKey(),
            'start_time' => bookingStart(),
        ] ) ) )->toThrow( InvalidArgumentException::class );
    } );

    it( 'restores whatever site was in context once the booking is placed', function (): void {
        scopeToSite( 7 );

        [ $service ] = bookableService();

        scopeToSite( 9 );

        bookingService()->create( bookingCustomer( [
            'service'    => Service::query()->acrossAllSites()->findOrFail( $service->getKey() ),
            'start_time' => bookingStart(),
        ] ) );

        expect( app( SiteContext::class )->currentSiteId() )->toBe( 9 );
    } );

    it( 'checks a reschedule for clashes against the booking\'s own site', function (): void {
        scopeToSite( 7 );

        [ $service, $providers ] = bookableService();

        bookingService()->create( bookingCustomer( [
            'service'     => $service,
            'provider_id' => $providers[0]->getKey(),
            'start_time'  => bookingStart( '10:00' ),
        ] ) );

        $later = bookingService()->create( bookingCustomer( [
            'service'     => $service,
            'provider_id' => $providers[0]->getKey(),
            'start_time'  => bookingStart( '11:00' ),
        ] ) );

        scopeToSite( 9 );

        // Moving the 11:00 booking onto the 10:00 one must be refused. The clash
        // check has to read the booking's own site (7), not the ambient one (9)
        // — under site 9 it sees an empty diary and double-books the provider.
        expect( fn () => bookingService()->reschedule(
            Booking::query()->acrossAllSites()->findOrFail( $later->getKey() ),
            bookingStart( '10:00' ),
        ) )->toThrow( SlotUnavailableException::class );
    } );
} );
