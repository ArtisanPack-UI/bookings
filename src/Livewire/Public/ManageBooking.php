<?php

/**
 * Self-serve booking management.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Livewire\Public;

use ArtisanPackUI\Bookings\Contracts\SlotResolver;
use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Exceptions\InvalidBookingTransitionException;
use ArtisanPackUI\Bookings\Exceptions\SlotLockTimeoutException;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Http\Controllers\Public\Concerns\ResolvesManagedBooking;
use ArtisanPackUI\Bookings\Http\Middleware\RateLimitBookings;
use ArtisanPackUI\Bookings\Http\Middleware\ResolveManageToken;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Services\BookingService;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use ArtisanPackUI\Bookings\Support\BookingWindow;
use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * The page behind the link in a customer's confirmation email.
 *
 * The JSON endpoints on
 * {@see \ArtisanPackUI\Bookings\Http\Controllers\Public\ManageBookingController}
 * are the machine-facing half of this, and a customer should not have to be one.
 * Embedded on a route carrying the `bookings.manage-token` middleware —
 * `<livewire:artisanpack-manage-booking />` — this is the same three actions with
 * a screen in front of them: what was booked, a way out of it, and a way to move
 * it.
 *
 * **The token is the whole credential and it is the whole state.** There is no
 * account behind a public booking, so nothing here is derived from a session:
 * the booking is re-resolved from the token on every request, which is what keeps
 * a page left open on a phone from acting on a booking somebody has since
 * cancelled from the other half of the API. The token lives in a `#[Locked]`
 * property because it also lives in the URL that rendered the page — Livewire's
 * update endpoint is not that URL, and a component that could not name its own
 * booking after the first render would be a read-only page with three buttons
 * that 404.
 *
 * The policy — may this still be cancelled, may it still be moved, until when —
 * is {@see ResolvesManagedBooking}'s rather than a second copy of it. A page
 * drawing a cancel button that the endpoint behind it refuses is a customer
 * clicking twice and then telephoning, which is the failure that trait exists to
 * prevent, and one that a restated `min_advance_minutes` here would reintroduce
 * on the first day somebody changed one of the two.
 *
 * Both writes go through {@see BookingService}, so `ap.bookings.cancelled` and
 * `ap.bookings.rescheduled` fire exactly once with `actor: customer` — the
 * notifications, the webhooks, and the calendar sync all hang off those and none
 * of them can tell where a change came from except by the actor. No hook is
 * registered here for the same reason: the lifecycle belongs to the service, and
 * the slots this page offers come through `SlotResolver`, so
 * `ap.bookings.availableSlots` and `ap.bookings.slotBookable` already decide what
 * a customer may pick.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ManageBooking extends Component
{
    use ResolvesManagedBooking;

    /**
     * The manage token this page is acting on behalf of.
     *
     * Locked, because it is the credential. A client that could set it could
     * point the page at any booking whose token it had guessed — which is the
     * one thing the middleware in front of the route exists to stop.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $token = '';

    /**
     * The visitor's timezone, as reported by their browser.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $timezone = '';

    /**
     * The month being browsed for a new time, as `YYYY-MM`.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $month = '';

    /**
     * The day being browsed for a new time, as `YYYY-MM-DD`.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $date = '';

    /**
     * The chosen new start, as an ISO 8601 instant in UTC.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $slotStart = '';

    /**
     * Why the customer is cancelling, when they say why.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $reason = '';

    /**
     * Whether the customer is picking a new time.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $choosingTime = false;

    /**
     * Whether the customer has been asked to confirm a cancellation.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $confirmingCancel = false;

    /**
     * What to tell the customer about the change they just made.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $notice = null;

    /**
     * Sets the page up.
     *
     * @since 1.0.0
     *
     * @param  string|null  $token  The manage token, when the host page names one
     *                              rather than leaving it in the route.
     *
     * @throws RuntimeException When the component was mounted somewhere no token
     *                          can be found.
     *
     * @return void
     */
    public function mount( ?string $token = null ): void
    {
        $this->token = $this->cleanToken( $token ?? $this->routeToken() );

        if ( '' === $this->token ) {
            // Loud rather than blank, and for the reason the trait's own
            // fallback is refused: a page that quietly renders "this link is no
            // longer valid" to every customer is a misconfiguration nobody
            // reports, because it looks exactly like an expired link.
            throw new RuntimeException(
                'ManageBooking was mounted without a manage token. Mount it on a route carrying the bookings.manage-token middleware, or pass the token in.',
            );
        }
    }

    /**
     * Asks the customer to confirm they want the appointment cancelled.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function startCancel(): void
    {
        $this->choosingTime     = false;
        $this->confirmingCancel = true;
        $this->notice           = null;
    }

    /**
     * Backs out of cancelling.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function stopCancel(): void
    {
        $this->confirmingCancel = false;
        $this->reason           = '';
    }

    /**
     * Cancels the booking, freeing the slot it was holding.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function cancel(): void
    {
        $booking = $this->booking;

        if ( null === $booking ) {
            $this->addError( 'cancel', $this->linkExpired() );

            return;
        }

        // Before the policy rather than after it, because `bookings.rate-limit:post`
        // is middleware on the endpoint this mirrors and middleware runs first.
        // Spent only on the way to a successful write, every refusal — a passed
        // deadline, an installation that does not offer cancellation — would be a
        // free round trip through a token lookup, which is the half of the surface
        // an attacker holding one leaked link would actually hammer.
        if ( ! $this->withinRateLimit() ) {
            $this->addError( 'cancel', __( 'Too many requests. Please wait a moment and try again.' ) );

            return;
        }

        if ( ! $booking->occupiesSlot() ) {
            $this->refuseCancellation( __( 'That booking can no longer be cancelled.' ) );

            return;
        }

        if ( ! $this->selfServeCancellationAllowed() ) {
            $this->refuseCancellation(
                __( 'Bookings cannot be cancelled from this link. Please contact us instead.' ),
            );

            return;
        }

        if ( $this->pastChangeDeadline( $booking ) ) {
            $this->refuseCancellation(
                __( 'It is too close to your appointment to cancel it online. Please contact us instead.' ),
            );

            return;
        }

        // Cleaned before it is measured, for the reason CancelBookingRequest
        // cleans first: the reason travels to webhooks and staff notices, so what
        // is kept has to be what was judged.
        $this->reason = trim( sanitizeText( $this->reason ) );

        $this->validateOnly( 'reason' );

        try {
            app( BookingService::class )->cancel( $booking, BookingActor::Customer, $this->cancellationReason() );
        } catch ( InvalidBookingTransitionException ) {
            // Lost to whoever moved the booking between the check above and here.
            $this->refuseCancellation( __( 'That booking can no longer be cancelled.' ) );

            return;
        }

        $this->confirmingCancel = false;
        $this->reason           = '';
        $this->notice           = __( 'Your appointment has been cancelled.' );

        $this->forgetPolicy();
    }

    /**
     * Opens the calendar so the customer can pick a new time.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function startReschedule(): void
    {
        if ( ! $this->canReschedule ) {
            $this->addError( 'slotStart', $this->rescheduleRefusal() );

            return;
        }

        $this->confirmingCancel = false;
        $this->choosingTime     = true;
        $this->notice           = null;

        if ( '' === $this->month ) {
            $this->month = $this->currentStart()?->setTimezone( $this->displayTimezone )->format( 'Y-m' ) ?? '';
        }
    }

    /**
     * Leaves the appointment where it is.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function stopReschedule(): void
    {
        $this->choosingTime = false;

        $this->forgetChosenTime();
    }

    /**
     * Moves the calendar by a month.
     *
     * @since 1.0.0
     *
     * @param  int  $months  How many months to move. Clamped to a year either
     *                       way: the template only ever sends one, and this is a
     *                       public action reached from a client that may have
     *                       sent anything.
     *
     * @return void
     */
    public function shiftMonth( int $months ): void
    {
        $months = max( -12, min( 12, $months ) );

        $this->month = $this->browsedMonth->addMonths( $months )->format( 'Y-m' );

        // The heading and the day list are computed and memoised for the request,
        // and `browsedMonth` was read a line above to work the new month out — so
        // without this the render that follows draws the month just left, and the
        // customer clicks "next" twice to move once.
        unset( $this->browsedMonth, $this->slotsByDay, $this->slotsOnDate );

        $this->forgetChosenTime();
    }

    /**
     * Chooses the day to look at.
     *
     * @since 1.0.0
     *
     * @param  string  $date  The day, as `YYYY-MM-DD`.
     *
     * @return void
     */
    public function chooseDate( string $date ): void
    {
        $this->date = $date;

        $this->canonicaliseDate();

        $this->slotStart = '';

        unset( $this->chosenStart, $this->slotsOnDate );
    }

    /**
     * Keeps a day set from the client in the one spelling everything expects.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedDate(): void
    {
        $this->chooseDate( $this->date );
    }

    /**
     * Chooses the new time.
     *
     * @since 1.0.0
     *
     * @param  string  $start  The slot's start, as an ISO 8601 instant.
     *
     * @return void
     */
    public function chooseSlot( string $start ): void
    {
        $this->slotStart = $start;

        $this->canonicaliseSlot();
    }

    /**
     * Keeps a time set from the client in the one spelling everything expects.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedSlotStart(): void
    {
        $this->canonicaliseSlot();
    }

    /**
     * Moves the booking to the time the customer picked.
     *
     * Availability is re-resolved here rather than trusted from the property the
     * slot travelled back in, exactly as
     * {@see \ArtisanPackUI\Bookings\Http\Controllers\Public\ManageBookingController::reschedule()}
     * re-resolves it: a manage link is often opened days after it was sent, and
     * the diary has to be read as it is now. That check is the authorisation on
     * the submitted time and the first half of the race
     * {@see BookingService::reschedule()} finishes under the slot lock.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function reschedule(): void
    {
        $booking = $this->booking;

        if ( null === $booking ) {
            $this->addError( 'slotStart', $this->linkExpired() );

            return;
        }

        // Before the policy, for the reason cancel() spends it there.
        if ( ! $this->withinRateLimit() ) {
            $this->addError( 'slotStart', __( 'Too many requests. Please wait a moment and try again.' ) );

            return;
        }

        if ( ! $this->canReschedule ) {
            $this->addError( 'slotStart', $this->rescheduleRefusal() );

            return;
        }

        $start = $this->chosenStart;

        if ( null === $start ) {
            $this->addError( 'slotStart', __( 'Choose a new time before confirming.' ) );

            return;
        }

        if ( null !== $booking->start_time && $start->equalTo( $booking->start_time ) ) {
            $this->addError( 'slotStart', __( 'Your booking is already at that time.' ) );

            return;
        }

        if ( null === $this->slotAt( $start ) ) {
            $this->forgetChosenTime();

            $this->addError( 'slotStart', __( 'That appointment time is not available. Please choose another.' ) );

            return;
        }

        try {
            app( BookingService::class )->reschedule( $booking, $start, BookingActor::Customer );
        } catch ( InvalidBookingTransitionException ) {
            $this->addError( 'slotStart', __( 'That booking can no longer be rescheduled.' ) );

            return;
        } catch ( SlotUnavailableException ) {
            $this->forgetChosenTime();

            $this->addError( 'slotStart', __( 'That appointment time is no longer available. Please choose another.' ) );

            return;
        } catch ( SlotLockTimeoutException ) {
            $this->addError( 'slotStart', __( 'That appointment time is busy right now. Please try again.' ) );

            return;
        }

        $this->choosingTime = false;

        $this->forgetChosenTime();

        $this->notice = __( 'Your appointment has been moved to :when (:timezone).', [
            'when'     => $start->setTimezone( $this->displayTimezone )->translatedFormat( 'l j F Y, g:i a' ),
            'timezone' => $this->displayTimezone,
        ] );

        $this->forgetPolicy();
    }

    /**
     * Gets the booking the token stands for.
     *
     * Resolved from the token on every request rather than held across them. A
     * manage page sits open on a phone for days, and the booking behind it can be
     * cancelled by staff, moved by the API, or rescheduled from another tab in
     * the meantime — all of which this then shows rather than acting on a copy
     * from before any of it happened.
     *
     * @since 1.0.0
     *
     * @return Booking|null The booking, or null when the token no longer manages one.
     */
    #[Computed]
    public function booking(): ?Booking
    {
        // On the render that follows the middleware, the booking has already been
        // looked up — taking it back saves the second identical query, and it is
        // the same row either way because the middleware resolved it from this
        // same token.
        $attached = request()->attributes->get( ResolveManageToken::BOOKING_ATTRIBUTE );

        if ( $attached instanceof Booking && app( ManageTokenService::class )->verifyFor( $attached, $this->token ) ) {
            return $attached;
        }

        return app( ManageTokenService::class )->findBooking( $this->token );
    }

    /**
     * Determines whether the customer may still cancel from this link.
     *
     * @since 1.0.0
     *
     * @return bool True when the cancel button should be drawn.
     */
    #[Computed]
    public function canCancel(): bool
    {
        return $this->changesStillOpen() && $this->selfServeCancellationAllowed();
    }

    /**
     * Determines whether the customer may still move the appointment.
     *
     * A withdrawn service refuses the move rather than the whole link. Availability
     * for a service nobody is maintaining is computed from rules nobody is
     * maintaining, so the link goes on working for reading and cancelling — which
     * is the part a customer whose service has been retired most needs.
     *
     * @since 1.0.0
     *
     * @return bool True when a new time may be chosen.
     */
    #[Computed]
    public function canReschedule(): bool
    {
        $booking = $this->booking;

        return $this->changesStillOpen()
            && null !== $booking?->service
            && (bool) $booking->service->is_active;
    }

    /**
     * Gets the instant after which nothing may be changed from this link.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable|null The deadline, or null when there is none.
     */
    #[Computed]
    public function changesAllowedUntil(): ?CarbonImmutable
    {
        $booking = $this->booking;

        return null === $booking ? null : $this->changeDeadline( $booking );
    }

    /**
     * Gets what has become of the booking, in words a customer reads.
     *
     * Said out loud rather than left to be inferred from which buttons are
     * missing. `BookingStatus` carries no customer-facing label of its own — its
     * values are the API's contract and the database's — and the wording here is
     * the page's business: "no_show" is a fact for staff and an accusation to
     * read about oneself.
     *
     * @since 1.0.0
     *
     * @return string The status, translated, or an empty string when there is
     *                no booking to describe.
     */
    #[Computed]
    public function statusLabel(): string
    {
        return match ( $this->booking?->status ) {
            BookingStatus::Requested => __( 'Awaiting confirmation' ),
            BookingStatus::Confirmed => __( 'Confirmed' ),
            BookingStatus::Cancelled => __( 'Cancelled' ),
            BookingStatus::Completed => __( 'Completed' ),
            BookingStatus::NoShow    => __( 'Not attended' ),
            default                  => '',
        };
    }

    /**
     * Gets the timezone the times on screen are stated in.
     *
     * The browser's, once it has said; the zone the booking was made in until
     * then — which is the zone the confirmation email quoted, and therefore the
     * one the customer is expecting to read the appointment in.
     *
     * @since 1.0.0
     *
     * @return string The IANA zone name.
     */
    #[Computed]
    public function displayTimezone(): string
    {
        if ( '' !== $this->timezone ) {
            return $this->timezone;
        }

        $booking = $this->booking;

        if ( null !== $booking && is_string( $booking->customer_timezone ) && '' !== $booking->customer_timezone ) {
            return $booking->customer_timezone;
        }

        if ( null !== $booking?->service ) {
            return BookingWindow::timezoneFor( $booking->service );
        }

        return (string) ( config( 'artisanpack.bookings.timezone' ) ?: 'UTC' );
    }

    /**
     * Gets the month the calendar is showing.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable The first instant of the month, in the display zone.
     */
    #[Computed]
    public function browsedMonth(): CarbonImmutable
    {
        foreach ( [ $this->month, $this->date ] as $candidate ) {
            if ( '' === $candidate ) {
                continue;
            }

            try {
                return CarbonImmutable::parse( $candidate, $this->displayTimezone )->startOfMonth();
            } catch ( Throwable ) {
                continue;
            }
        }

        return ( $this->currentStart() ?? CarbonImmutable::now() )
            ->setTimezone( $this->displayTimezone )
            ->startOfMonth();
    }

    /**
     * Gets the bookable slots of the browsed month, grouped by day.
     *
     * Resolved for the booking's own service and provider, so a customer moving
     * an appointment stays with the person they booked. Read through
     * `SlotResolver`, which is what puts `ap.bookings.availableSlots` and
     * `ap.bookings.slotBookable` in front of everything this page offers.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, Slot>> The slots, keyed by `YYYY-MM-DD`
     *                                         in the display timezone.
     */
    #[Computed]
    public function slotsByDay(): array
    {
        $booking = $this->booking;
        $service = $booking?->service;

        // Gated on the policy rather than on `$choosingTime`, which is an ordinary
        // public property a client can set. Without this, the holder of a manage
        // link for a withdrawn service could flip that flag and read a month of
        // that service's availability at a time — which nothing else public
        // discloses, since `ServiceController` resolves only active services.
        if ( null === $booking || null === $service || ! $this->canReschedule ) {
            return [];
        }

        $window = BookingWindow::month( $service, $this->browsedMonth->format( 'Y-m' ), $this->displayTimezone );

        if ( null === $window ) {
            return [];
        }

        $byDay = [];

        foreach ( app( SlotResolver::class )->resolve( $service, $booking->provider, $window ) as $slot ) {
            $byDay[ $slot->period->start->copy()->setTimezone( $this->displayTimezone )->format( 'Y-m-d' ) ][] = $slot;
        }

        return $byDay;
    }

    /**
     * Gets the slots on the chosen day.
     *
     * Named `slotsOnDate` rather than `slots` because Livewire's component base
     * class already has a `$slots` property — the one holding a component's Blade
     * slots — and a computed property of that name never runs.
     *
     * @since 1.0.0
     *
     * @return array<int, Slot> The slots, ascending by start.
     */
    #[Computed]
    public function slotsOnDate(): array
    {
        return $this->slotsByDay[ $this->date ] ?? [];
    }

    /**
     * Gets the instant the customer chose, when they chose a readable one.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable|null The chosen start, in UTC.
     */
    #[Computed]
    public function chosenStart(): ?CarbonImmutable
    {
        if ( '' === $this->slotStart ) {
            return null;
        }

        try {
            return CarbonImmutable::parse( str_replace( ' ', '+', $this->slotStart ) )->utc();
        } catch ( Throwable ) {
            return null;
        }
    }

    /**
     * Keeps a browser-reported timezone honest.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedTimezone(): void
    {
        $this->timezone = $this->cleanTimezone( $this->timezone );

        // Everything dated on screen is computed and memoised for the request, so
        // the browser's zone has to invalidate them or it lands a round trip late.
        unset( $this->displayTimezone, $this->browsedMonth, $this->slotsByDay, $this->slotsOnDate );
    }

    /**
     * Renders the page.
     *
     * @since 1.0.0
     *
     * @return View The page's view.
     */
    public function render(): View
    {
        return view( 'bookings::livewire.public.manage-booking' );
    }

    /**
     * Gets the validation rules that apply to what the customer types.
     *
     * The one free-text field on the page, carrying the rule its counterpart on
     * {@see \ArtisanPackUI\Bookings\Http\Requests\Public\CancelBookingRequest}
     * carries. The two are the same submission through different doors, and a
     * reason one keeps and the other refuses is a bug only findable by turning
     * JavaScript off.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, string>> The rules.
     */
    protected function rules(): array
    {
        return [
            'reason' => [ 'nullable', 'string', 'max:1000' ],
        ];
    }

    /**
     * Gets the names to use for the fields in validation messages.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The attribute names.
     */
    protected function validationAttributes(): array
    {
        return [
            'reason' => __( 'reason' ),
        ];
    }

    /**
     * Gets the reason the customer gave, when they gave one.
     *
     * @since 1.0.0
     *
     * @return string|null The reason, or null when the box was left empty.
     */
    protected function cancellationReason(): ?string
    {
        return '' === $this->reason ? null : $this->reason;
    }

    /**
     * Determines whether the booking is still open to change at all.
     *
     * @since 1.0.0
     *
     * @return bool True when it holds a slot and the deadline has not passed.
     */
    protected function changesStillOpen(): bool
    {
        $booking = $this->booking;

        return null !== $booking && $booking->occupiesSlot() && ! $this->pastChangeDeadline( $booking );
    }

    /**
     * Gets the booking's current start, when it has one.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable|null The start, in UTC.
     */
    protected function currentStart(): ?CarbonImmutable
    {
        $start = $this->booking?->start_time;

        return null === $start ? null : CarbonImmutable::instance( $start )->utc();
    }

    /**
     * Gets the slot starting at an instant, if the diary is offering one.
     *
     * The window runs a day forward from the instant asked about, which is the
     * window {@see \ArtisanPackUI\Bookings\Http\Controllers\Public\ManageBookingController::isBookable()}
     * asks the same question with — deliberately, because the two have to agree.
     * A slot's length is not knowable from here: a provider can be attached at a
     * duration of their own, and `ap.bookings.slotDuration` can change it again,
     * so a window cut to the local day would refuse a late slot the API would
     * have taken.
     *
     * That is why the booking window is enforced separately here rather than by
     * resolving inside it. On the endpoint this mirrors, `booking_window`'s
     * bounds are enforced by `RescheduleBookingRequest` before the controller
     * resolves anything — and there is no request object on this side, so
     * without this check a client setting `$slotStart` by hand could move an
     * appointment to a free instant years beyond the diary the installation
     * takes bookings in. The bounds are read through `BookingWindow::day()`
     * rather than restated from config, so the two cannot drift and a
     * non-positive `max_advance_minutes` reads as no maximum here exactly as it
     * does everywhere else.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable  $start  The instant the slot would begin.
     *
     * @return Slot|null The slot, or null when nothing is free then, or when the
     *                   instant is outside the window bookings are taken in.
     */
    protected function slotAt( CarbonImmutable $start ): ?Slot
    {
        $booking = $this->booking;
        $service = $booking?->service;

        if ( null === $booking || null === $service ) {
            return null;
        }

        if ( ! $this->withinBookingWindow( $service, $start ) ) {
            return null;
        }

        $window = new TimeRange( $start, $start->addDay() );

        foreach ( app( SlotResolver::class )->resolve( $service, $booking->provider, $window ) as $slot ) {
            if ( $slot->period->start->equalTo( $start ) ) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Determines whether an instant is inside the diary bookings are taken in.
     *
     * Asked of the clipped day the instant falls on rather than of the config
     * directly. `BookingWindow` is what the widget, the API, and the plain form
     * all clip through, and a fourth reading of `min_advance_minutes` and
     * `max_advance_minutes` here is how a page comes to refuse a time the
     * endpoint would take — or, worse, take one it would refuse.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being booked.
     * @param  CarbonImmutable  $start  The instant being asked about.
     *
     * @return bool True when a booking may start then.
     */
    protected function withinBookingWindow( Service $service, CarbonImmutable $start ): bool
    {
        $zone    = BookingWindow::timezoneFor( $service );
        $allowed = BookingWindow::day( $service, $start->setTimezone( $zone )->format( 'Y-m-d' ), $zone );

        if ( null === $allowed ) {
            return false;
        }

        // `greaterThan` rather than `greaterThanOrEqualTo` at the far end, because
        // `RescheduleBookingRequest` takes an instant exactly at the limit.
        return ! $start->lessThan( $allowed->start ) && ! $start->greaterThan( $allowed->end );
    }

    /**
     * Says why a reschedule is not on offer.
     *
     * @since 1.0.0
     *
     * @return string The message to show.
     */
    protected function rescheduleRefusal(): string
    {
        $booking = $this->booking;

        if ( null === $booking || ! $booking->occupiesSlot() ) {
            return __( 'That booking can no longer be rescheduled.' );
        }

        if ( $this->pastChangeDeadline( $booking ) ) {
            return __( 'It is too close to your appointment to reschedule it online. Please contact us instead.' );
        }

        return __( 'That service is no longer taking bookings. Please contact us instead.' );
    }

    /**
     * Refuses a cancellation, and puts the page back where it was.
     *
     * @since 1.0.0
     *
     * @param  string  $message  What to tell the customer.
     *
     * @return void
     */
    protected function refuseCancellation( string $message ): void
    {
        $this->confirmingCancel = false;

        $this->addError( 'cancel', $message );

        $this->forgetPolicy();
    }

    /**
     * Gets the message every dead link gets.
     *
     * @since 1.0.0
     *
     * @return string The message.
     */
    protected function linkExpired(): string
    {
        return __( 'That booking link is no longer valid. Please check your confirmation email.' );
    }

    /**
     * Spends the same rate-limit bucket the routed writes spend.
     *
     * Livewire's calls go to Livewire's own update endpoint, on the host
     * application's routes, so without this the two writes on this page would be
     * unguarded while the identical endpoints behind them are limited.
     *
     * @since 1.0.0
     *
     * @return bool True when there was room in the bucket.
     */
    protected function withinRateLimit(): bool
    {
        return app( RateLimitBookings::class )->consume( 'post', request() );
    }

    /**
     * Drops the chosen time, and everything derived from it this request.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function forgetChosenTime(): void
    {
        $this->date      = '';
        $this->slotStart = '';

        unset( $this->chosenStart, $this->slotsOnDate );
    }

    /**
     * Drops what this request already decided about a booking that has changed.
     *
     * The computed properties are memoised per request, and a write happens
     * halfway through one — so the render that follows a cancellation would
     * otherwise be drawn from the policy as it stood before it, and offer the
     * customer a cancel button for an appointment they have just cancelled.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function forgetPolicy(): void
    {
        unset( $this->canCancel, $this->canReschedule, $this->changesAllowedUntil, $this->slotsByDay, $this->slotsOnDate );
    }

    /**
     * Gets the token out of the route the page was reached on.
     *
     * @since 1.0.0
     *
     * @return string The token, or an empty string when the route names none.
     */
    protected function routeToken(): string
    {
        $token = request()->route( 'token' );

        return is_string( $token ) ? $token : '';
    }

    /**
     * Keeps a token honest before anything is looked up with it.
     *
     * The same shape {@see ResolveManageToken} demands: 64 hexadecimal
     * characters and nothing else can be a manage token, so anything else is
     * refused without a query.
     *
     * @since 1.0.0
     *
     * @param  string  $token  The token as given.
     *
     * @return string The token, or an empty string when it is not one.
     */
    protected function cleanToken( string $token ): string
    {
        $token = strtolower( trim( $token ) );

        return 1 === preg_match( '/^[0-9a-f]{64}$/', $token ) ? $token : '';
    }

    /**
     * Gets a timezone name it is safe to render times in.
     *
     * @since 1.0.0
     *
     * @param  string  $timezone  The reported zone.
     *
     * @return string The zone, or an empty string when it is not one.
     */
    protected function cleanTimezone( string $timezone ): string
    {
        $timezone = trim( $timezone );

        if ( '' === $timezone ) {
            return '';
        }

        return in_array( $timezone, timezone_identifiers_list(), true ) ? $timezone : '';
    }

    /**
     * Rewrites the chosen day into the one spelling everything else expects.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function canonicaliseDate(): void
    {
        if ( '' === $this->date ) {
            return;
        }

        try {
            $this->date = CarbonImmutable::parse( $this->date, $this->displayTimezone )->format( 'Y-m-d' );
        } catch ( Throwable ) {
            // A day nothing can read is no day at all. The template prints it
            // back, and a value that only fails once Carbon reaches it takes the
            // host page down with a 500 for anybody who can edit a property.
            $this->date = '';
        }
    }

    /**
     * Rewrites the chosen time into the one spelling everything else expects.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function canonicaliseSlot(): void
    {
        if ( '' === $this->slotStart ) {
            return;
        }

        try {
            $this->slotStart = BookingWidget::instantKey(
                CarbonImmutable::parse( str_replace( ' ', '+', $this->slotStart ) ),
            );
        } catch ( Throwable ) {
            $this->slotStart = '';
        }

        unset( $this->chosenStart );
    }
}
