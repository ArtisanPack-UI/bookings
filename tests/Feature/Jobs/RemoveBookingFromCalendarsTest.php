<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Jobs\RemoveBookingFromCalendars;
use ArtisanPackUI\Bookings\Services\CalendarSyncOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\TestsWithSqlite;

use function Pest\Laravel\mock;

uses( TestsWithSqlite::class, RefreshDatabase::class );

it( 'delegates the removal to the orchestrator', function (): void {
    $job = new RemoveBookingFromCalendars( 7, 11 );

    mock( CalendarSyncOrchestrator::class )
        ->shouldReceive( 'remove' )
        ->once()
        ->with( 7, 11 );

    $job->handle( app( CalendarSyncOrchestrator::class ) );
} );

it( 'records the miss when its retries are spent, without disabling anything', function (): void {
    Log::shouldReceive( 'warning' )
        ->once()
        ->with( 'A booking could not be removed from a connected calendar.', Mockery::type( 'array' ) );

    ( new RemoveBookingFromCalendars( 7, 11 ) )
        ->failed( new RuntimeException( 'Google returned 503.' ) );
} );
