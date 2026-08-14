<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Admin\BlackoutDatesIndex;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceBlackoutDate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'listing blackouts', function (): void {
    it( 'shows the site-wide and per-service closures the site owns', function (): void {
        ServiceBlackoutDate::factory()->siteWide()->create( [ 'reason' => 'Winter shutdown' ] );

        $service = Service::factory()->create( [ 'name' => 'Consultation' ] );
        ServiceBlackoutDate::factory()->for( $service )->create( [ 'reason' => 'Provider on leave' ] );

        Livewire::test( BlackoutDatesIndex::class )
            ->assertSee( 'Winter shutdown' )
            ->assertSee( 'Provider on leave' )
            ->assertSee( 'All services' )
            ->assertSee( 'Consultation' );
    } );

    it( 'filters the list by reason', function (): void {
        ServiceBlackoutDate::factory()->siteWide()->create( [ 'reason' => 'Public holiday' ] );
        ServiceBlackoutDate::factory()->siteWide()->create( [ 'reason' => 'Training day' ] );

        Livewire::test( BlackoutDatesIndex::class )
            ->set( 'search', 'holiday' )
            ->assertSee( 'Public holiday' )
            ->assertDontSee( 'Training day' );
    } );

    it( 'returns to the first page when the search changes', function (): void {
        ServiceBlackoutDate::factory()->siteWide()->count( 20 )->create();

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'gotoPage', 2 )
            ->assertSet( 'paginators.page', 2 )
            ->set( 'search', 'x' )
            ->assertSet( 'paginators.page', 1 );
    } );

    it( 'invites the administrator to add one when the list is empty', function (): void {
        Livewire::test( BlackoutDatesIndex::class )
            ->assertSee( 'No blackout dates yet.' );
    } );

    it( 'says the search matched nothing rather than that none exist', function (): void {
        ServiceBlackoutDate::factory()->siteWide()->create( [ 'reason' => 'Public holiday' ] );

        Livewire::test( BlackoutDatesIndex::class )
            ->set( 'search', 'no-such-reason' )
            ->assertSee( 'No blackout dates match that reason.' )
            ->assertDontSee( 'No blackout dates yet.' );
    } );
} );

describe( 'creating a blackout', function (): void {
    it( 'opens a blank form', function (): void {
        Livewire::test( BlackoutDatesIndex::class )
            ->assertSet( 'showForm', false )
            ->call( 'create' )
            ->assertSet( 'showForm', true )
            ->assertSet( 'editingId', null )
            ->assertSet( 'serviceId', null );
    } );

    it( 'saves a site-wide closure with no service named', function (): void {
        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'create' )
            ->set( 'startsOn', '2026-12-24' )
            ->set( 'endsOn', '2026-12-26' )
            ->set( 'reason', 'Christmas' )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->assertDispatched( 'bookings-blackout-saved' )
            ->assertSet( 'showForm', false );

        $blackout = ServiceBlackoutDate::query()->firstOrFail();

        expect( $blackout->service_id )->toBeNull()
            ->and( $blackout->isSiteWide() )->toBeTrue()
            ->and( $blackout->reason )->toBe( 'Christmas' )
            ->and( $blackout->starts_on->toDateString() )->toBe( '2026-12-24' )
            ->and( $blackout->ends_on->toDateString() )->toBe( '2026-12-26' );
    } );

    it( 'saves a per-service closure pinned to a service', function (): void {
        $service = Service::factory()->create();

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'create' )
            ->set( 'serviceId', $service->id )
            ->set( 'startsOn', '2026-06-01' )
            ->set( 'endsOn', '2026-06-07' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( ServiceBlackoutDate::query()->firstOrFail()->service_id )->toBe( $service->id );
    } );

    it( 'turns a per-service closure back into a site-wide one', function (): void {
        // The "All services" option submits an empty value, which the nullable
        // int property casts to null. Editing a pinned blackout down to site-wide
        // is the path that exercises that empty-string-to-null cast — the reason
        // the property is a nullable int rather than a plain one.
        $service  = Service::factory()->create();
        $blackout = ServiceBlackoutDate::factory()->for( $service )->create();

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'edit', $blackout->id )
            ->assertSet( 'serviceId', $service->id )
            ->set( 'serviceId', '' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( $blackout->refresh()->service_id )->toBeNull()
            ->and( $blackout->isSiteWide() )->toBeTrue();
    } );

    it( 'stores a blank reason as null rather than an empty string', function (): void {
        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'create' )
            ->set( 'startsOn', '2026-06-01' )
            ->set( 'endsOn', '2026-06-01' )
            ->set( 'reason', '   ' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( ServiceBlackoutDate::query()->firstOrFail()->reason )->toBeNull();
    } );
} );

describe( 'validating the form', function (): void {
    it( 'requires both dates', function (): void {
        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'create' )
            ->call( 'save' )
            ->assertHasErrors( [ 'startsOn' => 'required', 'endsOn' => 'required' ] );
    } );

    it( 'rejects an end date before the start date', function (): void {
        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'create' )
            ->set( 'startsOn', '2026-12-26' )
            ->set( 'endsOn', '2026-12-24' )
            ->call( 'save' )
            ->assertHasErrors( [ 'endsOn' => 'after_or_equal' ] );
    } );

    it( 'allows a one-day closure that opens and closes on the same day', function (): void {
        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'create' )
            ->set( 'startsOn', '2026-12-25' )
            ->set( 'endsOn', '2026-12-25' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( ServiceBlackoutDate::query()->count() )->toBe( 1 );
    } );

    it( 'rejects a service the site does not own', function (): void {
        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'create' )
            ->set( 'serviceId', 999999 )
            ->set( 'startsOn', '2026-06-01' )
            ->set( 'endsOn', '2026-06-01' )
            ->call( 'save' )
            ->assertHasErrors( [ 'serviceId' ] );

        expect( ServiceBlackoutDate::query()->count() )->toBe( 0 );
    } );

    it( 'accepts a service with a populated site when no site is in context', function (): void {
        // With no site in context the scope is inert and every service is
        // visible, so the dropdown offers this site-5 service. The exists rule
        // has to stay unconstrained to match — pinning it to `site_id IS NULL`
        // would reject the very service it just offered.
        scopeToSite( 5 );
        $service = Service::factory()->create();

        scopeToSite( null );

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'create' )
            ->set( 'serviceId', $service->id )
            ->set( 'startsOn', '2026-06-01' )
            ->set( 'endsOn', '2026-06-01' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( ServiceBlackoutDate::query()->firstOrFail()->service_id )->toBe( $service->id );
    } );
} );

describe( 'editing a blackout', function (): void {
    it( 'loads an existing blackout into the form', function (): void {
        $service  = Service::factory()->create();
        $blackout = ServiceBlackoutDate::factory()->for( $service )->create( [
            'starts_on' => '2026-07-01',
            'ends_on'   => '2026-07-05',
            'reason'    => 'Summer break',
        ] );

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'edit', $blackout->id )
            ->assertSet( 'editingId', $blackout->id )
            ->assertSet( 'serviceId', $service->id )
            ->assertSet( 'startsOn', '2026-07-01' )
            ->assertSet( 'endsOn', '2026-07-05' )
            ->assertSet( 'reason', 'Summer break' )
            ->assertSet( 'showForm', true );
    } );

    it( 'updates the blackout in place rather than creating a second one', function (): void {
        $blackout = ServiceBlackoutDate::factory()->siteWide()->create( [ 'reason' => 'Old reason' ] );

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'edit', $blackout->id )
            ->set( 'reason', 'New reason' )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->assertDispatched( 'bookings-blackout-saved', blackoutId: $blackout->id );

        expect( ServiceBlackoutDate::query()->count() )->toBe( 1 )
            ->and( $blackout->refresh()->reason )->toBe( 'New reason' );
    } );

    it( 'keeps the pinned service when it was retired after the blackout was made', function (): void {
        // A service can be soft-deleted while a blackout still points at it. The
        // dropdown leaves retired services out, so the option is not offered — but
        // the deferred wire:model keeps the id on the property, and the exists
        // rule matches the still-present row, so re-saving must not drop the pin.
        $service  = Service::factory()->create();
        $blackout = ServiceBlackoutDate::factory()->for( $service )->create();

        $service->delete();

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'edit', $blackout->id )
            ->assertSet( 'serviceId', $service->id )
            ->set( 'reason', 'Still closed' )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( $blackout->refresh()->service_id )->toBe( $service->id )
            ->and( $blackout->reason )->toBe( 'Still closed' );
    } );

    it( 'closes the form without saving on cancel', function (): void {
        $blackout = ServiceBlackoutDate::factory()->siteWide()->create( [ 'reason' => 'Untouched' ] );

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'edit', $blackout->id )
            ->set( 'reason', 'Discarded' )
            ->call( 'cancel' )
            ->assertSet( 'showForm', false )
            ->assertSet( 'editingId', null );

        expect( $blackout->refresh()->reason )->toBe( 'Untouched' );
    } );
} );

describe( 'tenant isolation', function (): void {
    it( 'cannot edit a blackout belonging to another site', function (): void {
        scopeToSite( 2 );
        $theirs = ServiceBlackoutDate::factory()->siteWide()->create();

        scopeToSite( 1 );

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'edit', $theirs->id );
    } )->throws( ModelNotFoundException::class );

    it( 'cannot delete a blackout belonging to another site', function (): void {
        scopeToSite( 2 );
        $theirs = ServiceBlackoutDate::factory()->siteWide()->create();

        scopeToSite( 1 );

        try {
            Livewire::test( BlackoutDatesIndex::class )
                ->call( 'delete', $theirs->id );

            $this->fail( 'The cross-site delete was not refused.' );
        } catch ( ModelNotFoundException ) {
            // The site scope hid the row, so findOrFail threw rather than
            // deleting it — which is exactly the isolation being asserted.
        }

        scopeToSite( 2 );

        expect( ServiceBlackoutDate::query()->find( $theirs->id ) )->not->toBeNull();
    } );

    it( 'cannot pin a blackout to a service owned by another site', function (): void {
        scopeToSite( 2 );
        $theirService = Service::factory()->create();

        scopeToSite( 1 );

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'create' )
            ->set( 'serviceId', $theirService->id )
            ->set( 'startsOn', '2026-06-01' )
            ->set( 'endsOn', '2026-06-01' )
            ->call( 'save' )
            ->assertHasErrors( [ 'serviceId' ] );

        expect( ServiceBlackoutDate::query()->count() )->toBe( 0 );
    } );
} );

describe( 'deleting a blackout', function (): void {
    it( 'removes the blackout outright', function (): void {
        $blackout = ServiceBlackoutDate::factory()->siteWide()->create();

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'delete', $blackout->id )
            ->assertDispatched( 'bookings-blackout-deleted', blackoutId: $blackout->id );

        expect( ServiceBlackoutDate::query()->find( $blackout->id ) )->toBeNull();
    } );

    it( 'closes the form when the blackout being edited is the one deleted', function (): void {
        $blackout = ServiceBlackoutDate::factory()->siteWide()->create();

        Livewire::test( BlackoutDatesIndex::class )
            ->call( 'edit', $blackout->id )
            ->assertSet( 'showForm', true )
            ->call( 'delete', $blackout->id )
            ->assertSet( 'showForm', false )
            ->assertSet( 'editingId', null );
    } );
} );
