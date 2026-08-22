<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Jobs\SyncBookingToCalendars;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Services\CalendarSyncOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

use function Pest\Laravel\mock;

uses( TestsWithSqlite::class, RefreshDatabase::class );

it( 'delegates the push to the orchestrator', function (): void {
    $job = new SyncBookingToCalendars( 7, 11 );

    mock( CalendarSyncOrchestrator::class )
        ->shouldReceive( 'push' )
        ->once()
        ->with( 7, 11 );

    $job->handle( app( CalendarSyncOrchestrator::class ) );
} );

it( 'counts the failure against the connection once its retries are spent', function (): void {
    $connection = CalendarConnection::factory()->google()->failing( 4 )->create();
    Booking::factory()->for( $connection->provider, 'provider' )->create();

    ( new SyncBookingToCalendars( 7, $connection->getKey() ) )
        ->failed( new RuntimeException( 'Google returned 503.' ) );

    $connection->refresh();

    expect( $connection->isDisabled() )->toBeTrue();
} );
