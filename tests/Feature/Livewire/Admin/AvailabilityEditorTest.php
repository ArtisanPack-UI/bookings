<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\AvailabilityOverrideType;
use ArtisanPackUI\Bookings\Livewire\Admin\AvailabilityEditor;
use ArtisanPackUI\Bookings\Models\AvailabilityOverride;
use ArtisanPackUI\Bookings\Models\AvailabilitySchedule;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'loading availability', function (): void {
    it( 'loads the provider and their current availability', function (): void {
        $provider = ServiceProvider::factory()->create( [
            'name'     => 'Ada Lovelace',
            'timezone' => 'America/Chicago',
        ] );

        AvailabilitySchedule::factory()->for( $provider, 'provider' )->nineToFive( 1 )->create();
        AvailabilityOverride::factory()->for( $provider, 'provider' )->unavailable()->onDate( '2026-12-24' )->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->assertSet( 'providerName', 'Ada Lovelace' )
            ->assertSet( 'timezone', 'America/Chicago' )
            ->assertSet( 'schedules.0.day_of_week', 1 )
            ->assertSet( 'schedules.0.start_time_local', '09:00' )
            ->assertSet( 'overrides.0.date', '2026-12-24' );
    } );

    it( 'refuses a provider it cannot find', function (): void {
        Livewire::test( AvailabilityEditor::class, [ 'providerId' => 999999 ] );
    } )->throws( ModelNotFoundException::class );
} );

describe( 'editing the rows', function (): void {
    it( 'adds and removes a weekly window', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->assertSet( 'schedules', [] )
            ->call( 'addSchedule' )
            ->assertSet( 'schedules.0.day_of_week', 1 )
            ->call( 'removeSchedule', 0 )
            ->assertSet( 'schedules', [] );
    } );

    it( 'adds and removes a date exception', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->assertSet( 'overrides', [] )
            ->call( 'addOverride' )
            ->assertSet( 'overrides.0.type', AvailabilityOverrideType::Unavailable->value )
            ->call( 'removeOverride', 0 )
            ->assertSet( 'overrides', [] );
    } );
} );

describe( 'saving availability', function (): void {
    it( 'replaces the provider\'s weekly hours with the form', function (): void {
        $provider = ServiceProvider::factory()->create();
        AvailabilitySchedule::factory()->for( $provider, 'provider' )->nineToFive( 3 )->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->set( 'schedules', [
                [
                    'day_of_week'      => 1,
                    'start_time_local' => '08:00',
                    'end_time_local'   => '12:00',
                    'effective_from'   => '',
                    'effective_until'  => '',
                    'is_available'     => true,
                ],
            ] )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->assertDispatched( 'bookings-provider-availability-saved', providerId: $provider->id );

        $schedules = $provider->availabilitySchedules()->get();

        expect( $schedules )->toHaveCount( 1 )
            ->and( $schedules->first()->day_of_week )->toBe( 1 )
            ->and( $schedules->first()->start_time_local )->toBe( '08:00:00' )
            ->and( $schedules->first()->end_time_local )->toBe( '12:00:00' );
    } );

    it( 'stores an unavailable exception without times', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->set( 'overrides', [
                [
                    'date'             => '2026-12-24',
                    'type'             => AvailabilityOverrideType::Unavailable->value,
                    'start_time_local' => '10:00',
                    'end_time_local'   => '14:00',
                    'reason'           => 'Christmas Eve',
                ],
            ] )
            ->call( 'save' )
            ->assertHasNoErrors();

        $override = $provider->availabilityOverrides()->first();

        expect( $override->isUnavailable() )->toBeTrue()
            ->and( $override->start_time_local )->toBeNull()
            ->and( $override->end_time_local )->toBeNull()
            ->and( $override->reason )->toBe( 'Christmas Eve' );
    } );

    it( 'stores a custom-hours exception with its window', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->set( 'overrides', [
                [
                    'date'             => '2026-12-31',
                    'type'             => AvailabilityOverrideType::CustomHours->value,
                    'start_time_local' => '10:00',
                    'end_time_local'   => '14:00',
                    'reason'           => '',
                ],
            ] )
            ->call( 'save' )
            ->assertHasNoErrors();

        $override = $provider->availabilityOverrides()->first();

        expect( $override->isCustomHours() )->toBeTrue()
            ->and( $override->start_time_local )->toBe( '10:00:00' )
            ->and( $override->end_time_local )->toBe( '14:00:00' );
    } );

    it( 'saves an empty form as no availability', function (): void {
        $provider = ServiceProvider::factory()->create();
        AvailabilitySchedule::factory()->for( $provider, 'provider' )->nineToFive( 1 )->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->set( 'schedules', [] )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( $provider->availabilitySchedules()->count() )->toBe( 0 );
    } );
} );

describe( 'validating availability', function (): void {
    it( 'rejects a window that ends before it starts', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->set( 'schedules', [
                [
                    'day_of_week'      => 1,
                    'start_time_local' => '17:00',
                    'end_time_local'   => '09:00',
                    'effective_from'   => '',
                    'effective_until'  => '',
                    'is_available'     => true,
                ],
            ] )
            ->call( 'save' )
            ->assertHasErrors( [ 'schedules.0.end_time_local' ] );
    } );

    it( 'rejects an effective window that ends before it starts', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->set( 'schedules', [
                [
                    'day_of_week'      => 1,
                    'start_time_local' => '09:00',
                    'end_time_local'   => '17:00',
                    'effective_from'   => '2026-09-01',
                    'effective_until'  => '2026-08-01',
                    'is_available'     => true,
                ],
            ] )
            ->call( 'save' )
            ->assertHasErrors( [ 'schedules.0.effective_until' ] );
    } );

    it( 'rejects a custom-hours window that ends before it starts', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->set( 'overrides', [
                [
                    'date'             => '2026-12-31',
                    'type'             => AvailabilityOverrideType::CustomHours->value,
                    'start_time_local' => '16:00',
                    'end_time_local'   => '10:00',
                    'reason'           => '',
                ],
            ] )
            ->call( 'save' )
            ->assertHasErrors( [ 'overrides.0.end_time_local' ] );
    } );

    it( 'requires a window on a custom-hours exception', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->set( 'overrides', [
                [
                    'date'             => '2026-12-31',
                    'type'             => AvailabilityOverrideType::CustomHours->value,
                    'start_time_local' => '',
                    'end_time_local'   => '',
                    'reason'           => '',
                ],
            ] )
            ->call( 'save' )
            ->assertHasErrors( [ 'overrides.0.start_time_local', 'overrides.0.end_time_local' ] );
    } );

    it( 'requires a date on an exception', function (): void {
        $provider = ServiceProvider::factory()->create();

        Livewire::test( AvailabilityEditor::class, [ 'providerId' => $provider->id ] )
            ->set( 'overrides', [
                [
                    'date'             => '',
                    'type'             => AvailabilityOverrideType::Unavailable->value,
                    'start_time_local' => '',
                    'end_time_local'   => '',
                    'reason'           => '',
                ],
            ] )
            ->call( 'save' )
            ->assertHasErrors( [ 'overrides.0.date' ] );
    } );
} );
