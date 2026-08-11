<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Public\BookingWidget;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // The Monday the shared booking helpers work against, far enough ahead of
    // the window that nothing is clipped.
    $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );
} );

describe( 'choosing a service', function (): void {
    it( 'opens on the service list when there is a choice to make', function (): void {
        Service::factory()->create( [ 'name' => 'Discovery Call' ] );
        Service::factory()->create( [ 'name' => 'Strategy Session' ] );

        Livewire::test( BookingWidget::class )
            ->assertSet( 'serviceSlug', '' )
            ->assertSee( 'Discovery Call' )
            ->assertSee( 'Strategy Session' );
    } );

    it( 'skips the list when the site offers exactly one service', function (): void {
        $service = Service::factory()->create();

        Livewire::test( BookingWidget::class )
            ->assertSet( 'serviceSlug', $service->slug );
    } );

    it( 'leaves an inactive service off the list', function (): void {
        Service::factory()->create( [ 'name' => 'Discovery Call' ] );
        Service::factory()->create( [ 'name' => 'Strategy Session' ] );
        Service::factory()->inactive()->create( [ 'name' => 'Retired Service' ] );

        Livewire::test( BookingWidget::class )
            ->assertSee( 'Discovery Call' )
            ->assertDontSee( 'Retired Service' );
    } );

    it( 'books only the service the embed pinned it to', function (): void {
        $pinned = Service::factory()->create( [ 'name' => 'Discovery Call' ] );
        Service::factory()->create( [ 'name' => 'Strategy Session' ] );

        Livewire::test( BookingWidget::class, [ 'service' => $pinned->slug ] )
            ->assertSet( 'serviceSlug', $pinned->slug )
            ->call( 'chooseService', 'anything-else' )
            ->assertSet( 'serviceSlug', $pinned->slug );
    } );
} );

describe( 'choosing a provider', function (): void {
    it( 'asks who, when the service is offered by more than one person', function (): void {
        [ $service, $providers ] = bookableService( 2 );

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->assertSee( $providers[0]->name )
            ->assertSee( $providers[1]->name )
            ->assertSee( 'No preference' );
    } );

    it( 'does not ask when there is only one person to ask about', function (): void {
        [ $service, $providers ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->assertDontSee( 'No preference' )
            ->assertSee( 'Available days' )
            ->assertDontSee( $providers[0]->name );
    } );

    it( 'forgets the day and the slot when the provider changes', function (): void {
        [ $service ] = bookableService( 2 );

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseProvider', 'any' )
            ->call( 'chooseDate', '2026-06-01' )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->assertSet( 'date', '2026-06-01' )
            ->call( 'chooseProvider', 'any' )
            ->assertSet( 'date', '' )
            ->assertSet( 'slotStart', '' );
    } );
} );

describe( 'choosing a time', function (): void {
    it( 'offers the days the provider actually works', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->set( 'month', '2026-06' )
            ->assertSee( 'Mon 1' )
            ->assertDontSee( 'Tue 2' );
    } );

    it( 'states the times in the browser\'s zone once the browser has said', function (): void {
        [ $service ] = bookableService();

        // 10:00 in Chicago is 16:00 in Berlin on that date.
        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->set( 'timezone', 'Europe/Berlin' )
            ->set( 'month', '2026-06' )
            ->call( 'chooseDate', '2026-06-01' )
            ->assertSee( 'Europe/Berlin' )
            ->assertSee( '4:00 pm' );
    } );

    it( 'falls back to the service\'s zone until the browser says otherwise', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->set( 'month', '2026-06' )
            ->call( 'chooseDate', '2026-06-01' )
            ->assertSee( 'America/Chicago' )
            ->assertSee( '10:00 am' );
    } );

    it( 'refuses a timezone the machine has never heard of', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->set( 'timezone', 'Moon/Tranquility' )
            ->assertSet( 'timezone', '' );
    } );

    it( 'moves the calendar a month at a time', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->set( 'month', '2026-06' )
            ->call( 'shiftMonth', 1 )
            ->assertSet( 'month', '2026-07' )
            ->call( 'shiftMonth', -2 )
            ->assertSet( 'month', '2026-05' );
    } );

    it( 'clamps a month shift a client sent from outside the template', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->set( 'month', '2026-06' )
            ->call( 'shiftMonth', PHP_INT_MAX )
            ->assertSet( 'month', '2027-06' )
            ->call( 'shiftMonth', PHP_INT_MIN )
            ->assertSet( 'month', '2026-06' );
    } );
} );

describe( 'input nothing else would survive', function (): void {
    it( 'renders rather than raises when the month is not a month', function (): void {
        [ $service ] = bookableService();

        Livewire::withQueryParams( [ 'bookingService' => $service->slug, 'bookingMonth' => 'not-a-month' ] )
            ->test( BookingWidget::class )
            ->assertSet( 'month', '' )
            ->assertSee( 'Available days' );
    } );

    it( 'renders rather than raises when the day is not a day', function (): void {
        [ $service ] = bookableService();

        Livewire::withQueryParams( [ 'bookingService' => $service->slug, 'bookingDate' => '../../etc/passwd' ] )
            ->test( BookingWidget::class )
            ->assertSet( 'date', '' )
            ->assertSee( 'Available days' );
    } );

    it( 'renders rather than raises when the slot is not an instant', function (): void {
        [ $service ] = bookableService();

        Livewire::withQueryParams( [ 'bookingService' => $service->slug, 'bookingSlot' => 'whenever' ] )
            ->test( BookingWidget::class )
            ->assertSet( 'slotStart', '' )
            ->assertSee( 'Available days' )
            ->assertDontSee( 'Confirm your details' );
    } );

    it( 'does not put a service slug inside a Livewire expression unescaped', function (): void {
        Service::factory()->create( [ 'name' => 'One', 'slug' => "quote'-and-\"double" ] );
        Service::factory()->create( [ 'name' => 'Two' ] );

        $html = Livewire::test( BookingWidget::class )->html();

        // The slug is an administrator's to set, and a `wire:click` expression
        // Livewire cannot parse as a method call is handed to Alpine's
        // evaluator — so an unescaped quote there is script execution on every
        // page the widget is embedded on.
        expect( $html )->not->toContain( "chooseService('quote'" )
            ->and( $html )->toContain( "chooseService('quote\\u0027-and-\\u0022double')" );
    } );

    it( 'keeps a pinned service pinned when the customer steps back', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->call( 'back' )
            ->assertSet( 'slotStart', '' )
            ->assertSet( 'serviceSlug', $service->slug )
            ->call( 'back' )
            ->assertSet( 'serviceSlug', $service->slug );
    } );
} );

describe( 'booking', function (): void {
    it( 'creates the booking and confirms it to the customer', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->set( 'month', '2026-06' )
            ->call( 'chooseDate', '2026-06-01' )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'book' )
            ->assertHasNoErrors()
            ->assertSee( 'Your appointment is booked' );

        $booking = Booking::query()->sole();

        expect( $booking->customer_name )->toBe( 'Sam Rivera' )
            ->and( $booking->customer_email )->toBe( 'sam@example.test' )
            ->and( $booking->start_time->toIso8601String() )->toBe( bookingStart()->toIso8601String() )
            ->and( $booking->customer_timezone )->toBe( 'America/Chicago' );
    } );

    it( 'stores the browser\'s zone on the booking', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->set( 'timezone', 'Europe/Berlin' )
            ->set( 'month', '2026-06' )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'book' )
            ->assertHasNoErrors();

        expect( Booking::query()->sole()->customer_timezone )->toBe( 'Europe/Berlin' );
    } );

    it( 'refuses a submission with no name or email', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->call( 'book' )
            ->assertHasErrors( [ 'customerName' => 'required', 'customerEmail' => 'required' ] );

        expect( Booking::query()->count() )->toBe( 0 );
    } );

    it( 'refuses a name that sanitizes away to nothing', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->set( 'customerName', '<b></b>' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'book' )
            ->assertHasErrors( [ 'customerName' => 'required' ] );
    } );

    it( 'sends the customer back to the slot list when the time has gone', function (): void {
        [ $service, $providers ] = bookableService();

        $taken = bookingStart();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', $taken->toIso8601String() )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->tap( static function () use ( $service, $providers, $taken ): void {
                // Booked out from under the widget between the customer picking
                // the time and confirming it.
                Booking::factory()
                    ->for( $service, 'service' )
                    ->for( $providers[0], 'provider' )
                    ->create( [
                        'start_time' => $taken,
                        'end_time'   => $taken->addMinutes( 60 ),
                    ] );
            } )
            ->call( 'book' )
            ->assertHasErrors( 'slotStart' )
            ->assertSet( 'slotStart', '' )
            // The same render, not the next one: a message telling somebody to
            // choose another time is no use above the form they just submitted.
            ->assertSee( 'Available days' )
            ->assertDontSee( 'Confirm your details' );
    } );

    it( 'refuses a time nobody is free at, however it was submitted', function (): void {
        [ $service ] = bookableService();

        // A Sunday: nobody works one.
        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', CarbonImmutable::parse( '2026-06-07 10:00', 'America/Chicago' )->utc()->toIso8601String() )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'book' )
            ->assertHasErrors( 'slotStart' );

        expect( Booking::query()->count() )->toBe( 0 );
    } );

    it( 'spends the same rate limit bucket the API endpoint does', function (): void {
        config()->set( 'artisanpack.bookings.public.rate_limits.post', 1 );

        [ $service ] = bookableService();

        $widget = Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'book' )
            ->assertHasNoErrors();

        $widget->call( 'bookAnother' )
            ->call( 'chooseSlot', bookingStart( '11:00' )->toIso8601String() )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'book' )
            ->assertHasErrors( 'slotStart' );

        expect( Booking::query()->count() )->toBe( 1 );
    } );

    it( 'clears the form for a second booking', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'book' )
            ->call( 'bookAnother' )
            ->assertSet( 'confirmation', null )
            ->assertSet( 'customerName', '' )
            ->assertSet( 'customerEmail', '' )
            ->assertSet( 'slotStart', '' )
            // Pinned, so the widget stays on the service it was embedded for.
            ->assertSet( 'serviceSlug', $service->slug );
    } );
} );

describe( 'intake answers', function (): void {
    it( 'asks the questions the service\'s current schema names', function (): void {
        [ $service ] = bookableService();

        $service->forceFill( [
            'intake_schema' => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'textarea', 'label' => 'What would you like to cover?', 'required' => true ],
                ],
            ],
        ] )->save();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->assertSee( 'What would you like to cover?' );
    } );

    it( 'reports a missing required answer against its own field', function (): void {
        [ $service ] = bookableService();

        $service->forceFill( [
            'intake_schema' => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'text', 'label' => 'Your goal', 'required' => true ],
                ],
            ],
        ] )->save();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'book' )
            ->assertHasErrors( 'intake.goal' );

        expect( Booking::query()->count() )->toBe( 0 );
    } );

    it( 'stores the answers it was given', function (): void {
        [ $service ] = bookableService();

        $service->forceFill( [
            'intake_schema' => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'text', 'label' => 'Your goal', 'required' => true ],
                ],
            ],
        ] )->save();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->set( 'intake.goal', '  Grow the business  ' )
            ->call( 'book' )
            ->assertHasNoErrors();

        expect( Booking::query()->sole()->intake_data )->toBe( [ 'goal' => 'Grow the business' ] );
    } );
} );

describe( 'without JavaScript', function (): void {
    it( 'reads the whole flow back off the query string', function (): void {
        [ $service ] = bookableService();

        $start = bookingStart();

        Livewire::withQueryParams( [
            'bookingService' => $service->slug,
            'bookingDate'    => '2026-06-01',
            'bookingSlot'    => $start->toIso8601String(),
        ] )
            ->test( BookingWidget::class )
            ->assertSet( 'serviceSlug', $service->slug )
            ->assertSet( 'date', '2026-06-01' )
            ->assertSet( 'slotStart', $start->format( 'Y-m-d\TH:i:s\Z' ) )
            ->assertSee( 'Confirm your details' );
    } );

    it( 'names slots without a plus, so a query string survives decoding', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->set( 'month', '2026-06' )
            ->call( 'chooseDate', '2026-06-01' )
            ->assertSeeHtml( 'name="bookingSlot"' )
            ->assertSeeHtml( 'value="' . bookingStart()->format( 'Y-m-d\TH:i:s\Z' ) . '"' )
            ->assertDontSeeHtml( 'value="' . bookingStart()->toIso8601String() . '"' );
    } );

    it( 'still reads a slot whose plus decoded to a space on the way in', function (): void {
        [ $service ] = bookableService();

        // What a `+00:00` offset becomes after a round trip through a query
        // string — from a link somebody pasted, or a page from before slots
        // were named with a `Z`.
        Livewire::withQueryParams( [
            'bookingService' => $service->slug,
            'bookingSlot'    => str_replace( '+', ' ', bookingStart()->toIso8601String() ),
        ] )
            ->test( BookingWidget::class )
            ->assertSee( 'Confirm your details' )
            ->set( 'customerName', 'Sam Rivera' )
            ->set( 'customerEmail', 'sam@example.test' )
            ->call( 'book' )
            ->assertHasNoErrors();

        expect( Booking::query()->sole()->start_time->toIso8601String() )
            ->toBe( bookingStart()->toIso8601String() );
    } );

    it( 'renders the form as a plain post to the widget route', function (): void {
        [ $service ] = bookableService();

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->assertSeeHtml( 'action="' . route( 'artisanpack.bookings.widget.store' ) . '"' )
            ->assertSeeHtml( 'name="customer_email"' )
            ->assertSeeHtml( 'name="start_time"' );
    } );

    it( 'shows a booking the plain form made', function (): void {
        [ $service ] = bookableService();

        $booking = Booking::factory()->for( $service, 'service' )->create();

        session()->put( BookingWidget::CONFIRMATION_KEY, BookingWidget::confirmationFor( $booking, 'America/Chicago' ) );

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->assertSee( 'Your appointment is booked' )
            ->assertSee( (string) $booking->booking_number );
    } );

    it( 'shows a refusal the plain form was given, under this form\'s field names', function (): void {
        [ $service ] = bookableService();

        session()->put( 'errors', ( new Illuminate\Support\ViewErrorBag() )->put(
            'default',
            new Illuminate\Support\MessageBag( [ 'customer_email' => [ 'That email address is not valid.' ] ] ),
        ) );

        Livewire::test( BookingWidget::class, [ 'service' => $service->slug ] )
            ->call( 'chooseSlot', bookingStart()->toIso8601String() )
            ->assertHasErrors( 'customerEmail' )
            ->assertSee( 'That email address is not valid.' );
    } );
} );
