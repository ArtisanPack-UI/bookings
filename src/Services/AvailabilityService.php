<?php

/**
 * Availability service.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Services;

use ArtisanPackUI\Bookings\Contracts\SlotResolver;
use ArtisanPackUI\Bookings\Models\AvailabilityOverride;
use ArtisanPackUI\Bookings\Models\AvailabilitySchedule;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\CalendarBusyBlock;
use ArtisanPackUI\Bookings\Models\Scopes\BelongsToSiteScope;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceBlackoutDate;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Models\ServiceProviderService;
use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;
use UnexpectedValueException;

use function applyFilters;
use function array_values;
use function get_debug_type;
use function hash;
use function intdiv;
use function is_array;
use function is_bool;
use function is_int;
use function max;
use function min;
use function sprintf;
use function usort;

/**
 * Answers what a service can be booked into, and when.
 *
 * The whole widget rests on this question, so the order the answer is built in
 * matters more than any single step. Availability is authored as wall-clock time
 * in a provider's own zone, so the clock face comes first and the instant second:
 * a candidate slot is named as a time of day on a date, turned into a real moment
 * against the provider's zone, and only then compared against anything. That is
 * what keeps nine o'clock meaning nine o'clock on the two days a year the offset
 * moves under it, and it is why a slot that names a local time the clocks skipped
 * is dropped rather than quietly rounded into one that exists.
 *
 * What is subtracted, in order: a blackout closes the day outright; an
 * unavailable override closes it for one provider; a custom-hours override
 * replaces the weekly schedule; existing bookings and externally reported busy
 * blocks remove individual slots. Buffers widen a candidate rather than the
 * things it is compared against, so the gap a service asks for is enforced once
 * and from one side.
 *
 * Results are cached per service, provider, and provider-local date, under a key
 * carrying stamps that every write to a schedule, override, booking, blackout, or
 * busy block bumps. The TTL is a backstop, not the mechanism.
 *
 * The extension filters split across that cache deliberately.
 * `ap.bookings.availabilityQuery` and `ap.bookings.slotDuration` shape the
 * computation itself and are cached with it — a subscriber to either is stating a
 * fact about the service, not about the person asking.
 * `ap.bookings.slotBookable` and `ap.bookings.availableSlots` run on every call,
 * after the cache is read, because those are the ones a plugin varies per
 * customer, and a per-customer answer written into an entry keyed by
 * `(service, provider, date)` would be handed to the next customer along.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class AvailabilityService implements SlotResolver
{
    /**
     * The prefix every availability cache entry and stamp is stored under.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected const CACHE_PREFIX = 'artisanpack.bookings.availability';

    /**
     * The longest a computed day may be trusted for, in seconds.
     *
     * The stamps are what actually keep the cache honest, so this only has to
     * bound the damage from a write that happened somewhere the stamps could not
     * see — a row changed by hand, or by an application that writes with the
     * query builder rather than the models.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const MAX_TTL_SECONDS = 300;

    /**
     * Constructs the service.
     *
     * @since 1.0.0
     *
     * @param  CacheRepository  $cache  The store computed days are kept in.
     * @param  ConfigRepository  $config  The application configuration.
     */
    public function __construct(
        protected CacheRepository $cache,
        protected ConfigRepository $config,
    ) {
    }

    /**
     * Resolves the slots a service can be booked into over a window.
     *
     * Given a provider, the slots come back assigned to them. Given none, every
     * active provider offering the service is resolved and the results are
     * collapsed to the distinct periods somebody is free in, unassigned — which
     * is the shape a round-robin service wants, since who serves a slot is
     * decided after the customer picks one. {@see self::resolveByProvider()} is
     * the same work with the assignment kept.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider|null  $provider  The provider to resolve for, or null
     *                                          to resolve across every provider the
     *                                          service is offered by.
     * @param  TimeRange  $window  The span of time to resolve within.
     *
     * @throws UnexpectedValueException When a subscriber to one of the
     *                                  `ap.bookings.*` availability filters
     *                                  returns something the filter cannot use.
     *
     * @return array<int, Slot> The bookable slots, ascending by start.
     */
    public function resolve( Service $service, ?ServiceProvider $provider, TimeRange $window ): array
    {
        if ( null !== $provider ) {
            return $this->slotsForProvider( $service, $provider, $window );
        }

        $distinct = [];

        foreach ( $this->resolveByProvider( $service, $window ) as $slots ) {
            foreach ( $slots as $slot ) {
                $distinct[ $this->periodKey( $slot->period ) ] = new Slot( $slot->period );
            }
        }

        return $this->sorted( array_values( $distinct ) );
    }

    /**
     * Resolves the slots over a window, keeping who would serve each one.
     *
     * The admin calendar and the round-robin assigner both need to know which
     * provider a free period belongs to, which {@see self::resolve()} deliberately
     * discards when asked across everybody.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  TimeRange  $window  The span of time to resolve within.
     *
     * @throws UnexpectedValueException When a subscriber to one of the
     *                                  `ap.bookings.*` availability filters
     *                                  returns something the filter cannot use.
     *
     * @return array<int, array<int, Slot>> The slots, keyed by provider id.
     */
    public function resolveByProvider( Service $service, TimeRange $window ): array
    {
        $byProvider = [];

        foreach ( $this->providersFor( $service ) as $provider ) {
            $slots = $this->slotsForProvider( $service, $provider, $window );

            if ( [] !== $slots ) {
                $byProvider[ (int) $provider->getKey() ] = $slots;
            }
        }

        return $byProvider;
    }

    /**
     * Drops every cached day computed for a provider.
     *
     * Bumping a counter rather than deleting keys: the entries are spread across
     * one key per service and date, and a store this package does not control
     * cannot be asked to enumerate them. A new stamp makes every one of them
     * unreachable at once, and the old entries age out on their own TTL.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider whose availability changed.
     *
     * @return void
     */
    public function invalidateProvider( int $providerId ): void
    {
        $this->bump( self::CACHE_PREFIX . '.stamp.provider.' . $providerId );
    }

    /**
     * Drops every cached day computed for a service.
     *
     * @since 1.0.0
     *
     * @param  int  $serviceId  The service whose availability changed.
     *
     * @return void
     */
    public function invalidateService( int $serviceId ): void
    {
        $this->bump( self::CACHE_PREFIX . '.stamp.service.' . $serviceId );
    }

    /**
     * Drops every cached day, for every service and provider.
     *
     * For the writes that cannot be attributed to one of either — a site-wide
     * blackout closes services that do not exist yet.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function invalidateEverything(): void
    {
        $this->bump( self::CACHE_PREFIX . '.stamp.global' );
    }

    /**
     * Resolves one provider's slots over a window.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider  $provider  The provider being resolved.
     * @param  TimeRange  $window  The span of time to resolve within.
     *
     * @throws UnexpectedValueException When a subscriber to one of the
     *                                  `ap.bookings.*` availability filters
     *                                  returns something the filter cannot use.
     *
     * @return array<int, Slot> The provider's bookable slots, ascending by start.
     */
    protected function slotsForProvider( Service $service, ServiceProvider $provider, TimeRange $window ): array
    {
        $providerId = (int) $provider->getKey();
        $slots      = [];

        foreach ( $this->localDatesSpanning( $window, $provider->timezone ) as $date ) {
            foreach ( $this->cachedDay( $service, $provider, $date ) as $period ) {
                $range = new TimeRange(
                    CarbonImmutable::parse( $period['start'] ),
                    CarbonImmutable::parse( $period['end'] ),
                );

                // A slot that runs past either end of the window is not a
                // shorter slot, it is one the caller did not ask about.
                if ( $range->start->lessThan( $window->start ) || $range->end->greaterThan( $window->end ) ) {
                    continue;
                }

                $slots[] = new Slot( $range, $providerId );
            }
        }

        $slots = $this->sorted( $slots );
        $slots = $this->bookableSlots( $slots );

        return $this->filterAvailableSlots( $slots, $provider, $window );
    }

    /**
     * Gets the providers a service can be booked with.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     *
     * @return array<int, ServiceProvider> The active providers offering the service.
     */
    protected function providersFor( Service $service ): array
    {
        $providers = $service->providers()->active()->get()->all();

        if ( [] !== $providers ) {
            return $providers;
        }

        // Attachment is asked about separately from activity, and the difference
        // matters. A service nobody has been attached to still books against its
        // fallback — that is what `default_provider_id` is for. A service whose
        // providers have all been deactivated is closed, and falling back there
        // would offer slots with somebody who does not offer the service, as a
        // side effect of an administrator switching the last one off.
        if ( $service->providers()->exists() ) {
            return [];
        }

        $default = $service->defaultProvider;

        return null !== $default && $default->is_active ? [ $default ] : [];
    }

    /**
     * Gets one provider-local date's slots, from cache when it is enabled.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider  $provider  The provider being resolved.
     * @param  string  $date  The provider-local date, as `Y-m-d`.
     *
     * @throws UnexpectedValueException When a subscriber to
     *                                  `ap.bookings.availabilityQuery` or
     *                                  `ap.bookings.slotDuration` returns
     *                                  something the filter cannot use.
     *
     * @return array<int, array{start: string, end: string}> The day's slots.
     */
    protected function cachedDay( Service $service, ServiceProvider $provider, string $date ): array
    {
        if ( true !== $this->config->get( 'artisanpack.bookings.availability_cache.enabled', true ) ) {
            return $this->computeDay( $service, $provider, $date );
        }

        $ttl = (int) $this->config->get( 'artisanpack.bookings.availability_cache.ttl_seconds', self::MAX_TTL_SECONDS );

        return $this->cache->remember(
            $this->cacheKey( $service, $provider, $date ),
            max( 1, min( $ttl, self::MAX_TTL_SECONDS ) ),
            fn (): array => $this->computeDay( $service, $provider, $date ),
        );
    }

    /**
     * Computes one provider-local date's slots from the availability rules.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider  $provider  The provider being resolved.
     * @param  string  $date  The provider-local date, as `Y-m-d`.
     *
     * @throws UnexpectedValueException When a subscriber to
     *                                  `ap.bookings.availabilityQuery` or
     *                                  `ap.bookings.slotDuration` returns
     *                                  something the filter cannot use.
     *
     * @return array<int, array{start: string, end: string}> The day's slots, as
     *                                                       ISO 8601 instants.
     */
    protected function computeDay( Service $service, ServiceProvider $provider, string $date ): array
    {
        if ( ServiceBlackoutDate::query()->closing( $service, $date )->exists() ) {
            return [];
        }

        $timezone = $provider->timezone;
        $windows  = $this->availabilityWindows( $provider, $date, $timezone );

        if ( [] === $windows ) {
            return [];
        }

        $duration = $this->slotDuration( $service, $provider );
        $interval = max( 1, (int) $this->config->get( 'artisanpack.bookings.slot_interval', 15 ) );
        $before   = max( 0, (int) $service->buffer_before );
        $after    = max( 0, (int) $service->buffer_after );

        $dayStart = CarbonImmutable::parse( $date, $timezone )->startOfDay();
        $dayEnd   = $dayStart->addDay();
        // Widened by the largest buffer anybody asks for, not by this service's.
        // The clash test runs both ways — this candidate's padding against the
        // other booking's time, and this candidate's time against the other
        // booking's padding — so a neighbour whose own buffer reaches back into
        // this day has to be fetched even when this service asks for no gap at
        // all. Bounding the fetch by the candidate's buffers would leave exactly
        // those neighbours unseen, and the slot next to them wrongly offered.
        $pad      = max( $before, $after, $this->widestBuffer() );
        $from     = $dayStart->subMinutes( $pad );
        $until    = $dayEnd->addMinutes( $pad );
        $bookings = $this->bookingsFor( $service, $provider, $date, $from, $until );
        $busy     = $this->busyBlocksFor( $provider, $from, $until );

        $slots = [];

        for ( $minutes = 0; $minutes < 1440; $minutes += $interval ) {
            $start = $this->localInstant(
                $date,
                sprintf( '%02d:%02d:00', intdiv( $minutes, 60 ), $minutes % 60 ),
                $timezone,
            );

            // The local time does not exist on this date — the clocks went
            // forward over it. There is no instant to offer.
            if ( null === $start ) {
                continue;
            }

            $end = $start->addMinutes( $duration );

            if ( ! $this->fitsAWindow( $start, $end, $windows ) ) {
                continue;
            }

            $occupied = new TimeRange( $start, $end );
            $reserved = 0 === $before && 0 === $after
                ? $occupied
                : new TimeRange( $start->subMinutes( $before ), $end->addMinutes( $after ) );

            if ( ! $this->isFree( $occupied, $reserved, $bookings, $busy ) ) {
                continue;
            }

            $slots[] = [
                'start' => $start->utc()->toIso8601String(),
                'end'   => $end->utc()->toIso8601String(),
            ];
        }

        return $slots;
    }

    /**
     * Gets the spans a provider is available in on a date, as instants.
     *
     * An override outranks the weekly schedule, which is the point of having
     * one: a day off closes the date outright, and custom hours replace the
     * usual window rather than adding to it.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider being resolved.
     * @param  string  $date  The provider-local date, as `Y-m-d`.
     * @param  string  $timezone  The provider's timezone.
     *
     * @return array<int, TimeRange> The windows the provider works in.
     */
    protected function availabilityWindows( ServiceProvider $provider, string $date, string $timezone ): array
    {
        $overrides   = AvailabilityOverride::query()->for( $provider, $date )->get();
        $overridden  = false;
        $windows     = [];

        foreach ( $overrides as $override ) {
            if ( $override->isUnavailable() ) {
                return [];
            }

            // Set before the row is read, not after. A custom-hours override
            // with a time missing is a broken row, and falling back to the
            // weekly schedule would quietly work the provider on a day they
            // said they were doing something else with.
            $overridden = true;
            $start      = $override->startsAt( $timezone );
            $end        = $override->endsAt( $timezone );

            if ( null !== $start && null !== $end && $end->greaterThan( $start ) ) {
                $windows[] = new TimeRange( $start, $end );
            }
        }

        if ( $overridden ) {
            return $windows;
        }

        $dayOfWeek = CarbonImmutable::parse( $date, $timezone )->dayOfWeek;

        $schedules = AvailabilitySchedule::query()
            ->for( $provider, $dayOfWeek, $date )
            ->available()
            ->get();

        foreach ( $schedules as $schedule ) {
            $start = $schedule->startsAtOn( $date, $timezone );
            $end   = $schedule->endsAtOn( $date, $timezone );

            // A window that does not end after it starts is a row somebody has
            // to fix, not a slot; overnight hours are two rows, not one.
            if ( $end->greaterThan( $start ) ) {
                $windows[] = new TimeRange( $start, $end );
            }
        }

        return $windows;
    }

    /**
     * Gets the bookings that could clash with a provider's day.
     *
     * `ap.bookings.availabilityQuery` runs here, on the query rather than on its
     * results, so a subscriber can widen or narrow what counts as taken without
     * this method having to anticipate why.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider  $provider  The provider being resolved.
     * @param  string  $date  The provider-local date, as `Y-m-d`.
     * @param  CarbonImmutable  $from  The earliest instant that could clash.
     * @param  CarbonImmutable  $until  The latest instant that could clash.
     *
     * @throws UnexpectedValueException When a subscriber returns something other
     *                                  than a query builder.
     *
     * Each booking comes back twice: the span it actually occupies, and that
     * span widened by the buffers its own service asks for. Both are needed,
     * because a gap between two appointments has two parties with an opinion
     * about it and the one that wins is whichever asks for more —
     * see {@see self::isFree()}.
     *
     * The service is eager-loaded rather than read per row: without it this is a
     * query per booking, on the hot path of the widget.
     *
     * @return array<int, array{occupied: TimeRange, padded: TimeRange}> The spans the bookings hold.
     */
    protected function bookingsFor(
        Service $service,
        ServiceProvider $provider,
        string $date,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): array {
        // Bound in UTC, matching the columns. Eloquent formats a bound through
        // whatever zone the instance is carrying, so a Carbon still in the
        // provider's zone would compare a local clock face against stored UTC
        // and silently move the window by the offset.
        $query = Booking::query()
            ->active()
            ->with( 'service' )
            ->where( 'provider_id', $provider->getKey() )
            ->where( 'start_time', '<', $until->utc() )
            ->where( 'end_time', '>', $from->utc() );

        $criteria = [
            'service_id'  => (int) $service->getKey(),
            'provider_id' => (int) $provider->getKey(),
            'date'        => $date,
            'timezone'    => $provider->timezone,
            'from'        => $from->utc()->toIso8601String(),
            'until'       => $until->utc()->toIso8601String(),
        ];

        $filtered = applyFilters( 'ap.bookings.availabilityQuery', $query, $criteria );

        if ( ! $filtered instanceof Builder ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.availabilityQuery must return %s, got %s.',
                Builder::class,
                get_debug_type( $filtered ),
            ) );
        }

        return $filtered->get()
            ->map( static function ( Booking $booking ): array {
                $occupied = new TimeRange( $booking->start_time, $booking->end_time );

                // A booking whose service has been deleted out from under it
                // keeps the time it occupies and loses its claim to a gap. There
                // is nothing left to read a buffer off, and inventing one would
                // suppress slots for a reason nobody can look up.
                $before = max( 0, (int) ( $booking->service->buffer_before ?? 0 ) );
                $after  = max( 0, (int) ( $booking->service->buffer_after ?? 0 ) );

                return [
                    'occupied' => $occupied,
                    'padded'   => 0 === $before && 0 === $after
                        ? $occupied
                        : new TimeRange( $occupied->start->subMinutes( $before ), $occupied->end->addMinutes( $after ) ),
                ];
            } )
            ->all();
    }

    /**
     * Gets the largest buffer any service asks for, in minutes.
     *
     * One aggregate rather than a join, and only on the path that recomputes a
     * day — a cached day never asks. It bounds how far outside a day a
     * neighbouring appointment can still reach in, which is the only thing the
     * fetch window needs from it.
     *
     * @since 1.0.0
     *
     * @return int The widest buffer configured, or zero when none is.
     */
    protected function widestBuffer(): int
    {
        /** @var object{before: int|null, after: int|null}|null $widest */
        $widest = Service::query()
            ->selectRaw( 'max(buffer_before) as before, max(buffer_after) as after' )
            ->first();

        return max( 0, (int) ( $widest->before ?? 0 ), (int) ( $widest->after ?? 0 ) );
    }

    /**
     * Gets the spans an external calendar reports the provider as busy in.
     *
     * Only connections that read busy blocks back are consulted. A one-way
     * connection may well have rows behind it from before its mode changed, and
     * letting those suppress availability would hand a calendar a power the
     * operator explicitly did not grant it.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider being resolved.
     * @param  CarbonImmutable  $from  The earliest instant that could clash.
     * @param  CarbonImmutable  $until  The latest instant that could clash.
     *
     * @return array<int, TimeRange> The busy spans.
     */
    protected function busyBlocksFor( ServiceProvider $provider, CarbonImmutable $from, CarbonImmutable $until ): array
    {
        $connectionIds = $provider->calendarConnections()->twoWay()->pluck( 'id' )->all();

        if ( [] === $connectionIds ) {
            return [];
        }

        return CalendarBusyBlock::query()
            ->overlapping( $connectionIds, $from, $until )
            ->get()
            ->map( static fn ( CalendarBusyBlock $block ): TimeRange => new TimeRange(
                $block->starts_at_utc,
                $block->ends_at_utc,
            ) )
            ->all();
    }

    /**
     * Determines whether a candidate slot sits inside one of the day's windows.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable  $start  When the candidate starts.
     * @param  CarbonImmutable  $end  When the candidate ends.
     * @param  array<int, TimeRange>  $windows  The windows the provider works in.
     *
     * @return bool True when the whole candidate fits one window.
     */
    protected function fitsAWindow( CarbonImmutable $start, CarbonImmutable $end, array $windows ): bool
    {
        foreach ( $windows as $window ) {
            if ( $start->greaterThanOrEqualTo( $window->start ) && $end->lessThanOrEqualTo( $window->end ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether nothing already holds the time a candidate needs.
     *
     * A gap between two appointments has two parties with a claim on it: the
     * candidate's service wants its own buffer, and the appointment already
     * there wants the buffer *its* service asks for. Both are checked, each
     * against the other's occupied time rather than against the other's padding
     * — so the gap that survives is the larger of the two claims rather than
     * their sum, which is what a single expanded-versus-expanded comparison
     * would quietly demand.
     *
     * The asymmetric case is the one that makes this necessary: a service with
     * no buffer before and fifteen minutes after would otherwise offer a slot
     * starting the instant an earlier appointment of the same service ended,
     * because nothing on the candidate's side was asking for that gap.
     *
     * External busy blocks get the candidate's buffers and nothing more. They
     * come from somebody else's calendar and carry no service to ask.
     *
     * `max_bookings_per_slot` is deliberately not consulted. The partial unique
     * index on `bookings` admits one active booking per provider and start time,
     * so a second seat in the same sitting cannot be written at all — reporting a
     * group slot as still free would offer a booking the database then refuses.
     * Seating a group needs an attendee row rather than a second booking, and
     * that does not exist yet; when it does, this is where capacity is read.
     *
     * @since 1.0.0
     *
     * @param  TimeRange  $occupied  The time the candidate itself would take.
     * @param  TimeRange  $reserved  The candidate widened by its own buffers.
     * @param  array<int, array{occupied: TimeRange, padded: TimeRange}>  $bookings  The bookings holding time.
     * @param  array<int, TimeRange>  $busy  The externally reported busy spans.
     *
     * @return bool True when the candidate can still be offered.
     */
    protected function isFree( TimeRange $occupied, TimeRange $reserved, array $bookings, array $busy ): bool
    {
        foreach ( $busy as $block ) {
            if ( $reserved->overlaps( $block ) ) {
                return false;
            }
        }

        foreach ( $bookings as $booking ) {
            if ( $reserved->overlaps( $booking['occupied'] ) || $occupied->overlaps( $booking['padded'] ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gets how long a slot of this service runs for, in minutes.
     *
     * A provider can be attached at a duration of their own — a senior clinician
     * who needs longer for the same appointment — and `ap.bookings.slotDuration`
     * is the seam for everything neither column can express.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider  $provider  The provider being resolved.
     *
     * @throws UnexpectedValueException When a subscriber returns something that
     *                                  is not a positive number of minutes.
     *
     * @return int The effective slot length.
     */
    protected function slotDuration( Service $service, ServiceProvider $provider ): int
    {
        $minutes = (int) $service->duration;
        $custom  = $this->customDuration( $service, $provider );

        if ( null !== $custom ) {
            $minutes = $custom;
        }

        $filtered = applyFilters( 'ap.bookings.slotDuration', $minutes, $service, $provider );

        if ( ! is_int( $filtered ) || $filtered < 1 ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.slotDuration must return a positive integer number of minutes, got %s.',
                get_debug_type( $filtered ),
            ) );
        }

        return $filtered;
    }

    /**
     * Gets the duration a provider is attached to a service at, if any.
     *
     * Read off the pivot when the provider arrived through the relationship, and
     * queried when they did not — a caller who resolved a provider on their own
     * and handed it in must not silently get the service's default length
     * instead of the one the attachment actually says.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider  $provider  The provider being resolved.
     *
     * @return int|null The attachment's own duration in minutes, or null.
     */
    protected function customDuration( Service $service, ServiceProvider $provider ): ?int
    {
        $pivot = $provider->getRelationValue( 'pivot' );

        $custom = $pivot instanceof ServiceProviderService
            ? $pivot->custom_duration
            : ServiceProviderService::query()
                ->where( 'service_id', $service->getKey() )
                ->where( 'provider_id', $provider->getKey() )
                ->value( 'custom_duration' );

        return null === $custom || (int) $custom < 1 ? null : (int) $custom;
    }

    /**
     * Drops the slots a `ap.bookings.slotBookable` subscriber vetoes.
     *
     * Run per call rather than per computation, because the customer is part of
     * the question and the cache entry is shared between all of them.
     *
     * @since 1.0.0
     *
     * @param  array<int, Slot>  $slots  The slots to validate.
     *
     * @throws UnexpectedValueException When a subscriber returns something other
     *                                  than a boolean.
     *
     * @return array<int, Slot> The slots that survived.
     */
    protected function bookableSlots( array $slots ): array
    {
        $customer = $this->currentCustomer();
        $bookable = [];

        foreach ( $slots as $slot ) {
            $verdict = applyFilters( 'ap.bookings.slotBookable', true, $slot, $customer );

            if ( ! is_bool( $verdict ) ) {
                throw new UnexpectedValueException( sprintf(
                    'ap.bookings.slotBookable must return a boolean, got %s.',
                    get_debug_type( $verdict ),
                ) );
            }

            if ( $verdict ) {
                $bookable[] = $slot;
            }
        }

        return $bookable;
    }

    /**
     * Runs the resolved slots through `ap.bookings.availableSlots`.
     *
     * @since 1.0.0
     *
     * @param  array<int, Slot>  $slots  The slots about to be returned.
     * @param  ServiceProvider  $provider  The provider they belong to.
     * @param  TimeRange  $window  The window they were resolved over.
     *
     * @throws UnexpectedValueException When a subscriber returns something other
     *                                  than an array of slots.
     *
     * @return array<int, Slot> The filtered slots, ascending by start.
     */
    protected function filterAvailableSlots( array $slots, ServiceProvider $provider, TimeRange $window ): array
    {
        $filtered = applyFilters(
            'ap.bookings.availableSlots',
            $slots,
            $provider,
            CarbonPeriod::create( $window->start, $window->end ),
        );

        if ( ! is_array( $filtered ) ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.availableSlots must return an array, got %s.',
                get_debug_type( $filtered ),
            ) );
        }

        foreach ( $filtered as $slot ) {
            if ( ! $slot instanceof Slot ) {
                throw new UnexpectedValueException( sprintf(
                    'ap.bookings.availableSlots must return %s instances, got %s.',
                    Slot::class,
                    get_debug_type( $slot ),
                ) );
            }
        }

        // Re-sorted rather than trusted: a subscriber that appends a slot has
        // every reason to append it at the end of the array and none to work out
        // where it belongs in time.
        return $this->sorted( array_values( $filtered ) );
    }

    /**
     * Gets the customer the slots are being resolved for, if anybody is signed in.
     *
     * The container is checked rather than assumed, because a queue worker or a
     * console command resolving availability has no guard to ask.
     *
     * @since 1.0.0
     *
     * @return Authenticatable|null The signed-in user, or null.
     */
    protected function currentCustomer(): ?Authenticatable
    {
        $container = Container::getInstance();

        if ( ! $container->bound( 'auth' ) ) {
            return null;
        }

        return $container->make( 'auth' )->guard()->user();
    }

    /**
     * Gets the provider-local dates a window touches.
     *
     * @since 1.0.0
     *
     * @param  TimeRange  $window  The window being resolved.
     * @param  string  $timezone  The provider's timezone.
     *
     * @return array<int, string> The dates, as `Y-m-d`, ascending.
     */
    protected function localDatesSpanning( TimeRange $window, string $timezone ): array
    {
        $cursor = $window->start->setTimezone( $timezone )->startOfDay();
        $dates  = [];

        // Strictly before the window closes, because the window is half-open. A
        // window of one whole local day ends at the next midnight, and taking
        // that date too would compute and cache a second day whose every slot is
        // then discarded for falling outside the window — doubling the cold cost
        // of the single-day query the widget makes most.
        while ( $cursor->lessThan( $window->end ) ) {
            $dates[] = $cursor->toDateString();
            $cursor  = $cursor->addDay()->startOfDay();
        }

        return $dates;
    }

    /**
     * Turns a date and a clock face into the instant they name, if there is one.
     *
     * Carbon does not object to a local time that never happened: ask it for
     * 02:30 on the morning the clocks go forward and it hands back 03:30 without
     * comment. Reading the clock face back off the result is what catches that,
     * and a slot that cannot exist is dropped rather than silently moved.
     *
     * @since 1.0.0
     *
     * @param  string  $date  The date, as `Y-m-d`.
     * @param  string  $wallClock  The clock face, as `H:i:s`.
     * @param  string  $timezone  The zone to read the clock face in.
     *
     * @return CarbonImmutable|null The instant, or null when the local time does
     *                              not exist on that date.
     */
    protected function localInstant( string $date, string $wallClock, string $timezone ): ?CarbonImmutable
    {
        $instant = CarbonImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . $wallClock, $timezone );

        return null !== $instant && $instant->format( 'H:i:s' ) === $wallClock ? $instant : null;
    }

    /**
     * Sorts slots into ascending start order.
     *
     * @since 1.0.0
     *
     * @param  array<int, Slot>  $slots  The slots to sort.
     *
     * @return array<int, Slot> The sorted slots.
     */
    protected function sorted( array $slots ): array
    {
        usort( $slots, static function ( Slot $first, Slot $second ): int {
            return $first->period->start->getTimestamp() <=> $second->period->start->getTimestamp()
                ?: $first->period->end->getTimestamp() <=> $second->period->end->getTimestamp()
                ?: ( $first->providerId ?? 0 ) <=> ( $second->providerId ?? 0 );
        } );

        return $slots;
    }

    /**
     * Gets the key a cached day is stored under.
     *
     * Every stamp the answer depends on is in the key rather than consulted
     * beside it, so a bump makes the old entry unreachable instead of leaving it
     * to be found and then discarded.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider  $provider  The provider being resolved.
     * @param  string  $date  The provider-local date, as `Y-m-d`.
     *
     * @return string The cache key.
     */
    protected function cacheKey( Service $service, ServiceProvider $provider, string $date ): string
    {
        $site = BelongsToSiteScope::currentSiteId();

        return sprintf(
            '%s.day.%s.%d.%d.%s.g%d.s%d.p%d',
            self::CACHE_PREFIX,
            // Hashed rather than interpolated. A site identifier is only typed
            // as int|string, so it is the one component of this key whose shape
            // the package does not control — and a key is handed to a store that
            // does have opinions about it. Memcached rejects spaces and control
            // characters outright, and Laravel does not sanitise on the way
            // through, so a tenant keyed by a slug with a space in it takes the
            // whole availability cache down for that site. Fixed-width and
            // alphanumeric sidesteps every store's rules at once.
            //
            // Not a collision guard: the two fields after this one are the
            // service and the provider, and the date after those is fixed-shape,
            // so the key still parses unambiguously from the right however many
            // dots a site puts in.
            null === $site ? 'global' : hash( 'sha256', (string) $site ),
            (int) $service->getKey(),
            (int) $provider->getKey(),
            $date,
            $this->stamp( self::CACHE_PREFIX . '.stamp.global' ),
            $this->stamp( self::CACHE_PREFIX . '.stamp.service.' . $service->getKey() ),
            $this->stamp( self::CACHE_PREFIX . '.stamp.provider.' . $provider->getKey() ),
        );
    }

    /**
     * Gets a key that identifies a period, for collapsing duplicates.
     *
     * @since 1.0.0
     *
     * @param  TimeRange  $period  The period to identify.
     *
     * @return string The period key.
     */
    protected function periodKey( TimeRange $period ): string
    {
        $bounds = $period->toArray();

        return $bounds['start'] . '/' . $bounds['end'];
    }

    /**
     * Reads a stamp.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The stamp to read.
     *
     * @return int The current stamp value.
     */
    protected function stamp( string $key ): int
    {
        return (int) $this->cache->get( $key, 0 );
    }

    /**
     * Moves a stamp on.
     *
     * Seeded with `add()` before being incremented because an increment against
     * a key that was never written is not portable: some stores treat it as
     * zero, others refuse it and report false, and a stamp that silently failed
     * to move would serve stale availability for the whole TTL.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The stamp to bump.
     *
     * @return void
     */
    protected function bump( string $key ): void
    {
        $this->cache->add( $key, 0 );

        if ( false === $this->cache->increment( $key ) ) {
            $this->cache->forever( $key, $this->stamp( $key ) + 1 );
        }
    }
}
