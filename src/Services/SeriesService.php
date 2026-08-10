<?php

/**
 * Series service.
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

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\BookingAssignmentStrategy;
use ArtisanPackUI\Bookings\Enums\SeriesEditScope;
use ArtisanPackUI\Bookings\Events\SeriesCancelled;
use ArtisanPackUI\Bookings\Events\SeriesCreated;
use ArtisanPackUI\Bookings\Events\SeriesEdited;
use ArtisanPackUI\Bookings\Exceptions\SeriesException;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Core\MultiTenancy\SiteContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;

use function array_intersect_key;
use function array_key_exists;
use function array_merge;
use function count;
use function doAction;
use function is_string;
use function max;
use function sprintf;
use function trim;

/**
 * The one place a recurring arrangement is decided.
 *
 * A recurring booking is two things at once, and the split is the whole design.
 * The **rule** — `rrule`, `dtstart_local`, `dtstart_timezone` — is the source of
 * truth; the **occurrences** are ordinary bookings materialised from it. Neither
 * alone is enough. Without materialised rows the admin filters, the calendar
 * push, and the reminder cron would each have to expand recurrence rules for
 * themselves, and three implementations of RFC 5545 in one package is three
 * chances to disagree about what a customer booked. Without the rule, a "this
 * and all following" edit could only approximate the arrangement by pattern-
 * matching the rows it produced.
 *
 * The start is stored as a **floating** local date-time rather than as an
 * instant, and that is what makes the rule survive daylight saving. A customer
 * who books a weekly 15:00 coaching call means 15:00 every week — not the UTC
 * instant that happened to be 15:00 in March. Expansion therefore runs in the
 * series' own zone and only converts to UTC at the end, so the occurrences
 * either side of a clock change land an hour apart in UTC and on the same clock
 * face locally, which is the answer the customer would give.
 *
 * **Occurrences are materialised one at a time through {@see BookingService}**,
 * never written directly, so every one of them goes through the slot lock, the
 * availability check, the intake validation, and the `ap.bookings.*` lifecycle
 * hooks exactly as a single booking does. A twelve-week series fires twelve
 * `created` actions — once per booking that exists — and no more.
 *
 * **An occurrence whose slot has gone is skipped, not fatal.** A rule expanded
 * over three months will cross somebody's holiday sooner or later, and refusing
 * the whole arrangement because week seven is taken serves nobody. The series is
 * created with the occurrences that could be booked, and {@see SeriesCreated}
 * carries how many that was, so a caller comparing it against
 * {@see self::expand()} can tell the customer which weeks did not land. Only a
 * rule where *nothing* could be booked is refused outright.
 *
 * **A series is pinned to one provider.** Recurring means the same person: a
 * weekly 1:1 that round-robined a different coach each week would be a different
 * product. When the caller names a provider, every occurrence goes to them; when
 * they do not, the first occurrence is assigned by the usual rota and the rest
 * follow it, which is written back onto the series row.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SeriesService
{
    /**
     * The changes a rule-touching edit is allowed to make to the series.
     *
     * An allow-list rather than a straight `fill()`, because `$changes` reaches
     * this from an admin form and the columns it must not carry — `site_id`,
     * `pii_erased_at`, `cancelled_at`, the primary key — are exactly the ones a
     * request has no business setting.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected const SERIES_FIELDS = [
        'provider_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'rrule',
        'dtstart_local',
        'dtstart_timezone',
        'until_local',
        'metadata',
    ];

    /**
     * The changes a single-occurrence edit is allowed to make to the booking.
     *
     * `start_time` is deliberately absent: moving an occurrence goes through
     * {@see BookingService::reschedule()} so that it takes the slot lock and
     * fires the reschedule hooks, rather than being written over the top of
     * whoever else is holding the new time.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected const OCCURRENCE_FIELDS = [
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_timezone',
        'notes',
    ];

    /**
     * Constructs the service.
     *
     * @since 1.0.0
     *
     * @param  BookingService  $bookings  The service every occurrence is written through.
     * @param  ConfigRepository  $config  The application configuration.
     */
    public function __construct(
        protected BookingService $bookings,
        protected ConfigRepository $config,
    ) {
    }

    /**
     * Creates a recurring series and materialises its occurrences.
     *
     * Accepts `service` or `service_id`, an optional `provider` or
     * `provider_id`, an `rrule`, a `dtstart_local` and `dtstart_timezone`, an
     * optional `until_local`, the customer's details, and `intake_data` — the
     * booking attributes {@see BookingService::create()} takes, plus the rule.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The series to create.
     * @param  Authenticatable|null  $customer  The signed-in customer, when there
     *                                          is one. Passed through to each
     *                                          occurrence's `ap.bookings.creating`.
     *
     * @throws InvalidArgumentException When the attributes do not name a service,
     *                                  a rule, and a start.
     * @throws SeriesException When the rule cannot be read or names nothing.
     * @throws SlotUnavailableException When not one occurrence could be booked.
     *
     * @return BookingSeries The series, with its occurrences materialised.
     */
    public function create( array $attributes, ?Authenticatable $customer = null ): BookingSeries
    {
        $service  = $this->resolveService( $attributes );
        $rrule    = $this->requiredRule( $attributes );
        $timezone = $this->resolveTimezone( $attributes, $service );
        $dtstart  = $this->floating( $attributes['dtstart_local'] ?? throw new InvalidArgumentException(
            'A series must name a dtstart_local.',
        ) );
        $until    = array_key_exists( 'until_local', $attributes ) && null !== $attributes['until_local']
            ? $this->floating( $attributes['until_local'] )
            : null;

        $instants = $this->expand( $rrule, $dtstart, $timezone, $until );

        /** @var BookingSeries $series */
        $series = BookingSeries::query()->create( [
            'service_id'            => (int) $service->getKey(),
            'provider_id'           => $this->namedProviderId( $attributes ),
            'customer_name'         => $this->requiredString( $attributes, 'customer_name' ),
            'customer_email'        => $this->requiredString( $attributes, 'customer_email' ),
            'customer_phone'        => $attributes['customer_phone'] ?? null,
            'rrule'                 => $rrule,
            'dtstart_local'         => $dtstart,
            'dtstart_timezone'      => $timezone,
            'until_local'           => $until,
            // Only ever the count the *rule* declares. An `UNTIL`-bounded or
            // unbounded rule genuinely does not have one, and filling this in
            // from the expansion would make a column that means "what the rule
            // says" quietly start meaning "what we managed to book this time".
            'occurrence_count'      => $this->declaredCount( $rrule ),
            'intake_schema_version' => (int) $service->intake_schema_version,
            'metadata'              => $attributes['metadata'] ?? null,
        ] );

        try {
            $created = $this->materialise( $series, $instants, 0, $attributes, $customer );
        } catch ( Exception $exception ) {
            // A provider who does not offer the service, intake answers the form
            // refuses — caller mistakes that surface partway through, after some
            // occurrences have already been written. Letting them through would
            // leave the orphans this method is otherwise careful not to write.
            $this->discard( $series );

            throw $exception;
        }

        if ( [] === $created ) {
            // Nothing was booked, so there is no arrangement — and a series row
            // with no occurrences is worse than none at all: it shows up in the
            // admin list as a recurring booking nobody can find the appointments
            // for.
            $this->discard( $series );

            throw SlotUnavailableException::for( $service, $instants[0] );
        }

        SeriesCreated::dispatch( $series, count( $created ) );

        return $series;
    }

    /**
     * Applies an edit to a series, as far through it as the scope reaches.
     *
     * The three scopes are the three choices every calendar application offers,
     * and they do genuinely different things to the data. See
     * {@see SeriesEditScope} for what each one means; in short, `this` detaches
     * one occurrence and leaves the rule alone, `this_and_following` bounds the
     * rule at that occurrence and carries the change forward on a new one, and
     * `all` rewrites the rule and re-materialises everything still to come.
     *
     * `$changes` is read against the scope. A rule-touching scope reads series
     * columns from it — `rrule`, `dtstart_local`, `provider_id`, the customer's
     * details — and `this` reads booking columns, plus a `start_time` to move
     * the single occurrence to.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series being edited.
     * @param  SeriesEditScope  $scope  How far through it the edit reaches.
     * @param  array<string, mixed>  $changes  What to change.
     * @param  Booking|null  $occurrence  The occurrence the edit starts from.
     *                                    Required for every scope but `all`.
     * @param  BookingActor  $actor  Who made the edit.
     *
     * @throws SeriesException When the occurrence is missing or belongs to
     *                         another series, or the new rule cannot be read.
     *
     * @return BookingSeries The series carrying the arrangement forward — the
     *                       original, or the tail when the edit split it in two.
     */
    public function edit(
        BookingSeries $series,
        SeriesEditScope $scope,
        array $changes,
        ?Booking $occurrence = null,
        BookingActor $actor = BookingActor::System,
    ): BookingSeries {
        // A cancelled series is not a series any more, and an edit that
        // re-materialises one would hand the provider appointments for an
        // arrangement every screen in the system reports as called off. The way
        // in is ordinary: an admin with the edit form already open when the
        // customer cancels it themselves.
        if ( $series->isCancelled() ) {
            throw SeriesException::alreadyCancelled( $series );
        }

        if ( SeriesEditScope::All !== $scope ) {
            if ( ! $occurrence instanceof Booking ) {
                throw SeriesException::scopeNeedsAnOccurrence( $scope );
            }

            if ( (int) $occurrence->series_id !== (int) $series->getKey() ) {
                throw SeriesException::occurrenceNotInSeries( $occurrence, $series );
            }
        }

        $split = $this->inSiteOf( $series, function () use ( $series, $scope, $changes, $occurrence, $actor ): ?BookingSeries {
            // Fired inside the pin, so a subscriber that reads the series back
            // does so under the series' own site rather than the ambient one —
            // a `fresh()` from the wrong site returns null, and a subscriber
            // written against the documented payload would fatal on it. Still
            // the first thing the closure does, so the rule it sees is the one
            // about to be replaced.
            doAction( 'ap.bookings.series.editApplying', $series, $scope->value, $changes );

            if ( SeriesEditScope::This === $scope ) {
                $this->editOccurrence( $occurrence, $changes, $actor );

                return null;
            }

            if ( SeriesEditScope::ThisAndFollowing === $scope ) {
                return $this->splitSeries( $series, $occurrence, $changes, $actor );
            }

            $this->rewriteSeries( $series, $changes, $actor );

            return null;
        } );

        SeriesEdited::dispatch( $series, $scope, $actor, $split );

        return $split ?? $series;
    }

    /**
     * Calls off a whole arrangement, along with everything still to come.
     *
     * Occurrences that have already started are left exactly as they are — they
     * happened, and rewriting history to tidy up a cancellation would lose the
     * record of work that was actually delivered. Detached occurrences *are*
     * cancelled: somebody moved one of them to a different afternoon, which does
     * not make it any less part of the arrangement being called off.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series to cancel.
     * @param  BookingActor  $actor  Who cancelled it.
     * @param  string|null  $reason  Why, when a reason was given.
     *
     * @throws SeriesException When the series has already been cancelled.
     *
     * @return BookingSeries The cancelled series.
     */
    public function cancel( BookingSeries $series, BookingActor $actor, ?string $reason = null ): BookingSeries
    {
        if ( $series->isCancelled() ) {
            throw SeriesException::alreadyCancelled( $series );
        }

        $cancelled = $this->inSiteOf(
            $series,
            fn (): int => $this->cancelOccurrencesFrom( $series, CarbonImmutable::now(), $actor, $reason, false ),
        );

        $series->forceFill( [ 'cancelled_at' => $series->freshTimestamp() ] )->save();

        SeriesCancelled::dispatch( $series, $actor, $cancelled, $reason );

        return $series;
    }

    /**
     * Expands a recurrence rule into the instants it names.
     *
     * Runs entirely in the rule's own zone and converts to UTC last, so a weekly
     * rule spanning a clock change keeps its local clock face rather than its
     * UTC offset. Bounded three ways, and the earliest bound wins: the rule's own
     * `COUNT` or `UNTIL`, the floating `$untilLocal` the series carries, and the
     * `artisanpack.bookings.series.max_occurrences` cap — which is what stops an
     * unbounded `FREQ=DAILY` from asking for an unbounded number of rows.
     *
     * @since 1.0.0
     *
     * @param  string  $rrule  The RFC 5545 recurrence rule.
     * @param  CarbonInterface  $dtstartLocal  The floating start — a clock face,
     *                                         not an instant.
     * @param  string  $timezone  The IANA zone that clock face is read in.
     * @param  CarbonInterface|null  $untilLocal  A floating upper bound, if the
     *                                            series carries one.
     * @param  int|null  $limit  How many occurrences at most, or null for the
     *                           configured cap.
     *
     * @throws InvalidArgumentException When the timezone is not one PHP knows.
     * @throws SeriesException When the rule cannot be read, or names nothing.
     *
     * @return array<int, CarbonImmutable> The occurrence instants, in UTC.
     */
    public function expand(
        string $rrule,
        CarbonInterface $dtstartLocal,
        string $timezone,
        ?CarbonInterface $untilLocal = null,
        ?int $limit = null,
    ): array {
        $cap = max( 1, $limit ?? $this->maxOccurrences() );

        // Built before the rule, and reported separately. An unknown zone and an
        // unparseable rule are different mistakes, and telling somebody their
        // RRULE is malformed when what they actually mistyped is
        // "America/Chicgao" sends them looking in the wrong place.
        try {
            $zone = new DateTimeZone( $timezone );
        } catch ( Exception $exception ) {
            throw new InvalidArgumentException(
                sprintf( '"%s" is not a timezone this system knows.', $timezone ),
                0,
                $exception,
            );
        }

        try {
            $start = new DateTimeImmutable( $dtstartLocal->format( 'Y-m-d H:i:s' ), $zone );
            $rule  = new Rule( $rrule, $start, null, $timezone );
        } catch ( Exception $exception ) {
            throw SeriesException::invalidRule( $rrule, $exception );
        }

        $config = new ArrayTransformerConfig();
        $config->setVirtualLimit( $cap );

        // The upper bound is compared as an instant rather than applied to the
        // rule, because `Rule::setUntil()` clears `COUNT` — so a rule that says
        // twelve times and a series that says "not past December" would lose the
        // twelve. Filtering afterwards makes the earlier bound win, which is
        // what a reader of both would expect.
        $until    = null === $untilLocal
            ? null
            : CarbonImmutable::instance( new DateTimeImmutable( $untilLocal->format( 'Y-m-d H:i:s' ), $zone ) )->utc();
        $instants = [];

        foreach ( ( new ArrayTransformer( $config ) )->transform( $rule ) as $recurrence ) {
            $instant = CarbonImmutable::instance( $recurrence->getStart() )->utc();

            if ( null !== $until && $instant->greaterThan( $until ) ) {
                break;
            }

            $instants[] = $instant;

            if ( count( $instants ) >= $cap ) {
                break;
            }
        }

        if ( [] === $instants ) {
            throw SeriesException::producedNoOccurrences( $rrule );
        }

        return $instants;
    }

    /**
     * Expands the rule a series is currently carrying.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series to expand.
     *
     * @throws SeriesException When the rule cannot be read, or names nothing.
     *
     * @return array<int, CarbonImmutable> The occurrence instants, in UTC.
     */
    public function expandSeries( BookingSeries $series ): array
    {
        return $this->expand(
            (string) $series->rrule,
            $series->dtstart_local,
            (string) $series->dtstart_timezone,
            $series->until_local,
        );
    }

    /**
     * Writes the occurrences a set of instants names.
     *
     * The index advances for every instant considered, including the ones whose
     * slot had gone, so a position within one materialisation run still maps to
     * a position in the rule's expansion — a series missing week seven has a gap
     * at seven rather than a silent renumbering that makes week eight look like
     * week seven.
     *
     * Deliberately not wrapped in a transaction. `BookingService::create()`
     * dispatches `BookingRequested` after commit, which is where a listener gets
     * to veto a booking by cancelling it; an outer transaction would push every
     * one of those listeners past the end of the whole materialisation, so they
     * would be cancelling confirmed bookings rather than preventing them. It also
     * means a slot lost in week seven costs week seven rather than the series.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series being materialised.
     * @param  array<int, CarbonImmutable>  $instants  The instants to book.
     * @param  int  $firstIndex  The series index the first occurrence takes.
     * @param  array<string, mixed>  $attributes  The customer details and intake
     *                                            answers every occurrence shares.
     * @param  Authenticatable|null  $customer  The signed-in customer, if any.
     * @param  BookingSeries|null  $inheritFrom  The series to read the details
     *                                           the rule does not carry from,
     *                                           when it is not this one — the tail
     *                                           of a split has no occurrences of
     *                                           its own to copy the intake answers
     *                                           off yet, and the head does.
     *
     * @return array<int, Booking> The occurrences that were written.
     */
    protected function materialise(
        BookingSeries $series,
        array $instants,
        int $firstIndex,
        array $attributes,
        ?Authenticatable $customer = null,
        ?BookingSeries $inheritFrom = null,
    ): array {
        $base       = $this->occurrenceAttributes( $series, $attributes, $inheritFrom );
        $providerId = null === $series->provider_id ? null : (int) $series->provider_id;
        $created    = [];
        $index      = $firstIndex;

        foreach ( $instants as $instant ) {
            $booking = $this->materialiseOne( $series, $instant, $index, $providerId, $base, $customer );
            $index++;

            if ( ! $booking instanceof Booking ) {
                continue;
            }

            // Pin the rota's answer for the first occurrence onto the series, so
            // the rest of the run — and every later re-materialisation — goes to
            // the same person. A recurring booking that rotated coaches weekly
            // would be a different product from the one the customer bought.
            if ( null === $providerId && null !== $booking->provider_id ) {
                $providerId = (int) $booking->provider_id;

                $series->forceFill( [ 'provider_id' => $providerId ] )->save();
            }

            $created[] = $booking;
        }

        return $created;
    }

    /**
     * Writes one occurrence, or reports that its slot had gone.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series being materialised.
     * @param  CarbonImmutable  $instant  When the occurrence starts.
     * @param  int  $index  The position it takes in the series.
     * @param  int|null  $providerId  The provider the series is pinned to, if it
     *                                is pinned yet.
     * @param  array<string, mixed>  $base  The attributes every occurrence shares.
     * @param  Authenticatable|null  $customer  The signed-in customer, if any.
     *
     * @return Booking|null The occurrence, or null when nobody could take it.
     */
    protected function materialiseOne(
        BookingSeries $series,
        CarbonImmutable $instant,
        int $index,
        ?int $providerId,
        array $base,
        ?Authenticatable $customer,
    ): ?Booking {
        try {
            return $this->bookings->create( array_merge( $base, [
                'provider_id'  => $providerId,
                'start_time'   => $instant,
                'series_id'    => (int) $series->getKey(),
                'series_index' => $index,
            ] ), $customer );
        } catch ( SlotUnavailableException ) {
            return null;
        }
    }

    /**
     * Applies a `this` edit: one occurrence changes and stops following the rule.
     *
     * @since 1.0.0
     *
     * @param  Booking  $occurrence  The occurrence being edited.
     * @param  array<string, mixed>  $changes  What to change.
     * @param  BookingActor  $actor  Who made the edit.
     *
     * @throws SlotUnavailableException When the occurrence is being moved to a
     *                                  time somebody else already holds.
     *
     * @return void
     */
    protected function editOccurrence( Booking $occurrence, array $changes, BookingActor $actor ): void
    {
        // The move goes first, because it is the only part of this that can
        // fail. Somebody else can take the new time between the widget
        // rendering it and the form arriving, and `reschedule()` throws — so
        // doing it first means a lost race changes nothing at all, rather than
        // leaving the occurrence detached and its notes rewritten while it sits
        // at the time it always had, invisible to every later rewrite of the
        // rule and with no `SeriesEdited` to say so.
        if ( array_key_exists( 'start_time', $changes ) && null !== $changes['start_time'] ) {
            $this->bookings->reschedule( $occurrence, $this->instant( $changes['start_time'] ), $actor );
        }

        $occurrence->detachFromSeries();

        $fields = array_intersect_key( $changes, $this->keyed( self::OCCURRENCE_FIELDS ) );

        if ( [] !== $fields ) {
            $occurrence->forceFill( $fields )->save();
        }
    }

    /**
     * Applies a `this_and_following` edit: the series splits in two.
     *
     * The original rule is bounded just before the occurrence rather than
     * rewritten, so everything already delivered under it stays described by the
     * rule it was actually booked from. A new series picks up from the occurrence
     * carrying the change, and inherits the original's own upper bound — captured
     * before the original is bounded, since that is the end of the arrangement
     * rather than the end of its first half.
     *
     * **A `COUNT` is divided between the two halves, not handed to both.** The
     * tail starts a fresh `dtstart`, so an inherited `FREQ=WEEKLY;COUNT=12`
     * would start counting to twelve again — and a twelve-week package moved
     * from week five would quietly become sixteen sessions, billed or not. The
     * tail's count is the original less however many the head keeps. A caller
     * who supplies their own `rrule` is redefining the arrangement rather than
     * continuing it, and is left alone.
     *
     * **A split at the very first occurrence cancels the head rather than
     * bounding it.** There is nothing before the split to keep, and an `UNTIL`
     * one second before its own `DTSTART` is a rule that names nothing — which
     * would leave the head permanently unexpandable and every later edit of it
     * throwing.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series being split.
     * @param  Booking  $occurrence  The occurrence the tail starts from.
     * @param  array<string, mixed>  $changes  What the tail changes.
     * @param  BookingActor  $actor  Who made the edit.
     *
     * @throws SeriesException When the new rule cannot be read, or names nothing.
     * @throws SlotUnavailableException When the tail could book nothing at all.
     *
     * @return BookingSeries The tail carrying the arrangement forward.
     */
    protected function splitSeries(
        BookingSeries $series,
        Booking $occurrence,
        array $changes,
        BookingActor $actor,
    ): BookingSeries {
        $timezone  = (string) ( $changes['dtstart_timezone'] ?? $series->dtstart_timezone );
        $splitFrom = CarbonImmutable::instance( $occurrence->start_time )->utc();
        $kept      = $this->countBefore( $this->expandSeries( $series ), $splitFrom );

        $tail = $this->cloneSeries( $series, $changes, $timezone, $this->tailRule( $series, $changes, $kept ), [
            'dtstart_local' => $this->floating(
                $changes['dtstart_local'] ?? CarbonImmutable::instance( $occurrence->start_time )
                    ->setTimezone( $timezone ),
            ),
            // The original's own upper bound, read before the original is
            // bounded below — that is the end of the arrangement, whereas what
            // this method is about to write on the head is the end of its first
            // half.
            'until_local'   => array_key_exists( 'until_local', $changes )
                ? ( null === $changes['until_local'] ? null : $this->floating( $changes['until_local'] ) )
                : $series->until_local,
        ] );

        // Expanded before anything is cancelled. A tail whose rule turns out to
        // be unreadable has to fail with the original arrangement still standing,
        // rather than half of it called off and nothing put in its place.
        try {
            $instants = $this->expandSeries( $tail );
        } catch ( SeriesException $exception ) {
            $tail->forceDelete();

            throw $exception;
        }

        $this->cancelOccurrencesFrom( $series, $splitFrom, $actor, null, true );

        $series->forceFill(
            0 === $kept
                // Nothing before the split to describe. Cancelling says that
                // honestly; bounding would leave a rule that names no
                // occurrences and throws on every later expansion.
                ? [ 'cancelled_at' => $series->freshTimestamp() ]
                // A second before the split, so the boundary is unambiguous: the
                // occurrence the tail starts from must not also be named by the
                // head.
                : [
                    'until_local' => $this->floating(
                        CarbonImmutable::instance( $occurrence->start_time )
                            ->setTimezone( $series->dtstart_timezone )
                            ->subSecond(),
                    ),
                ],
        )->save();

        $created = $this->materialise( $tail, $instants, 0, $changes, null, $series );

        // The head's future is already released by this point, so there is no
        // undamaged state to return to — but an empty tail returned as "the
        // series carrying the arrangement forward" is a lie the caller would act
        // on. Better to remove it and say so.
        if ( [] === $created ) {
            $this->discard( $tail );

            throw SlotUnavailableException::for( $series->service, $instants[0] );
        }

        return $tail;
    }

    /**
     * Applies an `all` edit: the rule is rewritten and the future re-derived.
     *
     * Occurrences that have already started are left alone — the rule describes
     * what is still to come, and rewriting appointments that happened would be a
     * claim about the past nobody made. Detached occurrences are left alone too,
     * by definition: detaching one is the record that it is no longer described
     * by the rule, and re-materialising it would throw that edit away.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series being rewritten.
     * @param  array<string, mixed>  $changes  What to change.
     * @param  BookingActor  $actor  Who made the edit.
     *
     * @throws SeriesException When the new rule cannot be read, or names nothing.
     *
     * @return void
     */
    protected function rewriteSeries( BookingSeries $series, array $changes, BookingActor $actor ): void
    {
        $from = CarbonImmutable::now();

        // Filled but not saved, and expanded before either. A new rule that
        // turns out to be unreadable has to fail with the arrangement still
        // standing — saving first and expanding second would leave the series
        // carrying a rule nothing can derive occurrences from and the old
        // occurrences already called off.
        $series->forceFill( $this->seriesChanges( $changes ) );

        // Re-derived from `now` rather than from the rule's start, so an
        // arrangement halfway through does not try to rebook the weeks it has
        // already delivered — which the availability rules would refuse anyway,
        // but slowly, and once per past week.
        $future = [];

        foreach ( $this->expandSeries( $series ) as $instant ) {
            if ( $instant->greaterThan( $from ) ) {
                $future[] = $instant;
            }
        }

        $series->save();

        $this->cancelOccurrencesFrom( $series, $from, $actor, null, true );

        $created = $this->materialise( $series, $future, $series->nextSeriesIndex(), $changes );

        // A rule that named nothing still to come is a legitimate no-op — every
        // remaining occurrence may already have been cancelled by hand. A rule
        // that named occurrences and booked none of them is not: the caller has
        // just traded a working arrangement for an empty one, and returning
        // quietly would let them believe otherwise.
        if ( [] !== $future && [] === $created ) {
            throw SlotUnavailableException::for( $series->service, $future[0] );
        }
    }

    /**
     * Builds the tail of a split from the series it is splitting off.
     *
     * The tail inherits the head's site by virtue of the whole edit running in
     * it — see {@see self::inSiteOf()} — rather than by being stamped here.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series being split.
     * @param  array<string, mixed>  $changes  What the tail changes.
     * @param  string  $timezone  The zone the tail's clock faces are read in.
     * @param  string  $rrule  The rule the tail recurs on.
     * @param  array<string, mixed>  $bounds  The tail's start and upper bound.
     *
     * @return BookingSeries The new series.
     */
    protected function cloneSeries(
        BookingSeries $series,
        array $changes,
        string $timezone,
        string $rrule,
        array $bounds,
    ): BookingSeries {
        $allowed = $this->seriesChanges( $changes );

        /** @var BookingSeries $tail */
        $tail = BookingSeries::query()->create( array_merge( [
            'service_id'            => (int) $series->service_id,
            'provider_id'           => $series->provider_id,
            'customer_name'         => $series->customer_name,
            'customer_email'        => $series->customer_email,
            'customer_phone'        => $series->customer_phone,
            'rrule'                 => $rrule,
            'dtstart_timezone'      => $timezone,
            'occurrence_count'      => $this->declaredCount( $rrule ),
            'intake_schema_version' => (int) $series->intake_schema_version,
            'metadata'              => $series->metadata,
        ], array_intersect_key( $allowed, $this->keyed( [
            'customer_name',
            'customer_email',
            'customer_phone',
            'provider_id',
            'metadata',
        ] ) ), $bounds ) );

        return $tail;
    }

    /**
     * Gets the rule the tail of a split should recur on.
     *
     * A caller supplying their own is redefining the arrangement and is taken at
     * their word. Otherwise the head's rule carries over, with any `COUNT`
     * reduced by however many occurrences the head keeps — see
     * {@see self::splitSeries()} for why handing the full count to both halves
     * silently lengthens the arrangement.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series being split.
     * @param  array<string, mixed>  $changes  What the tail changes.
     * @param  int  $kept  How many occurrences stay with the head.
     *
     * @return string The tail's recurrence rule.
     */
    protected function tailRule( BookingSeries $series, array $changes, int $kept ): string
    {
        $allowed = $this->seriesChanges( $changes );

        if ( array_key_exists( 'rrule', $allowed ) ) {
            return (string) $allowed['rrule'];
        }

        $rrule    = (string) $series->rrule;
        $declared = $this->declaredCount( $rrule );

        if ( null === $declared || 0 === $kept ) {
            return $rrule;
        }

        try {
            $rule = new Rule( $rrule );
            $rule->setCount( max( 1, $declared - $kept ) );

            return $rule->getString();
        } catch ( Exception ) {
            // The rule parsed well enough to report a count a moment ago, so
            // this should not happen — but a rule that cannot be rewritten is
            // better carried over intact than replaced with nothing.
            return $rrule;
        }
    }

    /**
     * Counts how many of a set of instants fall before another.
     *
     * @since 1.0.0
     *
     * @param  array<int, CarbonImmutable>  $instants  The instants to count.
     * @param  CarbonImmutable  $boundary  The instant to count up to, exclusive.
     *
     * @return int How many fall strictly before the boundary.
     */
    protected function countBefore( array $instants, CarbonImmutable $boundary ): int
    {
        $before = 0;

        foreach ( $instants as $instant ) {
            if ( $instant->lessThan( $boundary ) ) {
                $before++;
            }
        }

        return $before;
    }

    /**
     * Cancels a series' occurrences from an instant onwards.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series whose occurrences to cancel.
     * @param  CarbonImmutable  $from  The instant to cancel from, exclusive.
     * @param  BookingActor  $actor  Who cancelled them.
     * @param  string|null  $reason  Why, when a reason was given.
     * @param  bool  $attachedOnly  Whether to leave detached occurrences standing.
     *                              True for a re-materialisation, which must not
     *                              touch an occurrence somebody edited by hand;
     *                              false for a cancellation of the whole
     *                              arrangement, which calls off every part of it.
     *
     * @return int How many were cancelled.
     */
    protected function cancelOccurrencesFrom(
        BookingSeries $series,
        CarbonImmutable $from,
        BookingActor $actor,
        ?string $reason,
        bool $attachedOnly,
    ): int {
        $query = $series->occurrences()->where( 'start_time', '>=', $from->utc() );

        if ( $attachedOnly ) {
            $query->whereNull( 'detached_from_series_at' );
        }

        $cancelled = 0;

        foreach ( $query->get() as $occurrence ) {
            if ( ! $occurrence->occupiesSlot() ) {
                continue;
            }

            $this->bookings->cancel( $occurrence, $actor, $reason );
            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * Narrows an edit to the series columns it is allowed to write.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $changes  What the caller asked to change.
     *
     * @return array<string, mixed> The changes that may be applied.
     */
    protected function seriesChanges( array $changes ): array
    {
        $allowed = array_intersect_key( $changes, $this->keyed( self::SERIES_FIELDS ) );

        foreach ( [ 'dtstart_local', 'until_local' ] as $key ) {
            if ( array_key_exists( $key, $allowed ) && null !== $allowed[ $key ] ) {
                $allowed[ $key ] = $this->floating( $allowed[ $key ] );
            }
        }

        return $allowed;
    }

    /**
     * Builds the attributes every occurrence of a series shares.
     *
     * The intake answers, the notes, and the customer's zone are not columns on
     * the series, so on a re-materialisation there is nothing on the rule to
     * read them back from. They are taken from an occurrence the series has
     * already produced instead — which is the only place they survive, and is
     * why an "all" edit does not silently hand the provider a booking with the
     * intake form blank.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series being materialised.
     * @param  array<string, mixed>  $attributes  Whatever the caller supplied.
     * @param  BookingSeries|null  $inheritFrom  The series to read those details
     *                                           from, when it is not this one.
     *
     * @return array<string, mixed> The shared booking attributes.
     */
    protected function occurrenceAttributes(
        BookingSeries $series,
        array $attributes,
        ?BookingSeries $inheritFrom = null,
    ): array {
        $template = $this->templateOccurrence( $inheritFrom ?? $series );

        return [
            'service_id'          => (int) $series->service_id,
            'customer_name'       => (string) $series->customer_name,
            'customer_email'      => (string) $series->customer_email,
            'customer_phone'      => $series->customer_phone,
            // Stated explicitly, because every occurrence after the first names
            // the provider the series is pinned to — which puts BookingService
            // on its "the caller chose this provider" branch, where the strategy
            // it records defaults to `customer`. Left alone, eleven weeks of a
            // twelve-week rota-assigned series would claim the customer picked.
            'assignment_strategy' => $attributes['assignment_strategy']
                ?? $template?->assignment_strategy
                ?? ( null === $series->provider_id
                    ? BookingAssignmentStrategy::RoundRobin
                    : BookingAssignmentStrategy::Customer ),
            // The zone the rule was authored in is the zone the customer chose
            // their time in, so it is the right default for rendering each
            // occurrence back to them.
            'customer_timezone' => (string) ( $attributes['customer_timezone']
                ?? $template?->customer_timezone
                ?? $series->dtstart_timezone ),
            'intake_data'       => $attributes['intake_data'] ?? $template?->intake_data ?? [],
            'notes'             => $attributes['notes'] ?? $template?->notes,
        ];
    }

    /**
     * Gets an occurrence to read the details the series does not carry from.
     *
     * The latest by position rather than the earliest, so an arrangement whose
     * customer changed their phone number halfway through carries the number
     * they gave most recently forward rather than the one they have replaced.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series being materialised.
     *
     * @return Booking|null The occurrence to copy from, or null on a new series.
     */
    protected function templateOccurrence( BookingSeries $series ): ?Booking
    {
        return $series->occurrences()->reorder( 'series_index', 'desc' )->first();
    }

    /**
     * Gets the occurrence count a rule declares, when it declares one.
     *
     * @since 1.0.0
     *
     * @param  string  $rrule  The recurrence rule.
     *
     * @return int|null The declared `COUNT`, or null when the rule has none.
     */
    protected function declaredCount( string $rrule ): ?int
    {
        try {
            $count = ( new Rule( $rrule ) )->getCount();
        } catch ( Exception ) {
            return null;
        }

        return null === $count ? null : (int) $count;
    }

    /**
     * Gets the provider a caller named for a whole series, if they named one.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The series being created.
     *
     * @return int|null The provider's id, or null to let the rota decide.
     */
    protected function namedProviderId( array $attributes ): ?int
    {
        $named = $attributes['provider'] ?? $attributes['provider_id'] ?? null;

        if ( null === $named ) {
            return null;
        }

        return $named instanceof Model ? (int) $named->getKey() : (int) $named;
    }

    /**
     * Gets the zone a series' clock faces are read in.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The series being created.
     * @param  Service  $service  The service being booked.
     *
     * @return string The IANA timezone name.
     */
    protected function resolveTimezone( array $attributes, Service $service ): string
    {
        $named = $attributes['dtstart_timezone'] ?? $attributes['timezone'] ?? null;

        if ( is_string( $named ) && '' !== trim( $named ) ) {
            return trim( $named );
        }

        return (string) ( $service->timezone ?? $this->config->get( 'artisanpack.bookings.timezone', 'UTC' ) );
    }

    /**
     * Gets the rule a series cannot be created without.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The series being created.
     *
     * @throws InvalidArgumentException When no rule was given.
     *
     * @return string The recurrence rule.
     */
    protected function requiredRule( array $attributes ): string
    {
        $rrule = trim( (string) ( $attributes['rrule'] ?? '' ) );

        if ( '' === $rrule ) {
            throw new InvalidArgumentException( 'A series must name an rrule.' );
        }

        return $rrule;
    }

    /**
     * Gets an attribute a series cannot be written without.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The series being created.
     * @param  string  $key  The attribute to read.
     *
     * @throws InvalidArgumentException When the attribute is missing or blank.
     *
     * @return string The value.
     */
    protected function requiredString( array $attributes, string $key ): string
    {
        $value = trim( (string) ( $attributes[ $key ] ?? '' ) );

        if ( '' === $value ) {
            throw new InvalidArgumentException( sprintf( 'A series must name a %s.', $key ) );
        }

        return $value;
    }

    /**
     * Gets the service a series names.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The series being created.
     *
     * @throws InvalidArgumentException When no service is named or it is unknown.
     *
     * @return Service The service being booked.
     */
    protected function resolveService( array $attributes ): Service
    {
        $named = $attributes['service'] ?? $attributes['service_id'] ?? null;

        $service = $named instanceof Service ? $named : Service::query()->find( $named );

        if ( ! $service instanceof Service ) {
            throw new InvalidArgumentException( 'A series must name a service that exists.' );
        }

        return $service;
    }

    /**
     * Reads a value as a floating clock face, without converting it.
     *
     * The whole point of `dtstart_local` is that it is not an instant, so
     * anything on its way into that column has to keep the clock face it arrived
     * with. Rebuilt in UTC so nothing downstream can shift it — the stored value
     * is whatever `format()` prints, and this makes that the face the caller
     * meant.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  A date-time, or something parseable as one.
     *
     * @throws InvalidArgumentException When the value cannot be read as a time.
     *
     * @return CarbonImmutable The same clock face, carrying no zone of meaning.
     */
    protected function floating( mixed $value ): CarbonImmutable
    {
        $face = $value instanceof CarbonInterface
            ? $value->format( 'Y-m-d H:i:s' )
            : CarbonImmutable::parse( (string) $value )->format( 'Y-m-d H:i:s' );

        return CarbonImmutable::createFromFormat( 'Y-m-d H:i:s', $face, 'UTC' );
    }

    /**
     * Reads a value as an instant, in UTC.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  A date-time, or something parseable as one.
     *
     * @return CarbonImmutable The instant.
     */
    protected function instant( mixed $value ): CarbonImmutable
    {
        return ( $value instanceof CarbonInterface
            ? CarbonImmutable::instance( $value )
            : CarbonImmutable::parse( (string) $value ) )->utc();
    }

    /**
     * Gets a list of column names as an array keyed by them.
     *
     * @since 1.0.0
     *
     * @param  list<string>  $keys  The column names.
     *
     * @return array<string, bool> The names, as keys.
     */
    protected function keyed( array $keys ): array
    {
        $keyed = [];

        foreach ( $keys as $key ) {
            $keyed[ $key ] = true;
        }

        return $keyed;
    }

    /**
     * Removes a series and everything it managed to write before it failed.
     *
     * The occurrences have to go first, and through the model rather than the
     * query builder. `bookings.series_id` is `nullOnDelete`, so removing the
     * series row first strands them: the database nulls the foreign key without
     * Eloquent ever running, they keep the `series_index` of a series that no
     * longer exists — the exact state `Booking::booted()` exists to prevent —
     * and, far worse than untidy, they go on holding their providers' slots for
     * an arrangement the caller was told did not happen.
     *
     * Hard deletes rather than cancellations, because these bookings are not
     * appointments anybody had: nothing was returned to the caller, no
     * confirmation went out, and a cancelled row would put a phantom in the
     * customer's history. Deleting through the model keeps the availability
     * cache invalidation that hangs off the delete event.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series to unwind.
     *
     * @return void
     */
    protected function discard( BookingSeries $series ): void
    {
        foreach ( $series->occurrences()->withTrashed()->get() as $occurrence ) {
            $occurrence->forceDelete();
        }

        $series->forceDelete();
    }

    /**
     * Runs a piece of work in the site the series belongs to.
     *
     * An edit reads and writes a great deal more than the series row — it looks
     * the service up, cancels occurrences, and materialises new bookings, each
     * of which is site-scoped on the way in and site-stamped on the way out.
     * Ordinarily the ambient site and the series' site are the same, because the
     * series was loaded through the scope. The two paths the package explicitly
     * supports for crossing sites break that: a console command looping with
     * `SiteContext::forSite()`, and a maintenance query using
     * `acrossAllSites()`.
     *
     * Left alone, an edit under a mismatched ambient site does one of two
     * things, both bad. It writes — putting the tail of a split, carrying this
     * customer's name, email, and phone, under a tenant they do not belong to,
     * and hiding their forward bookings from the tenant they do. Or it fails
     * halfway, because the service it needs is invisible from where it is
     * standing, leaving the head bounded and its occurrences cancelled with
     * nothing put in their place.
     *
     * Pinning the series' own site for the duration removes both. A null site —
     * every row on a single-tenant installation — pins "no site", which is
     * exactly the inert state those installations already run in.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series whose site to work in.
     * @param  Closure  $callback  The work to do.
     *
     * @return mixed Whatever the callback returns.
     */
    protected function inSiteOf( BookingSeries $series, Closure $callback ): mixed
    {
        $container = Container::getInstance();

        // Asked rather than assumed, for the same reason the site scope asks:
        // an application that never registered core's provider should get an
        // inert no-op here instead of a failure building a SiteContext out of
        // nothing.
        if ( ! $container->bound( SiteContext::class ) ) {
            return $callback();
        }

        return $container->make( SiteContext::class )->forSite(
            $series->getAttribute( $series->getSiteIdColumn() ),
            static fn (): mixed => $callback(),
        );
    }

    /**
     * Gets the hard cap on how many occurrences one rule may produce.
     *
     * @since 1.0.0
     *
     * @return int The maximum number of occurrences.
     */
    protected function maxOccurrences(): int
    {
        return (int) $this->config->get( 'artisanpack.bookings.series.max_occurrences', 52 );
    }
}
