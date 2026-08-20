<?php

/**
 * Booking service.
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

use ArtisanPackUI\Bookings\Contracts\RoundRobinStrategy;
use ArtisanPackUI\Bookings\Contracts\SlotResolver;
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
use ArtisanPackUI\Bookings\Exceptions\InvalidBookingTransitionException;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\TimeRange;
use ArtisanPackUI\Core\MultiTenancy\SiteContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * The one place a booking's life is decided.
 *
 * Every state a booking can be in is reached through a method here, and that is
 * a rule rather than a convention. Nine lifecycle actions and six domain events
 * hang off these transitions, and the guarantee everything downstream is written
 * against — a calendar push, a confirmation email, a CRM record — is that each
 * one fires exactly once per transition. A manage-token route or an admin screen
 * that flipped a status itself would either fire nothing or fire it twice, and
 * both failures surface a long way from the code that caused them.
 *
 * **Creating a booking is a race.** Two customers can be looking at the same
 * last slot, and neither request knows about the other until one of them writes.
 * Three things stop that ending in a double booking, and all three are needed:
 *
 * 1. An advisory lock on `(provider, start time)` around the read-availability
 *    and write-booking pair, so the ordinary case is decided before either
 *    request reaches the database. {@see ProviderSlotLock}.
 * 2. The partial unique index on `bookings`, which settles the case where the
 *    lock was not held — a write from code that never took one, a process that
 *    died holding it, a second application server on a cache with no shared
 *    locks. A lost race surfaces here as a unique violation.
 * 3. Falling through to the next round-robin candidate when that happens, so a
 *    customer who lost the race to one provider gets the colleague who is also
 *    free rather than an error page.
 *
 * That third point is why `ap.bookings.creating` can fire more than once for a
 * single call, while `ap.bookings.created` fires exactly once: the first is
 * "about to write this booking against this provider", which is a thing that
 * can genuinely be attempted twice, and the second is "a booking exists", which
 * cannot.
 *
 * **Cancellation before confirmation** stays with the `BookingRequested` event
 * rather than with a hook. The booking row exists by the time it fires — a
 * `requested` booking holds its slot exactly as a `confirmed` one does, which is
 * the only way to stop somebody else taking it while an approval is pending — so
 * a listener that objects cancels it, and this method then declines to confirm
 * what a listener has just cancelled. `ap.bookings.creating` deliberately has no
 * veto: it runs inside the lock, and letting a subscriber abort there would hold
 * a slot lock open across arbitrary third-party code.
 *
 * That veto has one limit worth knowing before relying on it. The event is
 * `ShouldDispatchAfterCommit`, so a caller who wraps `create()` in a transaction
 * of their own — materialising a series, importing in bulk — pushes every
 * listener past the end of this method. The booking is auto-confirmed before any
 * of them runs, and a listener that then cancels it is cancelling a confirmed
 * booking rather than preventing one. Callers who need the veto have to let
 * `create()` own its own transaction, and callers who batch should confirm
 * explicitly with automatic confirmation switched off.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingService
{
    /**
     * Constructs the service.
     *
     * @since 1.0.0
     *
     * @param  SlotResolver  $slots  The resolver that says what is bookable.
     * @param  ProviderSlotLock  $lock  The advisory lock around a slot.
     * @param  RoundRobinStrategy  $strategy  The rule that picks a provider.
     * @param  IntakeFieldValidator  $intake  The intake answer validator.
     * @param  ConfigRepository  $config  The application configuration.
     */
    public function __construct(
        protected SlotResolver $slots,
        protected ProviderSlotLock $lock,
        protected RoundRobinStrategy $strategy,
        protected IntakeFieldValidator $intake,
        protected ConfigRepository $config,
    ) {
    }

    /**
     * Creates a booking, assigning a provider when the caller has not.
     *
     * Accepts `service` or `service_id`, an optional `provider` or `provider_id`,
     * a `start_time`, the customer's details, and `intake_data`. A caller that
     * names a provider gets that provider or an exception; a caller that does not
     * gets whichever of the service's providers the assignment strategy picks.
     *
     * The intake answers are validated against the service's *current* schema
     * version and that version is snapshotted onto the booking, so the answers
     * stay readable after an administrator edits the form. A caller that gathered
     * those answers somewhere the schema does not describe — a form whose own
     * fields are the questions — passes `$validateIntake` false to store what it
     * has without measuring it against a schema it was never shown.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The booking to create.
     * @param  Authenticatable|null  $customer  The signed-in customer, when there
     *                                          is one. Passed to
     *                                          `ap.bookings.creating` and used for
     *                                          nothing else — a booking is keyed to
     *                                          an email address, not an account.
     * @param  bool  $validateIntake  Whether to judge `intake_data` against the
     *                                service's schema. False stores the answers as
     *                                given — for a booking whose questions were
     *                                asked by something other than the service's
     *                                own intake form.
     *
     * @throws InvalidArgumentException When the attributes do not name a service
     *                                  and a start time.
     * @throws SlotUnavailableException When no provider could take the slot.
     * @throws UnexpectedValueException When a subscriber to one of the
     *                                  `ap.bookings.*` filters returns something
     *                                  the filter cannot use.
     *
     * @return Booking The booking, confirmed unless a listener cancelled it or
     *                 automatic confirmation is switched off.
     */
    public function create( array $attributes, ?Authenticatable $customer = null, bool $validateIntake = true ): Booking
    {
        $service = $this->resolveService( $attributes );
        $start   = $this->resolveStart( $attributes );

        // The service the caller resolved — through `acrossAllSites()` or a
        // per-site loop — is the authority for the booking's site, not whichever
        // one happens to be ambient. Pinning it before anything reads or writes
        // means the provider lookup, the availability re-read, and the insert's
        // `site_id` stamp all speak of the service's tenant. {@see self::inSiteOf()}.
        return $this->inSiteOf(
            $service,
            fn (): Booking => $this->place( $service, $start, $attributes, $customer, $validateIntake ),
        );
    }

    /**
     * Creates a booking from the values a form submission carried.
     *
     * The entry point the forms integration (#48) reaches for once a submission
     * has been stored: the listener that watches `FormSubmitted` pulls the
     * booking-shaped values out of the submission and hands them here as a plain
     * array, and this maps them onto {@see self::create()}. Routing through
     * `create()` rather than writing the row itself is the whole point — a
     * booking made this way fires `ap.bookings.creating`, `created`, and
     * `confirmed`, is assigned a provider, and is guarded against a double-book,
     * exactly as one made through the widget is. The two paths differ only in
     * where the answers came from.
     *
     * The service's intake schema is *not* enforced here. A form booking's
     * questions are the form's own fields, which the form already validated; the
     * service's intake schema describes the standalone widget's questions, which
     * this visitor was never shown. So `create()` is asked to store the answers
     * as given rather than judge them against a schema that does not describe the
     * form — a service whose intake requires a field the form did not ask would
     * otherwise reject every booking the form could ever make.
     *
     * The array is deliberately not a forms model. `artisanpack-ui/forms` is a
     * `suggest`, so this service — which every installation loads — cannot name
     * a class that is only sometimes present. The listener owns the translation
     * from the submission to these keys; this owns turning a slug into the
     * service the booking is for and nothing more forms-shaped than that.
     *
     * Accepts a `service_slug` naming an active service, a `start_time`, the
     * customer's details, and optionally a `provider_id`, `notes`, and
     * `intake_data`. The slug is resolved to a service the same way the public
     * booking pages resolve one — active only — so a submission naming a
     * withdrawn or unknown service is refused here rather than booked against a
     * service the customer could never have reached.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $submission  The booking-shaped values a form
     *                                            submission carried.
     * @param  Authenticatable|null  $customer  The signed-in customer, when there
     *                                          is one.
     *
     * @throws InvalidArgumentException When the values name no service slug, or
     *                                  name one no active service answers to.
     * @throws SlotUnavailableException When no provider could take the slot.
     *
     * @return Booking The booking the submission asked for.
     */
    public function createFromFormSubmission( array $submission, ?Authenticatable $customer = null ): Booking
    {
        $slug = trim( (string) ( $submission['service_slug'] ?? '' ) );

        if ( '' === $slug ) {
            throw new InvalidArgumentException( 'A booking from a form submission must name a service slug.' );
        }

        $service = $this->activeServiceForSlug( $slug );

        if ( ! $service instanceof Service ) {
            throw new InvalidArgumentException( sprintf(
                'No active service answers to the slug [%s].',
                $slug,
            ) );
        }

        $providerId = $submission['provider_id'] ?? null;

        return $this->create( [
            'service'           => $service,
            'provider_id'       => null === $providerId || '' === $providerId ? null : (int) $providerId,
            'start_time'        => $submission['start_time'] ?? null,
            'customer_name'     => (string) ( $submission['customer_name'] ?? '' ),
            'customer_email'    => (string) ( $submission['customer_email'] ?? '' ),
            'customer_phone'    => $submission['customer_phone'] ?? null,
            'customer_timezone' => $submission['customer_timezone'] ?? null,
            'notes'             => $submission['notes'] ?? null,
            'intake_data'       => (array) ( $submission['intake_data'] ?? [] ),
        ], $customer, false );
    }

    /**
     * Answers whether a form submission's picked slot could still be booked.
     *
     * The seam issue #118 needs: the forms integration re-asks this at submit
     * time, before the submission is stored, so a slot that went while the form
     * was open fails the form the way any other field validation does — a visible
     * error the visitor can act on — rather than being caught and logged after a
     * success they have already been shown.
     *
     * It mirrors the front half of {@see self::createFromFormSubmission()} exactly
     * — the same active-only slug resolution, the same provider candidates, the
     * same availability read — but stops short of writing anything: it reports
     * whether a provider is free for the slot instead of taking it. A submission
     * that names no bookable service, no parseable start, or a provider who does
     * not offer the service is simply not bookable, so every malformed shape
     * answers false rather than raising — the caller wants a yes-or-no, and the
     * booking attempt the listener still makes is where a genuinely unexpected
     * problem surfaces.
     *
     * Two callers can still disagree about one slot between this check and the
     * booking that follows — the guarantee against a double-book is the advisory
     * lock in {@see self::attempt()}, not this read. What this buys is the common
     * case: the visitor who lost the slot minutes ago is told so at submit rather
     * than left believing they booked.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $submission  The booking-shaped values a form
     *                                            submission carried, keyed as
     *                                            {@see self::createFromFormSubmission()}
     *                                            takes them.
     *
     * @return bool True when a provider is free to take the slot right now.
     */
    public function formSubmissionIsBookable( array $submission ): bool
    {
        $slug       = $submission['service_slug'] ?? null;
        $start      = $submission['start_time'] ?? null;
        $providerId = $submission['provider_id'] ?? null;

        // A form field never carries an array or object here; one that does is
        // malformed rather than merely unavailable. Refuse it before it can be
        // coerced — `(int) [ 1 ]` is `1`, and a non-scalar start would fatal in
        // Carbon rather than resolve — so the malformed shape reports not-bookable
        // instead of appearing to name a real slot.
        if ( ! is_string( $slug )
            || ( null !== $start && ! is_scalar( $start ) && ! $start instanceof CarbonInterface )
            || ( null !== $providerId && ! is_scalar( $providerId ) ) ) {
            return false;
        }

        $slug = trim( $slug );

        if ( '' === $slug ) {
            return false;
        }

        $service = $this->activeServiceForSlug( $slug );

        if ( ! $service instanceof Service ) {
            return false;
        }

        $attributes = [
            'service'     => $service,
            'provider_id' => null === $providerId || '' === $providerId ? null : (int) $providerId,
            'start_time'  => $start,
        ];

        try {
            $start = $this->resolveStart( $attributes );
        } catch ( InvalidArgumentException ) {
            return false;
        }

        return $this->inSiteOf( $service, function () use ( $service, $start, $attributes ): bool {
            try {
                [ $candidates ] = $this->candidateProviders( $service, $attributes );
            } catch ( InvalidArgumentException ) {
                return false;
            }

            $available = $this->filterAvailableProviders(
                $this->providersFreeAt( $service, $candidates, $start ),
                $service,
                $start,
            );

            return [] !== $available;
        } );
    }

    /**
     * Confirms a booking that was waiting on approval.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to confirm.
     * @param  BookingActor  $actor  Who confirmed it.
     *
     * @throws InvalidBookingTransitionException When the booking is not awaiting
     *                                           confirmation.
     *
     * @return Booking The confirmed booking.
     */
    public function confirm( Booking $booking, BookingActor $actor = BookingActor::System ): Booking
    {
        if ( BookingStatus::Requested !== $booking->status ) {
            throw InvalidBookingTransitionException::from( $booking, BookingStatus::Confirmed );
        }

        $booking->forceFill( [ 'status' => BookingStatus::Confirmed ] )->save();

        doAction( 'ap.bookings.confirmed', $booking );

        BookingConfirmed::dispatch( $booking, $actor );

        return $booking;
    }

    /**
     * Moves a booking to a different time, keeping its provider and its length.
     *
     * Guarded by the same advisory lock and unique index that creation is, so a
     * reschedule cannot land on a slot somebody else is taking at that moment.
     *
     * What it does *not* do is re-ask the resolver whether the new time is one
     * the service offers. It cannot usefully: the booking's own row is what
     * makes its current time look taken, so availability computed around a
     * booking that is being moved reports the move as a clash with itself. The
     * caller decides which times to offer — that is what the resolver is for,
     * and every path that reaches here has already been through it — and this
     * enforces the invariant the caller cannot: no two active bookings on one
     * provider at once.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to move.
     * @param  CarbonInterface  $newStart  The instant it should start at instead.
     * @param  BookingActor  $actor  Who moved it.
     *
     * @throws InvalidBookingTransitionException When the booking no longer holds
     *                                           a slot to move.
     * @throws SlotUnavailableException When the new time is already taken.
     *
     * @return Booking The booking at its new time.
     */
    public function reschedule(
        Booking $booking,
        CarbonInterface $newStart,
        BookingActor $actor = BookingActor::System,
    ): Booking {
        if ( ! $booking->occupiesSlot() ) {
            throw InvalidBookingTransitionException::cannotReschedule( $booking );
        }

        $start    = CarbonImmutable::instance( $newStart )->utc();
        $previous = new TimeRange( $booking->start_time, $booking->end_time );
        $minutes  = max( 1, $previous->minutes() );
        $end      = $start->addMinutes( $minutes );

        $providerId = null === $booking->provider_id ? null : (int) $booking->provider_id;

        $move = function () use ( $booking, $start, $end, $providerId ): ?Booking {
            if ( null !== $providerId && $this->clashes( $providerId, $start, $end, $booking ) ) {
                return null;
            }

            try {
                $this->connection()->transaction( static function () use ( $booking, $start, $end ): void {
                    $booking->forceFill( [ 'start_time' => $start, 'end_time' => $end ] )->save();
                } );
            } catch ( UniqueConstraintViolationException ) {
                // The transaction rolled the row back; the instance did not roll
                // back with it. Left alone, the caller catching the exception
                // below holds a booking carrying the time the database just
                // refused — and saving it later would write exactly the value
                // this method exists to reject.
                $booking->refresh();

                return null;
            }

            return $booking;
        };

        // The whole announced lifecycle runs under the booking's own site, not
        // the ambient one — parity with {@see self::place()}. That is the tenant
        // its clash check has to read (a reschedule driven from a mismatched
        // context — a per-site reminder command, a maintenance sweep under
        // `acrossAllSites()` — would otherwise read another tenant's diary and
        // miss a real double booking), and the tenant a `rescheduling` or
        // `rescheduled` listener sees when it reads the booking's site-scoped
        // service or writes a site-scoped row of its own. The failure throw is
        // inside it too: it names the service, which would lazy-load as null
        // read from the wrong site. {@see self::inSiteOf()}.
        return $this->inSiteOf( $booking, function () use ( $booking, $providerId, $start, $previous, $actor, $move ): Booking {
            doAction( 'ap.bookings.rescheduling', $booking, $start );

            $moved = null === $providerId
                ? $this->connection()->transaction( $move )
                : $this->lock->withSlotLock( $providerId, $start, $this->providerTimezone( $booking ), $move );

            if ( null === $moved ) {
                throw SlotUnavailableException::for( $booking->service, $start );
            }

            doAction( 'ap.bookings.rescheduled', $moved, $previous->start );

            BookingRescheduled::dispatch( $moved, $previous, $actor );

            return $moved;
        } );
    }

    /**
     * Moves a booking to a different provider at the same time.
     *
     * The provider is chosen the way creation chooses one: the service's bookable
     * providers, minus the one holding the booking now, narrowed to those free at
     * the booking's instant, run through `ap.bookings.availableProviders`, and
     * picked from by the round-robin rota via `ap.bookings.roundRobin.selectProvider`.
     * Both hooks fire once per booking, so a bulk reassign over N bookings fires
     * each N times rather than once for the batch.
     *
     * The time never moves — this is "someone else takes this appointment", not a
     * reschedule — so the length and the slot are the booking's own. The write is
     * guarded by the same advisory lock and unique index creation is, and a
     * provider who loses the slot to a race is dropped and the next candidate
     * tried, so a reassign either lands on a genuinely free provider or reports
     * that none was free.
     *
     * Once the move lands, `ap.bookings.reassigned` fires and
     * {@see BookingReassigned} is dispatched, both carrying the provider the
     * booking left — the row no longer remembers it — so a listener can undo what
     * it set up for the old provider and stand the new one up. A change of time
     * without a change of provider is {@see self::reschedule()}'s to announce, not
     * this; the two never announce each other's kind of move.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to hand to another provider.
     * @param  BookingActor  $actor  Who reassigned it.
     *
     * @throws InvalidArgumentException When the booking has no service to resolve
     *                                  providers against.
     * @throws InvalidBookingTransitionException When the booking is not holding a
     *                                           slot to reassign.
     * @throws SlotUnavailableException When no other provider is free at the time.
     *
     * @return Booking The booking, now on its new provider.
     */
    public function reassign( Booking $booking, BookingActor $actor = BookingActor::System ): Booking
    {
        if ( ! $booking->occupiesSlot() ) {
            throw InvalidBookingTransitionException::cannotReschedule( $booking );
        }

        $service = $booking->service;

        if ( ! $service instanceof Service ) {
            throw new InvalidArgumentException( 'A booking with no service cannot be reassigned.' );
        }

        $start              = CarbonImmutable::instance( $booking->start_time )->utc();
        $previousProviderId = null === $booking->provider_id ? null : (int) $booking->provider_id;

        $candidates = $service->bookableProviders();

        if ( $booking->provider instanceof ServiceProvider ) {
            $candidates = $this->without( $candidates, $booking->provider );
        }

        $available = $this->filterAvailableProviders(
            $this->providersFreeAt( $service, $candidates, $start ),
            $service,
            $start,
        );

        $requested  = new Slot( new TimeRange( $start, CarbonImmutable::instance( $booking->end_time )->utc() ) );
        $reassigned = null;

        while ( [] !== $available && null === $reassigned ) {
            $provider = $this->chooseProvider( $available, $service, $requested, $booking, BookingAssignmentStrategy::RoundRobin );

            if ( null === $provider ) {
                break;
            }

            $reassigned = $this->moveToProvider( $booking, $service, $provider, $start );
            $available  = $this->without( $available, $provider );
        }

        if ( null === $reassigned ) {
            throw SlotUnavailableException::for( $service, $start );
        }

        doAction( 'ap.bookings.reassigned', $reassigned, $previousProviderId );

        BookingReassigned::dispatch( $reassigned, $previousProviderId, $actor );

        return $reassigned;
    }

    /**
     * Cancels a booking, freeing the slot it was holding.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to cancel.
     * @param  BookingActor  $actor  Who cancelled it. Not defaulted: "the customer
     *                               cancelled" and "we cancelled on the customer"
     *                               are the same status change and completely
     *                               different events downstream.
     * @param  string|null  $reason  Why, when a reason was given.
     *
     * @throws InvalidBookingTransitionException When the booking is not holding a
     *                                           slot to free.
     *
     * @return Booking The cancelled booking.
     */
    public function cancel( Booking $booking, BookingActor $actor, ?string $reason = null ): Booking
    {
        if ( ! $booking->occupiesSlot() ) {
            throw InvalidBookingTransitionException::from( $booking, BookingStatus::Cancelled );
        }

        doAction( 'ap.bookings.cancelling', $booking, $reason ?? '' );

        $booking->forceFill( [ 'status' => BookingStatus::Cancelled ] )->save();

        doAction( 'ap.bookings.cancelled', $booking );

        BookingCancelled::dispatch( $booking, $actor, $reason );

        return $booking;
    }

    /**
     * Marks a booking as delivered.
     *
     * Deliberately not gated on the end time having passed. Completion is a claim
     * about the real world rather than a consequence of the clock, and a provider
     * closing out an appointment that ran short is making it correctly.
     *
     * **The transition is claimed against the row, not asserted from memory.**
     * A caller holding a booking that has been cancelled underneath it — which
     * is the normal case for `bookings:complete-past`, walking a page at a time
     * — gets an exception rather than silently overwriting the cancellation, and
     * the hook and event fire only for the caller that actually moved the row.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking that was delivered.
     * @param  BookingActor  $actor  Who marked it complete.
     *
     * @throws InvalidBookingTransitionException When the booking is not one that
     *                                           could still be delivered.
     *
     * @return Booking The completed booking.
     */
    public function complete( Booking $booking, BookingActor $actor = BookingActor::System ): Booking
    {
        if ( ! $booking->occupiesSlot() ) {
            throw InvalidBookingTransitionException::from( $booking, BookingStatus::Completed );
        }

        // The check above reads the model in memory, and the completion sweep
        // hands over bookings it enumerated a page at a time — so by the time one
        // is reached, the customer may have cancelled it. A plain `save()` writes
        // by primary key with no condition on status, which would overwrite that
        // cancellation with `completed` and then announce it: the customer is
        // told their appointment was cancelled and the CRM is told it was
        // delivered.
        //
        // So the transition is claimed rather than asserted, the same way
        // {@see Webhook::disable()} claims a disable. The database decides, and
        // whoever did not win it throws — which is a normal outcome the sweep
        // catches and moves past, not an error. Scopes are dropped because the
        // sweep runs from cron across every site; soft deletes are re-guarded by
        // hand, since dropping the scopes drops that one too.
        $claimed = $booking->newQueryWithoutScopes()
            ->whereKey( $booking->getKey() )
            ->whereNull( 'deleted_at' )
            ->whereIn( 'status', BookingStatus::activeValues() )
            ->update( [ 'status' => BookingStatus::Completed->value ] );

        if ( 1 !== $claimed ) {
            throw InvalidBookingTransitionException::from( $booking, BookingStatus::Completed );
        }

        // Written onto the instance rather than reloaded with `refresh()`, which
        // re-reads the loaded relations through the model's own scopes — and the
        // sweep loads the service and provider across every site precisely so
        // the webhook body can name them. Refreshing here would null them again
        // for every tenant but the resolved one.
        $booking->setAttribute( 'status', BookingStatus::Completed );
        $booking->syncOriginalAttribute( 'status' );

        doAction( 'ap.bookings.completed', $booking );

        BookingCompleted::dispatch( $booking, $actor );

        return $booking;
    }

    /**
     * Marks a booking as a no-show.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking nobody attended.
     * @param  BookingActor  $actor  Who marked it.
     *
     * @throws InvalidBookingTransitionException When the booking was not holding a
     *                                           slot to be absent from.
     *
     * @return Booking The booking, marked as a no-show.
     */
    public function markNoShow( Booking $booking, BookingActor $actor = BookingActor::System ): Booking
    {
        if ( ! $booking->occupiesSlot() ) {
            throw InvalidBookingTransitionException::from( $booking, BookingStatus::NoShow );
        }

        $booking->forceFill( [ 'status' => BookingStatus::NoShow ] )->save();

        doAction( 'ap.bookings.noShow', $booking );

        BookingNoShow::dispatch( $booking, $actor );

        return $booking;
    }

    /**
     * Places a booking against a service whose site is already pinned.
     *
     * The body of {@see self::create()} once the service and start time are
     * resolved, split out so the whole of it runs inside the site pin rather
     * than only its final write.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  CarbonImmutable  $start  The instant the booking begins.
     * @param  array<string, mixed>  $attributes  The booking to create.
     * @param  Authenticatable|null  $customer  The signed-in customer, if any.
     * @param  bool  $validateIntake  Whether to judge the intake answers against
     *                                the service's schema. See {@see self::create()}.
     *
     * @throws SlotUnavailableException When no provider could take the slot.
     * @throws UnexpectedValueException When a subscriber to one of the
     *                                  `ap.bookings.*` filters returns something
     *                                  the filter cannot use.
     *
     * @return Booking The booking, confirmed unless a listener cancelled it or
     *                 automatic confirmation is switched off.
     */
    protected function place(
        Service $service,
        CarbonImmutable $start,
        array $attributes,
        ?Authenticatable $customer,
        bool $validateIntake = true,
    ): Booking {
        $version = (int) $service->intake_schema_version;

        [ $candidates, $assignment ] = $this->candidateProviders( $service, $attributes );

        $answers             = $this->arrayValue( $attributes['intake_data'] ?? [] );
        $base                = $this->baseAttributes( $attributes, $service, $start, $version, $assignment );
        $base['intake_data'] = $validateIntake
            ? $this->intake->validate( $service, $version, $answers )
            : $answers;

        $available = $this->filterAvailableProviders(
            $this->providersFreeAt( $service, $candidates, $start ),
            $service,
            $start,
        );

        $draft     = ( new Booking() )->forceFill( $base );
        $requested = new Slot( new TimeRange( $start, $start->addMinutes( max( 1, (int) $service->duration ) ) ) );
        $booking   = null;

        while ( [] !== $available && null === $booking ) {
            $provider = $this->chooseProvider( $available, $service, $requested, $draft, $assignment );

            if ( null === $provider ) {
                break;
            }

            $booking   = $this->attempt( $service, $provider, $start, $base, $customer, $assignment );
            $available = $this->without( $available, $provider );
        }

        if ( null === $booking ) {
            throw SlotUnavailableException::for( $service, $start );
        }

        doAction( 'ap.bookings.created', $booking );

        BookingRequested::dispatch( $booking );

        // Re-read rather than trust the instance. `BookingRequested` is where a
        // listener vetoes a booking, and it does that by cancelling it — on
        // whatever instance it loaded, which is not this one.
        $booking->refresh();

        if ( $this->confirmsAutomatically() && BookingStatus::Requested === $booking->status ) {
            $this->confirm( $booking );
        }

        return $booking;
    }

    /**
     * Tries to write the booking against one provider.
     *
     * Everything that decides whether this provider keeps the slot happens
     * inside the lock: availability is read again — the answer may have moved
     * since the candidate list was built — the payload is announced, and the row
     * is written. Returning null means "this one lost", which is a normal
     * outcome and not an error; the caller moves on to the next candidate.
     *
     * The insert has a transaction of its own inside the lock's, which on every
     * engine is a savepoint. Postgres aborts an entire transaction on any failed
     * statement, so without it the unique violation this method is written to
     * catch would poison the round-robin retry it exists to enable.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider  $provider  The provider being tried.
     * @param  CarbonImmutable  $start  The instant the slot begins.
     * @param  array<string, mixed>  $base  The attributes common to every attempt.
     * @param  Authenticatable|null  $customer  The signed-in customer, if any.
     * @param  BookingAssignmentStrategy  $assignment  How the provider was chosen.
     *
     * @return Booking|null The booking, or null when this provider lost the slot.
     */
    protected function attempt(
        Service $service,
        ServiceProvider $provider,
        CarbonImmutable $start,
        array $base,
        ?Authenticatable $customer,
        BookingAssignmentStrategy $assignment,
    ): ?Booking {
        $providerId = (int) $provider->getKey();

        return $this->lock->withSlotLock(
            $providerId,
            $start,
            $provider->timezone,
            function () use ( $service, $provider, $providerId, $start, $base, $customer, $assignment ): ?Booking {
                $slot = $this->slotAt( $service, $provider, $start );

                if ( null === $slot ) {
                    return null;
                }

                // Asked again as a plain overlap, because the resolver's answer
                // and the index's answer are both narrower than the invariant.
                // The resolver was read through a cache that a competing commit
                // on another machine may not have reached yet, and the index
                // only refuses an identical start time — so a booking landing in
                // the *middle* of one already there satisfies both and still
                // double-books. The day lock is what makes this check decisive:
                // nobody else can be writing to this provider's day while it runs.
                if ( $this->clashes( $providerId, $slot->period->start, $slot->period->end ) ) {
                    return null;
                }

                $attributes = array_merge( $base, [
                    'provider_id' => $providerId,
                    'start_time'  => $slot->period->start->utc(),
                    'end_time'    => $slot->period->end->utc(),
                ] );

                doAction( 'ap.bookings.creating', $attributes, $customer );

                try {
                    $booking = $this->connection()->transaction(
                        static fn (): Booking => Booking::query()->create( $attributes ),
                    );
                } catch ( UniqueConstraintViolationException ) {
                    return null;
                }

                // Moved inside the lock so the cursor and the booking commit
                // together. A provider credited with work whose booking then
                // rolled back would be pushed to the back of the rota for an
                // appointment nobody has.
                if ( BookingAssignmentStrategy::RoundRobin === $assignment ) {
                    $provider->forceFill( [
                        'round_robin_last_assigned_at' => $booking->freshTimestamp(),
                    ] )->save();
                }

                return $booking;
            },
        );
    }

    /**
     * Hands an existing booking to a provider under that provider's slot lock.
     *
     * The mirror of {@see self::attempt()} for a booking that already exists:
     * where creation inserts a row, this rewrites one booking's `provider_id`,
     * but both take the day lock so the clash check is decisive and both credit
     * the round-robin rota inside the lock so a provider is only pushed to the
     * back for work whose write committed. The `assignment_strategy` column is
     * rewritten to `RoundRobin` alongside the provider, because that column
     * records how the booking got the provider it now has and the rota is what
     * chose this one. The time is untouched — a reassign keeps the appointment
     * where it is.
     *
     * Availability is re-read inside the lock, exactly as {@see self::attempt()}
     * does it: the candidate list was built before the lock was held, so a
     * provider who stopped being free at the instant in that window — an override
     * added, a blackout drawn — must be caught here rather than have the booking
     * land on a diary that no longer covers the time. The clash check is a
     * separate question, and both have to pass.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being moved.
     * @param  Service  $service  The service being booked, for the availability re-read.
     * @param  ServiceProvider  $provider  The provider taking it.
     * @param  CarbonImmutable  $start  The instant the booking begins.
     *
     * @return Booking|null The booking, or null when this provider lost the slot.
     */
    protected function moveToProvider( Booking $booking, Service $service, ServiceProvider $provider, CarbonImmutable $start ): ?Booking
    {
        $providerId = (int) $provider->getKey();
        $end        = CarbonImmutable::instance( $booking->end_time )->utc();

        return $this->lock->withSlotLock(
            $providerId,
            $start,
            $provider->timezone,
            function () use ( $booking, $service, $provider, $providerId, $start, $end ): ?Booking {
                if ( null === $this->slotAt( $service, $provider, $start ) ) {
                    return null;
                }

                if ( $this->clashes( $providerId, $start, $end, $booking ) ) {
                    return null;
                }

                try {
                    $this->connection()->transaction( static function () use ( $booking, $providerId ): void {
                        $booking->forceFill( [
                            'provider_id'         => $providerId,
                            'assignment_strategy' => BookingAssignmentStrategy::RoundRobin,
                        ] )->save();
                    } );
                } catch ( UniqueConstraintViolationException ) {
                    $booking->refresh();

                    return null;
                }

                $provider->forceFill( [
                    'round_robin_last_assigned_at' => $booking->freshTimestamp(),
                ] )->save();

                return $booking;
            },
        );
    }

    /**
     * Picks which of the remaining candidates gets the slot.
     *
     * A caller who named a provider is not choosing between equals and the
     * strategy is not consulted — there is one candidate and it is theirs. The
     * round-robin filter is likewise silent on that path: `selectProvider` means
     * "the package is about to decide", and a subscriber overruling a provider
     * the customer picked by name would be answering a question nobody asked.
     *
     * @since 1.0.0
     *
     * @param  array<int, ServiceProvider>  $candidates  The providers still in the running.
     * @param  Service  $service  The service being booked.
     * @param  Slot  $slot  The slot being assigned.
     * @param  Booking  $draft  The booking about to be written, unsaved.
     * @param  BookingAssignmentStrategy  $assignment  How the provider is being chosen.
     *
     * @throws UnexpectedValueException When a subscriber to
     *                                  `ap.bookings.roundRobin.selectProvider`
     *                                  returns something other than a candidate.
     *
     * @return ServiceProvider|null The chosen provider, or null when none should take it.
     */
    protected function chooseProvider(
        array $candidates,
        Service $service,
        Slot $slot,
        Booking $draft,
        BookingAssignmentStrategy $assignment,
    ): ?ServiceProvider {
        if ( BookingAssignmentStrategy::RoundRobin !== $assignment ) {
            return $candidates[0] ?? null;
        }

        $selected = $this->strategy->select( $candidates, $service, $slot );
        $filtered = applyFilters( 'ap.bookings.roundRobin.selectProvider', $selected, $candidates, $draft );

        // Null is "no opinion", not "nobody". A subscriber that only cares about
        // some services has to be able to stay out of the way of the rest, and
        // the alternative reading would make an unconditional `return null`
        // guard turn every unrelated booking unbookable.
        if ( null === $filtered ) {
            return $selected;
        }

        if ( ! $filtered instanceof ServiceProvider ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.roundRobin.selectProvider must return %s or null, got %s.',
                ServiceProvider::class,
                get_debug_type( $filtered ),
            ) );
        }

        if ( ! $this->isCandidate( $candidates, $filtered ) ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.roundRobin.selectProvider returned provider %d, which is not one of the candidates.',
                (int) $filtered->getKey(),
            ) );
        }

        return $filtered;
    }

    /**
     * Gets the providers a service could be booked with.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  array<string, mixed>  $attributes  The booking being created.
     *
     * @throws InvalidArgumentException When a named provider does not exist or
     *                                  does not offer the service.
     *
     * @return array{0: array<int, ServiceProvider>, 1: BookingAssignmentStrategy} The
     *                                                                             candidates and how one will be chosen.
     */
    protected function candidateProviders( Service $service, array $attributes ): array
    {
        $named = $attributes['provider'] ?? $attributes['provider_id'] ?? null;

        if ( null !== $named ) {
            return [ [ $this->namedProvider( $service, $named ) ], $this->namedStrategy( $attributes ) ];
        }

        if ( ServiceAssignmentStrategy::DefaultProvider === $service->assignment_strategy ) {
            $default = $service->defaultProvider;

            return [
                null !== $default && $default->is_active ? [ $default ] : [],
                BookingAssignmentStrategy::DefaultProvider,
            ];
        }

        // `any` and `round_robin` both mean "the package picks", and the booking
        // records `round_robin` for either. The column says how this booking got
        // its provider, and the honest answer in both cases is "the rota did" —
        // `any` is a statement about how much the service cares, not a different
        // mechanism.
        return [ $service->bookableProviders(), BookingAssignmentStrategy::RoundRobin ];
    }

    /**
     * Resolves the provider a caller named, and checks they offer the service.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  int|ServiceProvider|string  $named  The provider, or their id.
     *
     * @throws InvalidArgumentException When the provider is unknown or does not
     *                                  offer the service.
     *
     * @return ServiceProvider The named provider.
     */
    protected function namedProvider( Service $service, mixed $named ): ServiceProvider
    {
        $provider = $named instanceof ServiceProvider
            ? $named
            : ServiceProvider::query()->find( $named );

        if ( ! $provider instanceof ServiceProvider ) {
            throw new InvalidArgumentException( 'The provider named for this booking does not exist.' );
        }

        foreach ( $service->bookableProviders() as $candidate ) {
            if ( (int) $candidate->getKey() === (int) $provider->getKey() ) {
                return $provider;
            }
        }

        throw new InvalidArgumentException( sprintf(
            'Provider %d does not offer service %d.',
            (int) $provider->getKey(),
            (int) $service->getKey(),
        ) );
    }

    /**
     * Gets how a booking records a provider the caller named.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The booking being created.
     *
     * @return BookingAssignmentStrategy The recorded strategy.
     */
    protected function namedStrategy( array $attributes ): BookingAssignmentStrategy
    {
        $given = $attributes['assignment_strategy'] ?? null;

        if ( $given instanceof BookingAssignmentStrategy ) {
            return $given;
        }

        return BookingAssignmentStrategy::tryFrom( (string) $given ) ?? BookingAssignmentStrategy::Customer;
    }

    /**
     * Narrows candidates to the ones actually free at the requested instant.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  array<int, ServiceProvider>  $candidates  The providers to check.
     * @param  CarbonImmutable  $start  The instant the slot begins.
     *
     * @return array<int, ServiceProvider> The candidates with a slot at that instant.
     */
    protected function providersFreeAt( Service $service, array $candidates, CarbonImmutable $start ): array
    {
        $free = [];

        foreach ( $candidates as $candidate ) {
            if ( null !== $this->slotAt( $service, $candidate, $start ) ) {
                $free[] = $candidate;
            }
        }

        return $free;
    }

    /**
     * Gets a provider's bookable slot beginning at an instant, if there is one.
     *
     * Resolved over a whole day rather than over the slot itself. The resolver
     * drops any slot that runs past the end of the window it was given, and how
     * long a slot runs for is not knowable here — a provider can be attached to
     * a service at a duration of their own, and `ap.bookings.slotDuration` can
     * change it again — so a window cut to the service's nominal length would
     * silently report a longer slot as unavailable. A day is the granularity the
     * resolver caches at in any case, so this costs nothing the widget has not
     * already paid.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  ServiceProvider  $provider  The provider being checked.
     * @param  CarbonImmutable  $start  The instant the slot begins.
     *
     * @return Slot|null The slot, or null when the provider is not free then.
     */
    protected function slotAt( Service $service, ServiceProvider $provider, CarbonImmutable $start ): ?Slot
    {
        $window = new TimeRange( $start, $start->addDay() );

        foreach ( $this->slots->resolve( $service, $provider, $window ) as $slot ) {
            if ( $slot->period->start->equalTo( $start ) ) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Runs the candidate list through `ap.bookings.availableProviders`.
     *
     * @since 1.0.0
     *
     * @param  array<int, ServiceProvider>  $providers  The providers found free.
     * @param  Service  $service  The service being booked.
     * @param  CarbonImmutable  $start  The instant the slot begins.
     *
     * @throws UnexpectedValueException When a subscriber returns something other
     *                                  than a list of providers.
     *
     * @return array<int, ServiceProvider> The candidates that survived.
     */
    protected function filterAvailableProviders( array $providers, Service $service, CarbonImmutable $start ): array
    {
        $filtered = applyFilters( 'ap.bookings.availableProviders', $providers, $service, $start );

        if ( ! is_array( $filtered ) ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.availableProviders must return an array, got %s.',
                get_debug_type( $filtered ),
            ) );
        }

        foreach ( $filtered as $provider ) {
            if ( ! $provider instanceof ServiceProvider ) {
                throw new UnexpectedValueException( sprintf(
                    'ap.bookings.availableProviders must return %s instances, got %s.',
                    ServiceProvider::class,
                    get_debug_type( $provider ),
                ) );
            }
        }

        return array_values( $filtered );
    }

    /**
     * Determines whether another active booking already holds a provider's time.
     *
     * Asked as an overlap rather than as an exact start, because a reschedule can
     * land in the middle of an appointment rather than on top of one — which the
     * unique index, keyed on the start time, would let through.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider whose diary is being checked.
     * @param  CarbonImmutable  $start  When the booking would start.
     * @param  CarbonImmutable  $end  When it would end.
     * @param  Booking|null  $excluding  A booking to ignore — the one being moved,
     *                                   which is otherwise its own clash.
     *
     * @return bool True when something else is already there.
     */
    protected function clashes(
        int $providerId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?Booking $excluding = null,
    ): bool {
        $query = Booking::query()
            ->active()
            ->where( 'provider_id', $providerId )
            ->where( 'start_time', '<', $end->utc() )
            ->where( 'end_time', '>', $start->utc() );

        if ( null !== $excluding && null !== $excluding->getKey() ) {
            $query->whereKeyNot( $excluding->getKey() );
        }

        return $query->exists();
    }

    /**
     * Builds the attributes every attempt at this booking shares.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The booking being created.
     * @param  Service  $service  The service being booked.
     * @param  CarbonImmutable  $start  The instant the slot begins.
     * @param  int  $version  The intake schema version being snapshotted.
     * @param  BookingAssignmentStrategy  $assignment  How the provider is chosen.
     *
     * @return array<string, mixed> The attributes, minus the provider and the times.
     */
    protected function baseAttributes(
        array $attributes,
        Service $service,
        CarbonImmutable $start,
        int $version,
        BookingAssignmentStrategy $assignment,
    ): array {
        return [
            'service_id'            => (int) $service->getKey(),
            'series_id'             => $attributes['series_id'] ?? null,
            'series_index'          => $attributes['series_index'] ?? null,
            'customer_name'         => $this->requiredString( $attributes, 'customer_name' ),
            'customer_email'        => $this->requiredString( $attributes, 'customer_email' ),
            'customer_phone'        => $attributes['customer_phone'] ?? null,
            'customer_timezone'     => (string) ( $attributes['customer_timezone']
                ?? $service->timezone
                ?? $this->config->get( 'artisanpack.bookings.timezone', 'UTC' ) ),
            'start_time'            => $start,
            'end_time'              => $start->addMinutes( max( 1, (int) $service->duration ) ),
            // Always `requested`, never `confirmed`, whatever the caller passed.
            // The slot is held either way, and going through the confirmation
            // transition is what fires `ap.bookings.confirmed` — a booking
            // inserted straight into `confirmed` would be one no listener ever
            // heard about.
            'status'                => BookingStatus::Requested,
            'assignment_strategy'   => $assignment,
            'intake_schema_version' => $version,
            'notes'                 => $attributes['notes'] ?? null,
        ];
    }

    /**
     * Gets the zone whose calendar day a booking's lock is taken against.
     *
     * Falls back to UTC rather than to the application's zone when the provider
     * has gone. The lock only has to be a name two racing requests agree on, and
     * a configured zone that an operator later changes would silently stop two
     * concurrent reschedules of the same booking from seeing each other.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking being written.
     *
     * @return string The provider's timezone.
     */
    protected function providerTimezone( Booking $booking ): string
    {
        return $booking->provider->timezone ?? 'UTC';
    }

    /**
     * Gets an attribute a booking cannot be written without.
     *
     * The columns behind these are NOT NULL, which sounds like enough and is
     * not: an empty string satisfies the database and produces a booking with
     * nobody's name on it and nowhere to send the confirmation. Nothing later in
     * the lifecycle can recover from that — the manage token goes to an empty
     * address, the reminder goes nowhere, and the row is found by no search an
     * administrator would think to run — so it is refused at the point the
     * caller can still do something about it.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The booking being created.
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
            throw new InvalidArgumentException( sprintf( 'A booking must name a %s.', $key ) );
        }

        return $value;
    }

    /**
     * Gets the service a booking names.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The booking being created.
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
            throw new InvalidArgumentException( 'A booking must name a service that exists.' );
        }

        return $service;
    }

    /**
     * Gets the instant a booking is asked to start at, in UTC.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The booking being created.
     *
     * @throws InvalidArgumentException When no start time is named.
     *
     * @return CarbonImmutable The start instant.
     */
    protected function resolveStart( array $attributes ): CarbonImmutable
    {
        $start = $attributes['start_time'] ?? null;

        if ( null === $start ) {
            throw new InvalidArgumentException( 'A booking must name a start time.' );
        }

        return ( $start instanceof CarbonInterface
            ? CarbonImmutable::instance( $start )
            : CarbonImmutable::parse( $start ) )->utc();
    }

    /**
     * Determines whether a new booking confirms itself.
     *
     * @since 1.0.0
     *
     * @return bool True when a booking is confirmed as soon as it is created.
     */
    protected function confirmsAutomatically(): bool
    {
        return (bool) $this->config->get( 'artisanpack.bookings.auto_confirm', true );
    }

    /**
     * Gets a candidate list with one provider removed.
     *
     * @since 1.0.0
     *
     * @param  array<int, ServiceProvider>  $candidates  The providers to filter.
     * @param  ServiceProvider  $provider  The provider to drop.
     *
     * @return array<int, ServiceProvider> The remaining candidates.
     */
    protected function without( array $candidates, ServiceProvider $provider ): array
    {
        return array_values( array_filter(
            $candidates,
            static fn ( ServiceProvider $candidate ): bool => (int) $candidate->getKey() !== (int) $provider->getKey(),
        ) );
    }

    /**
     * Determines whether a provider is one of the candidates for a slot.
     *
     * @since 1.0.0
     *
     * @param  array<int, ServiceProvider>  $candidates  The providers in the running.
     * @param  ServiceProvider  $provider  The provider to look for.
     *
     * @return bool True when the provider is among them.
     */
    protected function isCandidate( array $candidates, ServiceProvider $provider ): bool
    {
        foreach ( $candidates as $candidate ) {
            if ( (int) $candidate->getKey() === (int) $provider->getKey() ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets an array out of whatever a caller passed for the intake answers.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The raw value.
     *
     * @return array<string, mixed> The answers.
     */
    protected function arrayValue( mixed $value ): array
    {
        return is_array( $value ) ? $value : [];
    }

    /**
     * Runs a piece of work in the site the given record belongs to.
     *
     * A booking is written against a service and read back against a provider's
     * diary, each of which is site-scoped on the way in and site-stamped on the
     * way out. Ordinarily the ambient site and the record's site are the same,
     * because the record was loaded through the scope. The two paths the package
     * explicitly supports for crossing sites break that: a console command
     * looping with `SiteContext::forSite()`, and a maintenance query using
     * `acrossAllSites()`.
     *
     * Left alone, a write under a mismatched ambient site does one of two
     * things, both bad. It stamps the row with the ambient site — putting a
     * booking carrying this customer's name, email, and phone under a tenant
     * they do not belong to, and hiding it from the tenant that owns the
     * service. Or it fails halfway, because a provider or availability the write
     * needs is invisible from where it is standing.
     *
     * Pinning the record's own site for the duration removes both. A null site —
     * every row on a single-tenant installation — pins "no site", which is
     * exactly the inert state those installations already run in. `forSite()`
     * restores the previous context afterwards, nests safely, and is
     * exception-safe. Mirrors {@see SeriesService::inSiteOf()}.
     *
     * @since 1.0.0
     *
     * @param  Booking|Service  $owner  The record whose site to work in. Both
     *                                  carry `site_id` through `BelongsToSite`,
     *                                  which is what `getSiteIdColumn()` needs —
     *                                  a bare `Model` could not answer it.
     * @param  Closure  $callback  The work to do.
     *
     * @return mixed Whatever the callback returns.
     */
    protected function inSiteOf( Booking|Service $owner, Closure $callback ): mixed
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
            $owner->getAttribute( $owner->getSiteIdColumn() ),
            static fn (): mixed => $callback(),
        );
    }

    /**
     * Gets the connection bookings are written on.
     *
     * @since 1.0.0
     *
     * @return ConnectionInterface The booking table's connection.
     */
    protected function connection(): ConnectionInterface
    {
        return Booking::query()->getConnection();
    }

    /**
     * Resolves an active service by its slug, or null when none answers to it.
     *
     * The one active-only lookup {@see self::createFromFormSubmission()} and
     * {@see self::formSubmissionIsBookable()} share, so a submission naming a
     * withdrawn or unknown service is refused the same way whether it is being
     * booked or only being checked.
     *
     * @since 1.0.0
     *
     * @param  string  $slug  The service slug.
     *
     * @return Service|null The active service, or null when none matches.
     */
    private function activeServiceForSlug( string $slug ): ?Service
    {
        return Service::query()
            ->active()
            ->where( 'slug', $slug )
            ->first();
    }
}
