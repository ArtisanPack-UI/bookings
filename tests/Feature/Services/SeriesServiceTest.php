<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\BookingAssignmentStrategy;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Enums\SeriesEditScope;
use ArtisanPackUI\Bookings\Events\SeriesCancelled;
use ArtisanPackUI\Bookings\Events\SeriesCreated;
use ArtisanPackUI\Bookings\Events\SeriesEdited;
use ArtisanPackUI\Bookings\Exceptions\SeriesException;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Core\MultiTenancy\SiteContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    // Every date in this file is a Monday in June 2026, and the edit scopes are
    // written in terms of what is still to come — so "now" has to be a fixed
    // point before all of them rather than whenever the suite happens to run.
    $this->travelTo( CarbonImmutable::parse( '2026-05-25 12:00:00', 'UTC' ) );
} );

afterEach( function (): void {
    $this->travelBack();
} );

describe( 'expansion', function (): void {
    it( 'expands a bounded weekly rule into the instants it names', function (): void {
        $instants = seriesService()->expand(
            'FREQ=WEEKLY;COUNT=3',
            CarbonImmutable::parse( '2026-06-01 10:00:00' ),
            'America/Chicago',
        );

        expect( $instants )->toHaveCount( 3 )
            ->and( $instants[0]->toIso8601String() )->toBe( '2026-06-01T15:00:00+00:00' )
            ->and( $instants[1]->toIso8601String() )->toBe( '2026-06-08T15:00:00+00:00' )
            ->and( $instants[2]->toIso8601String() )->toBe( '2026-06-15T15:00:00+00:00' );
    } );

    it( 'keeps the local clock face across a daylight-saving change', function (): void {
        // The reason the rule rather than the rows is the source of truth. A
        // customer booking a weekly 15:00 call means 15:00 every week, so the
        // occurrences either side of the November clock change have to stay an
        // hour apart in UTC and identical locally.
        $instants = seriesService()->expand(
            'FREQ=WEEKLY;COUNT=3',
            CarbonImmutable::parse( '2026-10-26 15:00:00' ),
            'America/Chicago',
        );

        $local = [];

        foreach ( $instants as $instant ) {
            $local[] = $instant->setTimezone( 'America/Chicago' )->format( 'H:i' );
        }

        expect( $local )->toBe( [ '15:00', '15:00', '15:00' ] )
            ->and( $instants[0]->format( 'H:i' ) )->toBe( '20:00' )
            ->and( $instants[1]->format( 'H:i' ) )->toBe( '21:00' )
            ->and( $instants[2]->format( 'H:i' ) )->toBe( '21:00' );
    } );

    it( 'caps an unbounded rule at the configured maximum', function (): void {
        config()->set( 'artisanpack.bookings.series.max_occurrences', 7 );

        expect( seriesService()->expand(
            'FREQ=DAILY',
            CarbonImmutable::parse( '2026-06-01 10:00:00' ),
            'UTC',
        ) )->toHaveCount( 7 );
    } );

    it( 'lets the earlier of the rule bound and the series bound win', function (): void {
        // `Rule::setUntil()` clears COUNT, so the bound cannot be pushed into
        // the rule — a series that says "twelve times" and "not past June" has
        // to keep both, and stop at whichever comes first.
        $instants = seriesService()->expand(
            'FREQ=WEEKLY;COUNT=12',
            CarbonImmutable::parse( '2026-06-01 10:00:00' ),
            'America/Chicago',
            CarbonImmutable::parse( '2026-06-16 23:59:59' ),
        );

        expect( $instants )->toHaveCount( 3 );
    } );

    it( 'refuses a rule it cannot read', function (): void {
        expect( fn () => seriesService()->expand(
            'EVERY OTHER TUESDAY',
            CarbonImmutable::parse( '2026-06-01 10:00:00' ),
            'UTC',
        ) )->toThrow( SeriesException::class );
    } );

    it( 'refuses a rule that names no occurrences at all', function (): void {
        expect( fn () => seriesService()->expand(
            'FREQ=WEEKLY',
            CarbonImmutable::parse( '2026-06-01 10:00:00' ),
            'UTC',
            CarbonImmutable::parse( '2026-05-01 00:00:00' ),
        ) )->toThrow( SeriesException::class );
    } );

    it( 'reports a mistyped timezone as a timezone rather than as a bad rule', function (): void {
        // Telling somebody their RRULE is malformed when what they mistyped is
        // "America/Chicgao" sends them looking in the wrong place.
        expect( fn () => seriesService()->expand(
            'FREQ=WEEKLY;COUNT=3',
            CarbonImmutable::parse( '2026-06-01 10:00:00' ),
            'America/Chicgao',
        ) )->toThrow( InvalidArgumentException::class );
    } );
} );

describe( 'creating a series', function (): void {
    it( 'materialises one booking per occurrence, in order', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        $occurrences = $series->occurrences()->get();

        expect( $occurrences )->toHaveCount( 3 )
            ->and( $occurrences->pluck( 'series_index' )->all() )->toBe( [ 0, 1, 2 ] )
            ->and( $occurrences->pluck( 'start_time' )->map->toIso8601String()->all() )->toBe( [
                '2026-06-01T15:00:00+00:00',
                '2026-06-08T15:00:00+00:00',
                '2026-06-15T15:00:00+00:00',
            ] )
            ->and( $occurrences->pluck( 'status' )->unique()->all() )->toBe( [ BookingStatus::Confirmed ] );
    } );

    it( 'pins every occurrence to the one provider the rota picked', function (): void {
        // Recurring means the same person. A weekly 1:1 that round-robined a
        // different coach each week would be a different product.
        [ $service ] = bookableService( 3 );

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        expect( $series->occurrences()->pluck( 'provider_id' )->unique() )->toHaveCount( 1 )
            ->and( (int) $series->fresh()->provider_id )
            ->toBe( (int) $series->occurrences()->first()->provider_id );
    } );

    it( 'records only the count the rule itself declares', function (): void {
        [ $service ] = bookableService();

        $counted = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );
        $bounded = seriesService()->create( recurringCustomer( [
            'service'     => $service,
            'rrule'       => 'FREQ=WEEKLY',
            'until_local' => '2026-06-16 23:59:59',
            'start'       => '11:00',
        ] ) );

        expect( $counted->occurrence_count )->toBe( 3 )
            ->and( $bounded->occurrence_count )->toBeNull()
            ->and( $bounded->occurrences()->count() )->toBe( 3 );
    } );

    it( 'skips an occurrence whose slot has gone and keeps the gap', function (): void {
        // A rule expanded over months will cross somebody's holiday sooner or
        // later. Refusing the whole arrangement over week two serves nobody, and
        // renumbering around the hole would make week three look like week two.
        [ $service ] = bookableService();

        bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '10:00' )->addWeek(),
        ] ) );

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        expect( $series->occurrences()->pluck( 'series_index' )->all() )->toBe( [ 0, 2 ] );
    } );

    it( 'dispatches SeriesCreated with the count it actually booked', function (): void {
        [ $service ] = bookableService();

        bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => bookingStart( '10:00' )->addWeek(),
        ] ) );

        Event::fake( [ SeriesCreated::class ] );

        seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        Event::assertDispatched(
            SeriesCreated::class,
            static fn ( SeriesCreated $event ): bool => 2 === $event->occurrenceCount,
        );
    } );

    it( 'refuses a series where not one occurrence could be booked', function (): void {
        [ $service ] = bookableService();

        expect( fn () => seriesService()->create( recurringCustomer( [
            'service' => $service,
            'start'   => '03:00',
        ] ) ) )->toThrow( SlotUnavailableException::class );

        // And leaves nothing behind. A series row with no occurrences shows up
        // in the admin list as a recurring booking whose appointments nobody
        // can find.
        expect( BookingSeries::query()->count() )->toBe( 0 );
    } );

    it( 'refuses a series with no rule and one with no service', function (): void {
        [ $service ] = bookableService();

        expect( fn () => seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => '',
        ] ) ) )->toThrow( InvalidArgumentException::class );

        expect( fn () => seriesService()->create( recurringCustomer( [ 'service_id' => 9999 ] ) ) )
            ->toThrow( InvalidArgumentException::class );
    } );

    it( 'frees the slots it already took when a later occurrence throws', function (): void {
        // `bookings.series_id` is nullOnDelete, so deleting the series alone
        // strands whatever was already written: the database nulls the key
        // without Eloquent running, the rows keep a series_index for a series
        // that no longer exists, and — far worse — they go on holding their
        // provider's slots for an arrangement the caller was told did not
        // happen.
        [ $service ] = bookableService();

        $calls = 0;

        addFilter( 'ap.bookings.availableProviders', function ( array $providers ) use ( &$calls ): array {
            $calls++;

            if ( $calls >= 3 ) {
                throw new RuntimeException( 'Something broke partway through.' );
            }

            return $providers;
        } );

        expect( fn () => seriesService()->create( recurringCustomer( [ 'service' => $service ] ) ) )
            ->toThrow( RuntimeException::class );

        removeAllFilters( 'ap.bookings.availableProviders' );

        expect( BookingSeries::query()->count() )->toBe( 0 )
            ->and( Booking::withTrashed()->count() )->toBe( 0 );
    } );

    it( 'records how the provider was really chosen on every occurrence', function (): void {
        // Each occurrence after the first names the pinned provider, which puts
        // BookingService on its "the caller chose" branch — where the strategy
        // defaults to `customer`. Eleven weeks of a twelve-week rota-assigned
        // series claiming the customer picked would be a lie the reporting
        // screens read straight out of the column.
        [ $service ] = bookableService( 2 );

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        expect( $series->occurrences()->pluck( 'assignment_strategy' )->unique()->all() )
            ->toBe( [ BookingAssignmentStrategy::RoundRobin ] );
    } );

    it( 'leaves no series behind when the first occurrence is refused outright', function (): void {
        // A provider who does not offer the service is a caller mistake that only
        // surfaces once the first booking is attempted — by which point the
        // series row already exists.
        [ $service ] = bookableService();

        expect( fn () => seriesService()->create( recurringCustomer( [
            'service'  => $service,
            'provider' => ServiceProvider::factory()->create(),
        ] ) ) )->toThrow( InvalidArgumentException::class );

        expect( BookingSeries::query()->count() )->toBe( 0 );
    } );
} );

describe( 'editing one occurrence', function (): void {
    it( 'detaches the occurrence and leaves the rule alone', function (): void {
        [ $service ] = bookableService();

        $series     = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );
        $occurrence = $series->occurrences()->skip( 1 )->first();

        seriesService()->edit(
            $series,
            SeriesEditScope::This,
            [ 'notes' => 'Bring the draft.' ],
            $occurrence,
            BookingActor::Admin,
        );

        $occurrence->refresh();
        $series->refresh();

        expect( $occurrence->isDetachedFromSeries() )->toBeTrue()
            ->and( $occurrence->notes )->toBe( 'Bring the draft.' )
            ->and( $occurrence->series_id )->toBe( $series->id )
            ->and( $series->rrule )->toBe( 'FREQ=WEEKLY;COUNT=3' )
            ->and( $series->until_local )->toBeNull();
    } );

    it( 'changes nothing at all when the move loses a race for the new time', function (): void {
        // Somebody else can take the new time between the widget rendering it
        // and the form arriving. Detaching or rewriting the notes first would
        // leave the occurrence half-edited at the time it always had — and
        // invisible to every later rewrite of the rule — with no SeriesEdited
        // to say so.
        [ $service ] = bookableService();

        $series     = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );
        $occurrence = $series->occurrences()->first();
        $taken      = bookingStart( '14:00' );

        bookingService()->create( bookingCustomer( [
            'service'    => $service,
            'start_time' => $taken,
        ] ) );

        expect( fn () => seriesService()->edit(
            $series,
            SeriesEditScope::This,
            [ 'start_time' => $taken, 'notes' => 'Should not stick.' ],
            $occurrence,
        ) )->toThrow( SlotUnavailableException::class );

        $occurrence->refresh();

        expect( $occurrence->isDetachedFromSeries() )->toBeFalse()
            ->and( $occurrence->notes )->toBeNull()
            ->and( $occurrence->start_time->toIso8601String() )->toBe( bookingStart( '10:00' )->toIso8601String() );
    } );

    it( 'moves the occurrence through the booking service', function (): void {
        [ $service ] = bookableService();

        $series     = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );
        $occurrence = $series->occurrences()->first();
        $moved      = bookingStart( '14:00' );

        seriesService()->edit( $series, SeriesEditScope::This, [ 'start_time' => $moved ], $occurrence );

        expect( $occurrence->fresh()->start_time->toIso8601String() )->toBe( $moved->toIso8601String() )
            ->and( $occurrence->fresh()->isDetachedFromSeries() )->toBeTrue();
    } );

    it( 'refuses an occurrence belonging to another series', function (): void {
        [ $service ] = bookableService( 2 );

        $first  = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );
        $second = seriesService()->create( recurringCustomer( [ 'service' => $service, 'start' => '11:00' ] ) );

        expect( fn () => seriesService()->edit(
            $first,
            SeriesEditScope::This,
            [],
            $second->occurrences()->first(),
        ) )->toThrow( SeriesException::class );
    } );

    it( 'refuses a scoped edit that names no occurrence to start from', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        expect( fn () => seriesService()->edit( $series, SeriesEditScope::ThisAndFollowing, [] ) )
            ->toThrow( SeriesException::class );
    } );
} );

describe( 'editing this and following', function (): void {
    it( 'bounds the original rule and carries the change forward on a new one', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => 'FREQ=WEEKLY;COUNT=4',
        ] ) );

        $from = $series->occurrences()->skip( 2 )->first();

        $tail = seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [ 'dtstart_local' => '2026-06-15 11:00:00', 'rrule' => 'FREQ=WEEKLY;COUNT=2' ],
            $from,
            BookingActor::Admin,
        );

        $series->refresh();

        expect( $tail->is( $series ) )->toBeFalse()
            ->and( $series->rrule )->toBe( 'FREQ=WEEKLY;COUNT=4' )
            ->and( $series->until_local->format( 'Y-m-d H:i:s' ) )->toBe( '2026-06-15 09:59:59' )
            ->and( $tail->rrule )->toBe( 'FREQ=WEEKLY;COUNT=2' )
            ->and( $tail->dtstart_local->format( 'Y-m-d H:i:s' ) )->toBe( '2026-06-15 11:00:00' )
            ->and( $tail->dtstart_timezone )->toBe( 'America/Chicago' );

        // The head keeps everything already delivered under it; the two
        // occurrences from the split point on are called off and reissued at the
        // new time under the tail.
        expect( $series->occurrences()->where( 'status', BookingStatus::Cancelled )->count() )->toBe( 2 )
            ->and( $series->occurrences()->active()->count() )->toBe( 2 )
            ->and( $tail->occurrences()->pluck( 'start_time' )->map->toIso8601String()->all() )->toBe( [
                '2026-06-15T16:00:00+00:00',
                '2026-06-22T16:00:00+00:00',
            ] )
            ->and( $tail->occurrences()->pluck( 'series_index' )->all() )->toBe( [ 0, 1 ] );
    } );

    it( 'inherits the head\'s own upper bound rather than the one it was just given', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service'     => $service,
            'rrule'       => 'FREQ=WEEKLY',
            'until_local' => '2026-06-23 23:59:59',
        ] ) );

        $tail = seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [],
            $series->occurrences()->skip( 1 )->first(),
        );

        expect( $tail->until_local->format( 'Y-m-d H:i:s' ) )->toBe( '2026-06-23 23:59:59' )
            ->and( $tail->occurrences()->count() )->toBe( 3 );
    } );

    it( 'leaves the head standing when the tail\'s rule cannot be read', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        expect( fn () => seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [ 'rrule' => 'EVERY TUESDAY' ],
            $series->occurrences()->skip( 1 )->first(),
        ) )->toThrow( SeriesException::class );

        expect( $series->fresh()->until_local )->toBeNull()
            ->and( $series->occurrences()->active()->count() )->toBe( 3 )
            ->and( BookingSeries::query()->count() )->toBe( 1 );
    } );

    it( 'writes the tail under the head\'s site, not whichever one is in context', function (): void {
        // The two paths the package supports for crossing sites — a console
        // command looping with SiteContext::forSite(), and a maintenance query
        // using acrossAllSites() — both leave the ambient site disagreeing with
        // the series being edited. A tail stamped from the ambient site would
        // carry this customer's name, email, and phone into another tenant, and
        // hide their forward bookings from their own.
        scopeToSite( 7 );

        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        scopeToSite( 9 );

        $from = Booking::query()
            ->acrossAllSites()
            ->where( 'series_id', $series->getKey() )
            ->orderBy( 'series_index' )
            ->skip( 1 )
            ->first();

        $tail = seriesService()->edit( $series, SeriesEditScope::ThisAndFollowing, [], $from );

        $occurrences = Booking::query()
            ->acrossAllSites()
            ->where( 'series_id', $tail->getKey() )
            ->get();

        // The tail row, and every booking the re-materialisation wrote under it.
        // Without pinning, the service lookup would not even have found the
        // site-7 service from site 9 — the split would have half-applied and
        // thrown.
        // Two: the head keeps the first of its three, so the tail carries the
        // remaining two rather than starting the count over.
        expect( (int) $tail->getAttribute( 'site_id' ) )->toBe( 7 )
            ->and( $occurrences )->toHaveCount( 2 )
            ->and( $occurrences->pluck( 'site_id' )->map( 'intval' )->unique()->all() )->toBe( [ 7 ] );
    } );

    it( 'restores whatever site was in context once the edit is done', function (): void {
        scopeToSite( 7 );

        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        scopeToSite( 9 );

        seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [],
            Booking::query()->acrossAllSites()->where( 'series_id', $series->getKey() )->skip( 1 )->first(),
        );

        expect( app( SiteContext::class )->currentSiteId() )->toBe( 9 );
    } );

    it( 'divides a COUNT between the halves instead of handing it to both', function (): void {
        // The tail starts a fresh dtstart, so an inherited COUNT would start
        // counting over: a twelve-week package moved from week five would
        // quietly become sixteen sessions, billed or not.
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => 'FREQ=WEEKLY;COUNT=4',
        ] ) );

        $tail = seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [],
            $series->occurrences()->skip( 2 )->first(),
        );

        expect( $tail->rrule )->toBe( 'FREQ=WEEKLY;COUNT=2' )
            ->and( $tail->occurrence_count )->toBe( 2 )
            ->and( $tail->occurrences()->count() )->toBe( 2 )
            ->and( $series->fresh()->occurrences()->active()->count() )->toBe( 2 );
    } );

    it( 'counts the split by rule position, not by a rescheduled wall instant', function (): void {
        // A customer rescheduled occurrence #5 off its rule time — later than the
        // sixth occurrence's slot — the way ManageBooking does, without detaching.
        // Splitting by wall instant would count seven occurrences before it and
        // hand the tail COUNT=5; the split has to count by series_index instead,
        // so the tail keeps the seven occurrences that genuinely follow it.
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => 'FREQ=WEEKLY;COUNT=12',
        ] ) );

        $anchor = $series->occurrences()->where( 'series_index', 5 )->firstOrFail();

        // 2026-07-06 (index 5) moved to 2026-07-13 12:00 — past the index-6 slot
        // at 2026-07-13 10:00, and free because that occurrence ends at 11:00.
        bookingService()->reschedule(
            $anchor,
            CarbonImmutable::parse( '2026-07-13 12:00', 'America/Chicago' ),
            BookingActor::Customer,
        );

        $tail = seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [],
            $series->occurrences()->where( 'series_index', 5 )->firstOrFail(),
        );

        expect( $tail->rrule )->toBe( 'FREQ=WEEKLY;COUNT=7' )
            ->and( $tail->occurrence_count )->toBe( 7 )
            ->and( $series->fresh()->occurrences()->active()->count() )->toBe( 5 );
    } );

    it( 'leaves a caller\'s own rule alone rather than dividing it', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => 'FREQ=WEEKLY;COUNT=4',
        ] ) );

        $tail = seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [ 'rrule' => 'FREQ=WEEKLY;COUNT=3' ],
            $series->occurrences()->skip( 2 )->first(),
        );

        expect( $tail->rrule )->toBe( 'FREQ=WEEKLY;COUNT=3' );
    } );

    it( 'cancels the head when the split takes its very first occurrence', function (): void {
        // Bounding it instead would write an UNTIL a second before its own
        // DTSTART — a rule naming nothing, which throws on every later
        // expansion and leaves the head permanently unelectable.
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        $tail = seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [],
            $series->occurrences()->first(),
        );

        $series->refresh();

        expect( $series->isCancelled() )->toBeTrue()
            ->and( $series->until_local )->toBeNull()
            ->and( $series->occurrences()->active()->count() )->toBe( 0 )
            ->and( $tail->occurrences()->count() )->toBe( 3 );
    } );

    it( 'removes the tail and refuses when it could book nothing at all', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        expect( fn () => seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [ 'dtstart_local' => '2026-06-08 03:00:00' ],
            $series->occurrences()->skip( 1 )->first(),
        ) )->toThrow( SlotUnavailableException::class );

        // The head's future is gone either way — that is what the scope means —
        // but no empty tail is left claiming to carry the arrangement forward.
        expect( BookingSeries::query()->count() )->toBe( 1 );
    } );

    it( 'carries the intake answers on to the tail', function (): void {
        // The tail has no occurrences of its own to read them from, and the rule
        // does not carry them — so without the head as a template the provider
        // would get three bookings with the form blank.
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'notes'   => 'Ground floor room, please.',
        ] ) );

        $tail = seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [],
            $series->occurrences()->skip( 1 )->first(),
        );

        expect( $tail->occurrences()->first()->notes )->toBe( 'Ground floor room, please.' );
    } );
} );

describe( 'editing the whole series', function (): void {
    it( 're-materialises everything still to come under the new rule', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => 'FREQ=WEEKLY;COUNT=4',
        ] ) );

        seriesService()->edit(
            $series,
            SeriesEditScope::All,
            [ 'rrule' => 'FREQ=WEEKLY;COUNT=2' ],
            null,
            BookingActor::Admin,
        );

        $series->refresh();

        expect( $series->rrule )->toBe( 'FREQ=WEEKLY;COUNT=2' )
            ->and( $series->occurrences()->where( 'status', BookingStatus::Cancelled )->count() )->toBe( 4 )
            ->and( $series->occurrences()->active()->pluck( 'series_index' )->all() )->toBe( [ 4, 5 ] )
            ->and( $series->occurrences()->active()->pluck( 'start_time' )->map->toIso8601String()->all() )
            ->toBe( [ '2026-06-01T15:00:00+00:00', '2026-06-08T15:00:00+00:00' ] );
    } );

    it( 'leaves a detached occurrence exactly where somebody put it', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => 'FREQ=WEEKLY;COUNT=4',
        ] ) );

        $detached = $series->occurrences()->skip( 3 )->first();

        seriesService()->edit( $series, SeriesEditScope::This, [], $detached );
        seriesService()->edit( $series, SeriesEditScope::All, [ 'rrule' => 'FREQ=WEEKLY;COUNT=2' ] );

        $detached->refresh();

        expect( $detached->status )->toBe( BookingStatus::Confirmed )
            ->and( $detached->start_time->toIso8601String() )->toBe( '2026-06-22T15:00:00+00:00' );
    } );

    it( 'leaves the whole arrangement standing when the new rule cannot be read', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        expect( fn () => seriesService()->edit( $series, SeriesEditScope::All, [ 'rrule' => 'EVERY TUESDAY' ] ) )
            ->toThrow( SeriesException::class );

        expect( $series->fresh()->rrule )->toBe( 'FREQ=WEEKLY;COUNT=3' )
            ->and( $series->occurrences()->active()->count() )->toBe( 3 );
    } );

    it( 'leaves the occurrences that have already happened alone', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => 'FREQ=WEEKLY;COUNT=4',
        ] ) );

        $this->travelTo( CarbonImmutable::parse( '2026-06-10 12:00:00', 'UTC' ) );

        seriesService()->edit( $series, SeriesEditScope::All, [ 'rrule' => 'FREQ=WEEKLY;COUNT=4' ] );

        $delivered = $series->occurrences()->whereIn( 'series_index', [ 0, 1 ] )->get();

        expect( $delivered->pluck( 'status' )->unique()->all() )->toBe( [ BookingStatus::Confirmed ] );
    } );
} );

describe( 'cancelling a series', function (): void {
    it( 'cancels everything still to come and marks the rule off', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        Event::fake( [ SeriesCancelled::class ] );

        seriesService()->cancel( $series, BookingActor::Customer, 'Moving away.' );

        expect( $series->fresh()->isCancelled() )->toBeTrue()
            ->and( $series->occurrences()->active()->count() )->toBe( 0 );

        Event::assertDispatched(
            SeriesCancelled::class,
            static fn ( SeriesCancelled $event ): bool => 3 === $event->cancelledOccurrenceCount
                && BookingActor::Customer === $event->actor
                && 'Moving away.' === $event->reason,
        );
    } );

    it( 'keeps the appointments that already happened', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [
            'service' => $service,
            'rrule'   => 'FREQ=WEEKLY;COUNT=4',
        ] ) );

        $this->travelTo( CarbonImmutable::parse( '2026-06-10 12:00:00', 'UTC' ) );

        seriesService()->cancel( $series, BookingActor::Admin );

        expect( $series->occurrences()->active()->pluck( 'series_index' )->all() )->toBe( [ 0, 1 ] );
    } );

    it( 'cancels a detached occurrence along with the arrangement it belongs to', function (): void {
        // Somebody moved one week to a different afternoon. That does not make it
        // any less part of the thing being called off.
        [ $service ] = bookableService();

        $series   = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );
        $detached = $series->occurrences()->skip( 1 )->first();

        seriesService()->edit( $series, SeriesEditScope::This, [], $detached );
        seriesService()->cancel( $series, BookingActor::Admin );

        expect( $detached->fresh()->status )->toBe( BookingStatus::Cancelled );
    } );

    it( 'refuses to edit a series that has been called off', function (): void {
        // The way in is ordinary: an admin with the edit form already open when
        // the customer cancels it themselves. Without the guard the series still
        // reports itself cancelled while carrying live confirmed bookings, and
        // the provider gets appointments for an arrangement every screen says is
        // off.
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        seriesService()->cancel( $series, BookingActor::Customer );

        expect( fn () => seriesService()->edit( $series, SeriesEditScope::All, [ 'rrule' => 'FREQ=WEEKLY;COUNT=2' ] ) )
            ->toThrow( SeriesException::class );

        expect( $series->fresh()->occurrences()->active()->count() )->toBe( 0 );
    } );

    it( 'refuses to cancel a series twice', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        seriesService()->cancel( $series, BookingActor::Admin );

        expect( fn () => seriesService()->cancel( $series, BookingActor::Admin ) )
            ->toThrow( SeriesException::class );
    } );
} );

describe( 'the edited event', function (): void {
    it( 'carries the scope, and the tail when the edit split the series', function (): void {
        [ $service ] = bookableService();

        $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

        Event::fake( [ SeriesEdited::class ] );

        seriesService()->edit( $series, SeriesEditScope::This, [], $series->occurrences()->first() );
        seriesService()->edit(
            $series,
            SeriesEditScope::ThisAndFollowing,
            [],
            $series->occurrences()->skip( 2 )->first(),
        );

        Event::assertDispatched(
            SeriesEdited::class,
            static fn ( SeriesEdited $event ): bool => SeriesEditScope::This === $event->scope
                && null === $event->splitSeries,
        );

        Event::assertDispatched(
            SeriesEdited::class,
            static fn ( SeriesEdited $event ): bool => SeriesEditScope::ThisAndFollowing === $event->scope
                && $event->splitSeries instanceof BookingSeries,
        );
    } );
} );

it( 'writes occurrences that are ordinary bookings in every other respect', function (): void {
    [ $service ] = bookableService();

    $series = seriesService()->create( recurringCustomer( [ 'service' => $service ] ) );

    /** @var Booking $occurrence */
    $occurrence = $series->occurrences()->first();

    expect( $occurrence->booking_number )->not->toBeEmpty()
        ->and( $occurrence->manage_token_hash )->not->toBeEmpty()
        ->and( $occurrence->customer_email )->toBe( 'sam@example.test' )
        ->and( $occurrence->customer_timezone )->toBe( 'America/Chicago' )
        ->and( $occurrence->intake_schema_version )->toBe( (int) $service->intake_schema_version );
} );
