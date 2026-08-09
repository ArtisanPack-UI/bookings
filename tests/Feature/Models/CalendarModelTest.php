<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Enums\CalendarSyncMode;
use ArtisanPackUI\Bookings\Events\CalendarConnectionDisabled;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarBusyBlock;
use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\CalendarEvent;
use ArtisanPackUI\Bookings\Models\CalendarWatchChannel;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'the calendar connection factory', function (): void {
    it( 'produces every vendor variant', function (): void {
        expect( CalendarConnection::factory()->google()->create()->driver )->toBe( CalendarDriver::Google )
            ->and( CalendarConnection::factory()->microsoft()->create()->driver )->toBe( CalendarDriver::Microsoft )
            ->and( CalendarConnection::factory()->apple()->create()->driver )->toBe( CalendarDriver::Apple )
            ->and( CalendarConnection::factory()->ical()->create()->driver )->toBe( CalendarDriver::Ical );
    } );

    it( 'defaults to outbound sync', function (): void {
        // Two-way hands an external calendar the power to suppress availability,
        // so it is opted into rather than arrived at.
        $connection = CalendarConnection::factory()->create();

        expect( $connection->sync_mode )->toBe( CalendarSyncMode::Outbound )
            ->and( $connection->readsBusyBlocks() )->toBeFalse()
            ->and( $connection->sync_mode->writesEvents() )->toBeTrue();
    } );

    it( 'builds the two-way and off modes', function (): void {
        expect( CalendarConnection::factory()->twoWay()->create()->readsBusyBlocks() )->toBeTrue()
            ->and( CalendarConnection::factory()->syncOff()->create()->sync_mode->writesEvents() )->toBeFalse();
    } );

    it( 'knows which drivers can register a push channel', function (): void {
        expect( CalendarDriver::Google->supportsWatchChannels() )->toBeTrue()
            ->and( CalendarDriver::Microsoft->supportsWatchChannels() )->toBeTrue()
            ->and( CalendarDriver::Apple->supportsWatchChannels() )->toBeFalse()
            ->and( CalendarDriver::Ical->supportsWatchChannels() )->toBeFalse();
    } );

    it( 'refuses to connect the same calendar to the same provider twice', function (): void {
        // Outbound sync would then write every booking to it twice.
        $provider = ServiceProvider::factory()->create();

        CalendarConnection::factory()->for( $provider, 'provider' )->create( [
            'driver'               => CalendarDriver::Google,
            'external_calendar_id' => 'dana@example.test',
        ] );

        expect( fn () => CalendarConnection::factory()->for( $provider, 'provider' )->create( [
            'driver'               => CalendarDriver::Google,
            'external_calendar_id' => 'dana@example.test',
        ] ) )->toThrow( QueryException::class );
    } );

    it( 'keeps the sync token out of serialised output', function (): void {
        $connection = CalendarConnection::factory()->create();

        expect( $connection->toArray() )->not->toHaveKey( 'sync_token' )
            ->and( $connection->sync_token )->not->toBeEmpty();
    } );
} );

describe( 'disabling a connection', function (): void {
    it( 'stamps the row, drops the sync cursor, and announces it', function (): void {
        Event::fake( [ CalendarConnectionDisabled::class ] );

        $connection = CalendarConnection::factory()->failing()->create();

        expect( $connection->disable( 'Five consecutive failures.' ) )->toBeTrue();

        $connection->refresh();

        expect( $connection->disabled_at )->not->toBeNull()
            ->and( $connection->is_active )->toBeFalse()
            ->and( $connection->sync_token )->toBeNull()
            ->and( $connection->last_sync_error )->toBe( 'Five consecutive failures.' )
            ->and( $connection->isDisabled() )->toBeTrue();

        Event::assertDispatched(
            CalendarConnectionDisabled::class,
            fn ( CalendarConnectionDisabled $event ): bool => $event->connection->is( $connection )
                && 'Five consecutive failures.' === $event->reason,
        );
    } );

    it( 'says nothing the second time', function (): void {
        // A failure sweep that runs twice should not tell the operator about the
        // same outage twice.
        Event::fake( [ CalendarConnectionDisabled::class ] );

        $connection = CalendarConnection::factory()->create();
        $connection->disable( 'First.' );

        expect( $connection->disable( 'Second.' ) )->toBeFalse();

        Event::assertDispatchedTimes( CalendarConnectionDisabled::class, 1 );
    } );

    it( 'stops a disabled connection reading busy blocks', function (): void {
        $connection = CalendarConnection::factory()->twoWay()->create();

        expect( $connection->readsBusyBlocks() )->toBeTrue();

        $connection->disable( 'Token revoked.' );

        expect( $connection->readsBusyBlocks() )->toBeFalse();
    } );

    it( 'scopes disabled connections out of the sync sweep', function (): void {
        CalendarConnection::factory()->twoWay()->create();
        CalendarConnection::factory()->create();
        CalendarConnection::factory()->disabled()->create();
        CalendarConnection::factory()->twoWay()->create()->disable( 'Gone.' );

        expect( CalendarConnection::active()->count() )->toBe( 2 )
            ->and( CalendarConnection::twoWay()->count() )->toBe( 1 );
    } );
} );

describe( 'the calendar event ledger', function (): void {
    it( 'ties a booking to the event it became', function (): void {
        $event = CalendarEvent::factory()->create();

        expect( $event->booking )->toBeInstanceOf( Booking::class )
            ->and( $event->connection )->toBeInstanceOf( CalendarConnection::class )
            ->and( $event->hasSyncError() )->toBeFalse()
            ->and( $event->booking->calendarEvents()->count() )->toBe( 1 );
    } );

    it( 'records a failed push', function (): void {
        $event = CalendarEvent::factory()->failing()->create();

        expect( $event->hasSyncError() )->toBeTrue();
    } );

    it( 'refuses a second event for the same booking on the same calendar', function (): void {
        // A retried sync job has to update the row it wrote last time rather
        // than adding a second event nobody will ever clean up.
        $event = CalendarEvent::factory()->create();

        expect( fn () => CalendarEvent::factory()->create( [
            'booking_id'    => $event->booking_id,
            'connection_id' => $event->connection_id,
        ] ) )->toThrow( QueryException::class );
    } );
} );

describe( 'watch channels', function (): void {
    it( 'builds the Google and Microsoft shapes', function (): void {
        $google    = CalendarWatchChannel::factory()->create();
        $microsoft = CalendarWatchChannel::factory()->microsoft()->create();

        expect( $google->channel_id )->not->toBeNull()
            ->and( $google->subscription_id )->toBeNull()
            ->and( $microsoft->subscription_id )->not->toBeNull()
            ->and( $microsoft->channel_id )->toBeNull();
    } );

    it( 'knows whether it has lapsed', function (): void {
        expect( CalendarWatchChannel::factory()->expired()->create()->hasExpired() )->toBeTrue()
            ->and( CalendarWatchChannel::factory()->create()->hasExpired() )->toBeFalse();
    } );

    it( 'knows whether it lapses soon', function (): void {
        $soon  = CalendarWatchChannel::factory()->expiringIn( 4 )->create();
        $later = CalendarWatchChannel::factory()->create();

        expect( $soon->expiresWithin( 6 * 60 ) )->toBeTrue()
            ->and( $later->expiresWithin( 6 * 60 ) )->toBeFalse();
    } );

    it( 'sweeps up everything due for renewal, lapsed ones first of all', function (): void {
        // A lapsed channel is not something to skip past. It is the one most
        // urgently in need of replacing, because the calendar behind it has
        // already stopped reporting changes.
        $lapsed  = CalendarWatchChannel::factory()->expired()->create();
        $closing = CalendarWatchChannel::factory()->expiringIn( 2 )->create();
        CalendarWatchChannel::factory()->create();

        $due = CalendarWatchChannel::expiringBefore( now()->addHours( 6 ) )->get()->modelKeys();

        sort( $due );

        expect( $due )->toBe( collect( [ $lapsed->id, $closing->id ] )->sort()->values()->all() );
    } );

    it( 'reaches its connection', function (): void {
        $channel = CalendarWatchChannel::factory()->create();

        expect( $channel->connection )->toBeInstanceOf( CalendarConnection::class )
            ->and( $channel->connection->watchChannels()->count() )->toBe( 1 );
    } );
} );

describe( 'busy blocks', function (): void {
    it( 'ties a span of busy time to a two-way connection', function (): void {
        $block = CalendarBusyBlock::factory()->create();

        expect( $block->connection )->toBeInstanceOf( CalendarConnection::class )
            ->and( $block->connection->readsBusyBlocks() )->toBeTrue()
            ->and( $block->ends_at_utc->gt( $block->starts_at_utc ) )->toBeTrue();
    } );

    it( 'upserts on the connection and external event rather than accumulating', function (): void {
        // Incremental sync replays events it has already sent.
        $block = CalendarBusyBlock::factory()->create();

        expect( fn () => CalendarBusyBlock::factory()->create( [
            'connection_id'     => $block->connection_id,
            'external_event_id' => $block->external_event_id,
        ] ) )->toThrow( QueryException::class );
    } );

    it( 'finds the blocks that clash with a window', function (): void {
        $connection = CalendarConnection::factory()->twoWay()->create();

        $overlapping = CalendarBusyBlock::factory()
            ->for( $connection, 'connection' )
            ->spanning( '2026-06-01 09:30:00', '2026-06-01 10:30:00' )
            ->create();
        $containing  = CalendarBusyBlock::factory()
            ->for( $connection, 'connection' )
            ->spanning( '2026-06-01 08:00:00', '2026-06-01 18:00:00' )
            ->create();
        CalendarBusyBlock::factory()
            ->for( $connection, 'connection' )
            ->spanning( '2026-06-01 12:00:00', '2026-06-01 13:00:00' )
            ->create();

        $clashing = CalendarBusyBlock::overlapping(
            $connection->id,
            '2026-06-01 10:00:00',
            '2026-06-01 11:00:00',
        )->get()->modelKeys();

        sort( $clashing );

        expect( $clashing )->toBe( collect( [ $overlapping->id, $containing->id ] )->sort()->values()->all() );
    } );

    it( 'leaves the boundary bookable', function (): void {
        // The span is half-open. A block ending at 10:00 does not clash with a
        // slot starting at 10:00, and treating it as one would lose a bookable
        // slot at every boundary in the day.
        $connection = CalendarConnection::factory()->twoWay()->create();

        $block = CalendarBusyBlock::factory()
            ->for( $connection, 'connection' )
            ->spanning( '2026-06-01 09:00:00', '2026-06-01 10:00:00' )
            ->create();

        expect( CalendarBusyBlock::overlapping( $connection->id, '2026-06-01 10:00:00', '2026-06-01 11:00:00' )->count() )
            ->toBe( 0 )
            ->and( $block->overlaps( '2026-06-01 10:00:00', '2026-06-01 11:00:00' ) )->toBeFalse()
            ->and( $block->overlaps( '2026-06-01 08:00:00', '2026-06-01 09:00:00' ) )->toBeFalse()
            ->and( $block->overlaps( '2026-06-01 09:59:00', '2026-06-01 11:00:00' ) )->toBeTrue();
    } );

    it( 'searches several connections at once', function (): void {
        $first  = CalendarConnection::factory()->twoWay()->create();
        $second = CalendarConnection::factory()->twoWay()->create();
        $third  = CalendarConnection::factory()->twoWay()->create();

        foreach ( [ $first, $second, $third ] as $connection ) {
            CalendarBusyBlock::factory()
                ->for( $connection, 'connection' )
                ->spanning( '2026-06-01 09:00:00', '2026-06-01 10:00:00' )
                ->create();
        }

        expect( CalendarBusyBlock::overlapping(
            [ $first->id, $second->id ],
            '2026-06-01 09:00:00',
            '2026-06-01 10:00:00',
        )->count() )->toBe( 2 );
    } );

    it( 'answers an availability lookup out of the range index', function (): void {
        // A busy-block lookup runs once per candidate provider per availability
        // request, so a plan that scans the table would be the difference
        // between a fast page and a slow one.
        $connection = CalendarConnection::factory()->twoWay()->create();
        CalendarBusyBlock::factory()->count( 5 )->for( $connection, 'connection' )->create();

        $query = CalendarBusyBlock::overlapping(
            $connection->id,
            '2026-06-01 09:00:00',
            '2026-06-01 10:00:00',
        )->toBase();

        $plan = DB::select( 'explain query plan ' . $query->toSql(), $query->getBindings() );

        expect( $plan[0]->detail )->toContain( 'busy_blocks_connection_range_index' );
    } );
} );
