<?php

/**
 * Public booking widget.
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
use ArtisanPackUI\Bookings\Exceptions\IntakeValidationException;
use ArtisanPackUI\Bookings\Exceptions\SlotLockTimeoutException;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Http\Middleware\RateLimitBookings;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\BookingService;
use ArtisanPackUI\Bookings\Services\IntakeFieldValidator;
use ArtisanPackUI\Bookings\Support\BookingWindow;
use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\WidgetConfirmation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ViewErrorBag;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

use function __;
use function app;
use function array_key_exists;
use function config;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function old;
use function request;
use function sanitizeEmail;
use function sanitizeText;
use function session;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function timezone_identifiers_list;
use function trim;
use function view;

/**
 * The customer-facing booking flow: service, provider, time, details.
 *
 * Embedded on a marketing page as `<livewire:artisanpack-booking-widget />`,
 * optionally pinned to one service. Every step is rendered by the server, so the
 * whole flow — the service list, the calendar, the slot list, the intake form —
 * is on the page as ordinary HTML before any JavaScript has run. That is not
 * merely progressive-enhancement etiquette: a widget dropped onto somebody
 * else's landing page has to survive a script blocker, a CSP that refuses
 * Livewire's bundle, and the several seconds before a slow connection has
 * finished fetching it.
 *
 * So each step is a real `<form>`. Step navigation is a `GET` back to the host
 * page carrying the choice in the query string — which this component reads back
 * on mount — and the final submission is a `POST` to
 * {@see \ArtisanPackUI\Bookings\Http\Controllers\Public\WidgetController}, which
 * runs the same request object the JSON API does and redirects back here with
 * the confirmation flashed. With JavaScript, Livewire intercepts all of it and
 * nothing navigates; without it, every one of those forms still works.
 *
 * What JavaScript adds is what only the browser knows: the visitor's timezone,
 * captured through `Intl.DateTimeFormat().resolvedOptions().timeZone` and used
 * to render the slot list in their own clock. Without it the times are shown in
 * the service's zone, labelled as such, which is correct rather than merely
 * degraded.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingWidget extends Component
{
    /**
     * The service the embed pinned this widget to, when it pinned one.
     *
     * Locked, because it is the difference between a widget that books haircuts
     * and one that books anything on the site. A client that could set it could
     * step out of the embed the page author chose.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    #[Locked]
    public ?string $pinnedService = null;

    /**
     * The slug of the service being booked.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url( as: 'bookingService', except: '' )]
    public string $serviceSlug = '';

    /**
     * The chosen provider: their slug, `any`, or empty while undecided.
     *
     * Three states in one property rather than a nullable id plus a flag,
     * because "not chosen yet" and "no preference" are different answers that
     * lead to different screens, and a null would have to mean both.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url( as: 'bookingProvider', except: '' )]
    public string $providerSlug = '';

    /**
     * The month being browsed, as `YYYY-MM` in the display timezone.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url( as: 'bookingMonth', except: '' )]
    public string $month = '';

    /**
     * The chosen day, as `YYYY-MM-DD` in the display timezone.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url( as: 'bookingDate', except: '' )]
    public string $date = '';

    /**
     * The chosen slot's start, as an ISO 8601 instant in UTC.
     *
     * Carried as an instant rather than as a wall-clock time so that it means
     * the same appointment whichever zone the page is later read in.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url( as: 'bookingSlot', except: '' )]
    public string $slotStart = '';

    /**
     * The visitor's timezone, as reported by their browser.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $timezone = '';

    /**
     * The customer's name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $customerName = '';

    /**
     * The customer's email address.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $customerEmail = '';

    /**
     * The customer's telephone number.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $customerPhone = '';

    /**
     * Anything else the customer wanted to say.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $notes = '';

    /**
     * The answers to the service's intake form, keyed by field name.
     *
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    public array $intake = [];

    /**
     * What to tell the customer once a booking exists.
     *
     * Holds the few facts the confirmation screen shows rather than the booking
     * itself. A Livewire property is serialised into the page and signed but not
     * encrypted, and a booking carries an email address, a telephone number, and
     * whatever the intake form asked.
     *
     * @since 1.0.0
     *
     * @var array<string, string>|null
     */
    public ?array $confirmation = null;

    /**
     * Sets the widget up.
     *
     * @since 1.0.0
     *
     * @param  string|null  $service  The slug of the service to pin the widget
     *                                to, or null to let the visitor choose.
     * @param  string|null  $timezone  The zone to render times in before the
     *                                 browser has reported its own.
     *
     * @return void
     */
    public function mount( ?string $service = null, ?string $timezone = null ): void
    {
        if ( is_string( $service ) && '' !== trim( $service ) ) {
            $this->pinnedService = trim( $service );
            $this->serviceSlug   = $this->pinnedService;
        }

        // Before the canonicalisers, and the order is load-bearing: each of
        // them reads `displayTimezone`, which is computed and memoised for the
        // request — so assigning the zone afterwards would cache the service's
        // zone and then quietly ignore the one the embed asked for, in the slot
        // list and everywhere else that reads it.
        $this->timezone = $this->cleanTimezone( (string) $timezone );

        $this->recoverQueryString();

        $this->canonicaliseMonth();
        $this->canonicaliseDate();
        $this->canonicaliseSlot();

        // A site offering one service should not open on a list of one.
        if ( '' === $this->serviceSlug && 1 === count( $this->services ) ) {
            $this->serviceSlug = (string) $this->services[0]->slug;
        }

        $this->readFlashedConfirmation();
        $this->readOldInput();
        $this->readFlashedErrors();
        $this->seedMultiAnswerIntake();
    }

    /**
     * Chooses the service to book.
     *
     * @since 1.0.0
     *
     * @param  string  $slug  The service's slug.
     *
     * @return void
     */
    public function chooseService( string $slug ): void
    {
        if ( null !== $this->pinnedService ) {
            return;
        }

        $this->serviceSlug = $slug;

        $this->forgetChoicesBelow( 'service' );

        // The new service asks its own questions, so the old service's answers
        // go and the new one's multi-answer fields are seeded.
        $this->intake = [];

        unset( $this->intakeFields );

        $this->seedMultiAnswerIntake();
    }

    /**
     * Chooses the provider, or declines to.
     *
     * @since 1.0.0
     *
     * @param  string  $slug  The provider's slug, or `any` for no preference.
     *
     * @return void
     */
    public function chooseProvider( string $slug ): void
    {
        $this->providerSlug = $slug;

        $this->forgetChoicesBelow( 'provider' );
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

        $this->forgetChoicesBelow( 'date' );
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
        $this->canonicaliseDate();

        $this->slotStart = '';
    }

    /**
     * Chooses the slot to book.
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
     * Keeps a slot set from the client in the one spelling everything expects.
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
     * Moves the calendar by a month.
     *
     * @since 1.0.0
     *
     * @param  int  $months  How many months to move, positive or negative.
     *                       Clamped to a year either way: the template only ever
     *                       sends one, and this is a public Livewire action, so
     *                       the number arrives from a client that may have sent
     *                       anything. `addMonths( PHP_INT_MAX )` is an exception
     *                       rather than a date, and no booking window reaches
     *                       further than a year anyway.
     *
     * @return void
     */
    public function shiftMonth( int $months ): void
    {
        $months = max( -12, min( 12, $months ) );

        $this->month = $this->browsedMonth->addMonths( $months )->format( 'Y-m' );

        $this->forgetChoicesBelow( 'provider' );
    }

    /**
     * Goes back one step.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function back(): void
    {
        match ( $this->step ) {
            'details'  => $this->forgetChosenSlot(),
            'slot'     => $this->forgetChoicesBelow( $this->offersProviderChoice ? 'service' : 'start' ),
            'provider' => $this->forgetChoicesBelow( 'start' ),
            default    => null,
        };
    }

    /**
     * Books the chosen slot.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function book(): void
    {
        $service = $this->service;

        if ( null === $service ) {
            $this->addError( 'serviceSlug', __( 'Choose a service before booking.' ) );

            return;
        }

        // Cleaned before the rules run rather than after, for the reason
        // StoreBookingRequest cleans first: what the rules pass judgement on has
        // to be what will actually be stored, so a name whose markup strips to
        // nothing fails `required` here instead of being written as an empty
        // string.
        $this->scrubCustomerInput();

        $this->validate();

        if ( ! app( RateLimitBookings::class )->consume( 'post', request() ) ) {
            $this->addError( 'slotStart', __( 'Too many requests. Please wait a moment and try again.' ) );

            return;
        }

        $slot = $this->chosenSlot();

        if ( null === $slot ) {
            $this->forgetChosenSlot();

            $this->addError( 'slotStart', __( 'That appointment time is no longer available. Please choose another.' ) );

            return;
        }

        try {
            $booking = app( BookingService::class )->create( [
                'service'           => $service,
                'provider_id'       => null === $this->provider ? null : (int) $this->provider->getKey(),
                'start_time'        => $slot->period->start,
                'customer_name'     => $this->customerName,
                'customer_email'    => $this->customerEmail,
                'customer_phone'    => '' === $this->customerPhone ? null : $this->customerPhone,
                'customer_timezone' => $this->displayTimezone,
                'notes'             => '' === $this->notes ? null : $this->notes,
                'intake_data'       => $this->intake,
            ] );
        } catch ( IntakeValidationException $refused ) {
            foreach ( $refused->errors()->toArray() as $field => $messages ) {
                $this->addError( 'intake.' . $field, (string) ( $messages[0] ?? __( 'That answer could not be accepted.' ) ) );
            }

            return;
        } catch ( SlotUnavailableException ) {
            $this->forgetChosenSlot();

            $this->addError( 'slotStart', __( 'That appointment time is no longer available. Please choose another.' ) );

            return;
        } catch ( SlotLockTimeoutException ) {
            $this->addError( 'slotStart', __( 'That appointment time is busy right now. Please try again.' ) );

            return;
        } catch ( InvalidArgumentException $rejected ) {
            // A fixed message rather than the exception's own, for the reason
            // WidgetController::providerRefused() gives: `BookingService`
            // raises this from several places and only one of them is a
            // sentence a customer should read.
            Log::warning( 'A booking from the widget was refused.', [
                'exception' => $rejected->getMessage(),
            ] );

            $this->addError( 'providerSlug', __( 'That appointment could not be booked. Please choose another time.' ) );

            return;
        }

        $this->confirmation = WidgetConfirmation::forBooking( $booking, $this->displayTimezone );
    }

    /**
     * Starts the flow again from an empty form.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function bookAnother(): void
    {
        $this->confirmation = null;

        $this->reset( [ 'customerName', 'customerEmail', 'customerPhone', 'notes', 'intake' ] );

        $this->forgetChoicesBelow( null === $this->pinnedService ? 'start' : 'service' );

        $this->seedMultiAnswerIntake();
    }

    /**
     * Gets the step the flow is on.
     *
     * Derived rather than stored. A stored step is a second source of truth
     * about the same choices, and the two disagree the moment somebody arrives
     * on a link that names a slot but no service.
     *
     * @since 1.0.0
     *
     * @return string One of `service`, `provider`, `slot`, `details`, `done`.
     */
    #[Computed]
    public function step(): string
    {
        if ( null !== $this->confirmation ) {
            return 'done';
        }

        if ( null === $this->service ) {
            return 'service';
        }

        if ( $this->offersProviderChoice && '' === $this->providerSlug ) {
            return 'provider';
        }

        return null === $this->chosenStart ? 'slot' : 'details';
    }

    /**
     * Gets the instant the customer chose, when they chose a real one.
     *
     * Parsed here rather than in the template, because the value arrives from a
     * query string or from a client-controlled property and the details step
     * prints it. An unreadable instant means no slot has been chosen — which
     * puts the customer back on the slot list — rather than an exception raised
     * halfway through rendering somebody else's marketing page.
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
            // The space is a `+` that survived a round trip through a query
            // string and did not survive being decoded out of one. Slots are
            // emitted with a `Z` precisely so that never happens, but a link
            // somebody pasted, a mail client rewrote, or an older page produced
            // still carries `+00:00` — and a widget that answers "choose a time"
            // to a customer who just chose one is the worst version of this.
            return CarbonImmutable::parse( str_replace( ' ', '+', $this->slotStart ) )->utc();
        } catch ( Throwable ) {
            return null;
        }
    }

    /**
     * Names an instant in the form the widget puts in a URL.
     *
     * `Z` rather than `+00:00`, because the value travels through a query
     * string, and a `+` in one decodes to a space. An offset written that way
     * comes back unparseable, which puts a customer who chose a time back on the
     * list of times with nothing said about why.
     *
     * @since 1.0.0
     *
     * @param  CarbonInterface  $moment  The instant to name.
     *
     * @return string The instant, in UTC, as `YYYY-MM-DDTHH:MM:SSZ`.
     */
    public static function instantKey( CarbonInterface $moment ): string
    {
        return $moment->copy()->utc()->format( 'Y-m-d\TH:i:s\Z' );
    }

    /**
     * Gets the services on offer.
     *
     * @since 1.0.0
     *
     * @return array<int, Service> The bookable services, by name.
     */
    #[Computed]
    public function services(): array
    {
        $query = Service::query()->active()->orderBy( 'name' );

        if ( null !== $this->pinnedService ) {
            $query->where( 'slug', $this->pinnedService );
        }

        return $query->get()->all();
    }

    /**
     * Gets the service being booked.
     *
     * @since 1.0.0
     *
     * @return Service|null The service, or null while none is chosen.
     */
    #[Computed]
    public function service(): ?Service
    {
        if ( '' === $this->serviceSlug ) {
            return null;
        }

        foreach ( $this->services as $service ) {
            if ( $service->slug === $this->serviceSlug ) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Gets the providers the service can be booked with.
     *
     * @since 1.0.0
     *
     * @return array<int, ServiceProvider> The bookable providers.
     */
    #[Computed]
    public function providers(): array
    {
        return null === $this->service ? [] : $this->service->bookableProviders();
    }

    /**
     * Determines whether the customer is asked to pick a provider.
     *
     * Only worth asking when there is a choice to make. One provider — or none,
     * where the service falls back to its default — is not a decision, it is a
     * screen between the customer and a time.
     *
     * @since 1.0.0
     *
     * @return bool True when the provider step is shown.
     */
    #[Computed]
    public function offersProviderChoice(): bool
    {
        return count( $this->providers ) > 1;
    }

    /**
     * Gets the provider the customer asked for, when they asked for one.
     *
     * @since 1.0.0
     *
     * @return ServiceProvider|null The provider, or null for no preference.
     */
    #[Computed]
    public function provider(): ?ServiceProvider
    {
        if ( '' === $this->providerSlug || 'any' === $this->providerSlug ) {
            return null;
        }

        foreach ( $this->providers as $provider ) {
            if ( $provider->slug === $this->providerSlug ) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Gets the timezone the times on screen are stated in.
     *
     * The browser's, once it has said; the service's until then, and for anybody
     * whose browser never will.
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

        return null === $this->service
            ? (string) ( config( 'artisanpack.bookings.timezone' ) ?: 'UTC' )
            : BookingWindow::timezoneFor( $this->service );
    }

    /**
     * Gets the bookable slots of the browsed month, grouped by day.
     *
     * One resolve per render rather than one per day. The days a customer is
     * offered and the times behind each of them are the same question asked at
     * two zoom levels, and asking it twice would double the work behind the
     * busiest screen in the package.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, Slot>> The slots, keyed by `YYYY-MM-DD`
     *                                         in the display timezone.
     */
    #[Computed]
    public function slotsByDay(): array
    {
        $service = $this->service;

        if ( null === $service ) {
            return [];
        }

        $window = BookingWindow::month( $service, $this->browsedMonth->format( 'Y-m' ), $this->displayTimezone );

        if ( null === $window ) {
            return [];
        }

        $byDay = [];

        foreach ( app( SlotResolver::class )->resolve( $service, $this->provider, $window ) as $slot ) {
            $byDay[ $slot->period->start->copy()->setTimezone( $this->displayTimezone )->format( 'Y-m-d' ) ][] = $slot;
        }

        return $byDay;
    }

    /**
     * Gets the slots on the chosen day.
     *
     * Named `slotsOnDate` rather than the obvious `slots` because Livewire's
     * component base class already has a `$slots` property — the one holding a
     * component's Blade slots — and a computed property of that name never runs:
     * the real property wins, the template reads an empty array, and every day
     * on the calendar renders as fully booked with nothing raised anywhere.
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
     * Gets the questions the service's intake form asks.
     *
     * @since 1.0.0
     *
     * @return array<int, array{name: string, type: string, label: string, required: bool, options: array<int, string>, rules: array<int, string>}> The fields.
     */
    #[Computed]
    public function intakeFields(): array
    {
        if ( null === $this->service ) {
            return [];
        }

        return app( IntakeFieldValidator::class )->fieldsFor(
            $this->service,
            (int) $this->service->intake_schema_version,
        );
    }

    /**
     * Renders the widget.
     *
     * @since 1.0.0
     *
     * @return View The widget's view.
     */
    public function render(): View
    {
        return view( 'bookings::livewire.public.booking-widget' );
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

        // Everything on screen is dated in this zone, and all of it is computed
        // and memoised for the request — so the value that arrives has to
        // invalidate them or the browser's zone lands one round trip late.
        unset( $this->displayTimezone, $this->slotsByDay, $this->slotsOnDate, $this->browsedMonth );
    }

    /**
     * Gets the month the calendar is showing.
     *
     * Computed rather than protected so the template reads the month from here
     * too. `$this->month` is client-controlled, and a second parse in the view —
     * outside this method's `try` — is a 500 on somebody else's marketing page
     * for anybody who can edit a query string.
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

        return CarbonImmutable::now( $this->displayTimezone )->startOfMonth();
    }

    /**
     * Gets the validation rules that apply to the details step.
     *
     * The customer's own fields carry the same rules as their counterparts on
     * {@see \ArtisanPackUI\Bookings\Http\Requests\Public\StoreBookingRequest}
     * — `customerName` against `customer_name`, `slotStart` against
     * `start_time`, and so on. The two are the JavaScript and the
     * no-JavaScript halves of one form, and a name one accepts and the other
     * refuses is a bug a visitor can only find by turning JavaScript off.
     *
     * It is a mapping rather than a copy, and the parts that differ are the
     * parts each half enforces elsewhere. `service_slug`, `provider_id`, and
     * `customer_timezone` have no rules here because nothing on this side
     * accepts them as free text: the service and the provider are matched
     * against the lists the customer was shown, and the zone against the ones
     * this machine knows. The booking window is enforced by the slot having to
     * still be on offer, which `BookingWindow` has already clipped. And the
     * intake answers are absent on purpose — they are judged by
     * `IntakeFieldValidator` against the schema version the booking is captured
     * at, and a second copy of those rules here could only ever disagree.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, string>> The rules.
     */
    protected function rules(): array
    {
        return [
            'customerName'  => [ 'required', 'string', 'max:255' ],
            'customerEmail' => [ 'required', 'string', 'email', 'max:255' ],
            'customerPhone' => [ 'nullable', 'string', 'max:50' ],
            'notes'         => [ 'nullable', 'string', 'max:2000' ],
            'slotStart'     => [ 'required', 'string', 'date' ],
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
            'customerName'  => __( 'name' ),
            'customerEmail' => __( 'email address' ),
            'customerPhone' => __( 'telephone number' ),
            'notes'         => __( 'notes' ),
            'slotStart'     => __( 'appointment time' ),
        ];
    }

    /**
     * Gets the chosen slot, if it is still one the service is offering.
     *
     * Re-resolved rather than trusted. The slot travelled to the browser and
     * back through a property a client controls, and in between somebody else
     * may have booked it — so this is both the authorisation check on the
     * submitted time and the first half of the race the domain's lock finishes.
     *
     * @since 1.0.0
     *
     * @return Slot|null The slot, or null when it is no longer on offer.
     */
    protected function chosenSlot(): ?Slot
    {
        $start = $this->chosenStart;

        if ( null === $start ) {
            return null;
        }

        $wanted = self::instantKey( $start );

        foreach ( $this->slotsByDay as $slots ) {
            foreach ( $slots as $slot ) {
                if ( self::instantKey( $slot->period->start ) === $wanted ) {
                    return $slot;
                }
            }
        }

        // A slot chosen on a day the customer has since navigated away from is
        // still a slot they chose. Falling back to a direct read of the chosen
        // instant rather than only trusting the grouped month keeps a deep link
        // — `?bookingSlot=…` with no `bookingDate` — bookable.
        return $this->resolveSlotDirectly();
    }

    /**
     * Looks the chosen instant up on its own day.
     *
     * @since 1.0.0
     *
     * @return Slot|null The slot, or null when nothing is free at that instant.
     */
    protected function resolveSlotDirectly(): ?Slot
    {
        $service = $this->service;
        $start   = $this->chosenStart;

        if ( null === $service || null === $start ) {
            return null;
        }

        $window = BookingWindow::day(
            $service,
            $start->setTimezone( $this->displayTimezone )->format( 'Y-m-d' ),
            $this->displayTimezone,
        );

        if ( null === $window ) {
            return null;
        }

        foreach ( app( SlotResolver::class )->resolve( $service, $this->provider, $window ) as $slot ) {
            if ( $slot->period->start->equalTo( $start ) ) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Gives every multi-answer intake field an array to collect answers into.
     *
     * Livewire's grouped-checkbox binding appends to the bound property, and it
     * can only do that if the property is already an array. Bound to a nested
     * key that does not exist, the first box ticked writes a scalar instead —
     * and `IntakeFieldValidator` then refuses the whole booking against the
     * `array` rule those field types carry, naming a field the customer can see
     * they answered.
     *
     * Only the multi-answer types are seeded. A text field seeded with `[]`
     * would arrive at the validator as an array where the schema declares a
     * string, which is the same failure the other way round.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function seedMultiAnswerIntake(): void
    {
        foreach ( $this->intakeFields as $field ) {
            if ( ! $this->isMultiAnswerField( $field['type'] ) ) {
                continue;
            }

            if ( ! array_key_exists( $field['name'], $this->intake ) || ! is_array( $this->intake[ $field['name'] ] ) ) {
                $this->intake[ $field['name'] ] = [];
            }
        }
    }

    /**
     * Determines whether an intake field type holds more than one answer.
     *
     * The same two types `IntakeFieldValidator::isMultiAnswer()` names. They are
     * restated rather than shared because that method is the validator's own
     * judgement about rules, and this is the form's about markup — but they have
     * to agree, and the test suite is what holds them to it.
     *
     * @since 1.0.0
     *
     * @param  string  $type  The field type.
     *
     * @return bool True when the answer is an array of answers.
     */
    protected function isMultiAnswerField( string $type ): bool
    {
        return in_array( $type, [ 'multiselect', 'checkboxes' ], true );
    }

    /**
     * Cleans the customer's answers, in the order the API cleans them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function scrubCustomerInput(): void
    {
        $this->customerName  = trim( sanitizeText( $this->customerName ) );
        $this->customerPhone = trim( sanitizeText( $this->customerPhone ) );
        $this->notes         = trim( sanitizeText( $this->notes ) );
        $this->customerEmail = sanitizeEmail( $this->customerEmail );
        $this->intake        = $this->scrubIntake( $this->intake );
    }

    /**
     * Cleans the intake answers, however deeply they nest.
     *
     * Numbers and booleans keep the type they arrived as, so the schema
     * validator downstream still sees a number where the field declares one.
     * Keys are left alone: they are field names matched against the service's
     * schema, and an altered key would silently stop answering its field.
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $answers  The answers as given.
     *
     * @return array<array-key, mixed> The cleaned answers.
     */
    protected function scrubIntake( array $answers ): array
    {
        $cleaned = [];

        foreach ( $answers as $key => $value ) {
            if ( is_array( $value ) ) {
                $cleaned[ $key ] = $this->scrubIntake( $value );

                continue;
            }

            if ( is_string( $value ) ) {
                $cleaned[ $key ] = trim( sanitizeText( $value ) );

                continue;
            }

            $cleaned[ $key ] = null === $value || is_numeric( $value ) || is_bool( $value ) ? $value : null;
        }

        return $cleaned;
    }

    /**
     * Drops the choices that depend on the one just made.
     *
     * A provider chosen for one service means nothing under another, and a slot
     * chosen under one provider is not necessarily free under the next. Left in
     * place, either would carry a stale answer into the booking.
     *
     * @since 1.0.0
     *
     * @param  string  $keep  The last choice to keep: `start`, `service`,
     *                        `provider`, or `date`.
     *
     * @return void
     */
    protected function forgetChoicesBelow( string $keep ): void
    {
        // A pinned widget has no step below its service, so clearing the slug
        // there would drop the customer onto a list of the one service they
        // were already booking — and mount(), which is what re-pins it, does
        // not run again on a Livewire action.
        if ( 'start' === $keep && null === $this->pinnedService ) {
            $this->serviceSlug = '';
        }

        if ( in_array( $keep, [ 'start', 'service' ], true ) ) {
            $this->providerSlug = '';
        }

        if ( in_array( $keep, [ 'start', 'service', 'provider' ], true ) ) {
            $this->date = '';
        }

        $this->forgetChosenSlot();
    }

    /**
     * Drops the chosen slot, and everything derived from it this request.
     *
     * The property alone is not enough. `chosenStart` and `step` are computed
     * and cached per request, and `book()` reads both on its way to discovering
     * that the slot has gone — so clearing only the property leaves the same
     * render still believing a time is chosen, and the customer is shown the
     * details form underneath a message telling them to pick another time.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function forgetChosenSlot(): void
    {
        $this->slotStart = '';

        unset( $this->chosenStart, $this->step );
    }

    /**
     * Reads the choices back off the query string.
     *
     * Livewire's own `#[Url]` handling does this first and normally does all of
     * it, but it JSON-decodes each value before assigning it — so a service or
     * provider whose slug is all digits decodes to an integer, fails to assign
     * to a string property, and is silently dropped. That failure only shows up
     * without JavaScript, where the query string *is* the state, and it shows up
     * as a step that will not advance. Filling in whatever is still empty from
     * the raw query costs nothing and closes it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function recoverQueryString(): void
    {
        $mapping = [
            'bookingService'  => 'serviceSlug',
            'bookingProvider' => 'providerSlug',
            'bookingMonth'    => 'month',
            'bookingDate'     => 'date',
            'bookingSlot'     => 'slotStart',
        ];

        foreach ( $mapping as $parameter => $property ) {
            if ( '' !== $this->{$property} ) {
                continue;
            }

            $value = request()->query( $parameter );

            if ( is_string( $value ) || is_numeric( $value ) ) {
                $this->{$property} = trim( sanitizeText( (string) $value ) );
            }
        }

        if ( null !== $this->pinnedService ) {
            $this->serviceSlug = $this->pinnedService;
        }
    }

    /**
     * Picks up a booking made by the no-JavaScript form.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function readFlashedConfirmation(): void
    {
        $flashed = session()->get( WidgetConfirmation::SESSION_KEY );

        if ( ! is_array( $flashed ) || ! array_key_exists( 'booking_number', $flashed ) ) {
            return;
        }

        $confirmation = [];

        foreach ( $flashed as $key => $value ) {
            if ( is_string( $key ) && ( is_string( $value ) || is_numeric( $value ) ) ) {
                $confirmation[ $key ] = (string) $value;
            }
        }

        $this->confirmation = $confirmation;
    }

    /**
     * Puts a refused no-JavaScript submission back in the form.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function readOldInput(): void
    {
        $fields = [
            'customer_name'  => 'customerName',
            'customer_email' => 'customerEmail',
            'customer_phone' => 'customerPhone',
            'notes'          => 'notes',
        ];

        foreach ( $fields as $input => $property ) {
            $value = old( $input );

            if ( is_string( $value ) ) {
                $this->{$property} = $value;
            }
        }

        $intake = old( 'intake_data' );

        if ( is_array( $intake ) ) {
            $this->intake = $this->scrubIntake( $intake );
        }
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
            // A day nothing can read is no day at all. The template prints the
            // chosen day back to the customer, and a value that only fails when
            // Carbon reaches it takes the whole host page down with a 500 —
            // which is a page this package does not own, brought down by a query
            // string anybody can write.
            $this->date = '';
        }
    }

    /**
     * Rewrites the browsed month into the one spelling everything else expects.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function canonicaliseMonth(): void
    {
        if ( '' === $this->month ) {
            return;
        }

        try {
            $this->month = CarbonImmutable::parse( $this->month . '-01', $this->displayTimezone )->format( 'Y-m' );
        } catch ( Throwable ) {
            $this->month = '';
        }
    }

    /**
     * Rewrites the chosen slot into the one spelling everything else expects.
     *
     * Two jobs. The first is repair: a `+00:00` offset that went through a query
     * string comes back with a space where the `+` was, and while
     * {@see self::chosenStart()} can read past that, the `date` rule on the
     * property cannot — so a customer arriving on a pasted link would reach the
     * details step and then be told their appointment time was not a date.
     *
     * The second is refusal: an instant nothing can parse becomes no instant at
     * all, which puts the customer back on the list of times rather than into a
     * form built around a value that will be rejected at the end of it.
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
            $this->slotStart = self::instantKey( CarbonImmutable::parse( str_replace( ' ', '+', $this->slotStart ) ) );
        } catch ( Throwable ) {
            $this->slotStart = '';
        }
    }

    /**
     * Re-raises a refused no-JavaScript submission's errors as this form's.
     *
     * The controller behind that form validates through `StoreBookingRequest`,
     * whose field names are the API's — `customer_name`, `start_time` — while
     * this component's are its properties. Translating them once, here, is what
     * lets the view ask about one set of names: the alternative is every message
     * in the template being looked up under two spellings, which is two chances
     * to forget one and show the customer a form that silently refuses itself.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function readFlashedErrors(): void
    {
        $errors = session()->get( 'errors' );

        if ( ! $errors instanceof ViewErrorBag ) {
            return;
        }

        $mapping = [
            'service_slug'   => 'serviceSlug',
            'provider_id'    => 'providerSlug',
            'start_time'     => 'slotStart',
            'customer_name'  => 'customerName',
            'customer_email' => 'customerEmail',
            'customer_phone' => 'customerPhone',
            'notes'          => 'notes',
            'intake_data'    => 'intake',
        ];

        foreach ( $errors->getBag( 'default' )->toArray() as $field => $messages ) {
            $property = $mapping[ $field ] ?? null;

            if ( null === $property && str_starts_with( $field, 'intake_data.' ) ) {
                $property = 'intake.' . substr( $field, strlen( 'intake_data.' ) );
            }

            if ( null !== $property && isset( $messages[0] ) && is_string( $messages[0] ) ) {
                $this->addError( $property, $messages[0] );
            }
        }
    }

    /**
     * Gets a timezone name it is safe to render times in.
     *
     * Checked against the zones this machine actually knows rather than merely
     * being a non-empty string. The value arrives from the browser through a
     * property a client controls, and it is handed to Carbon and printed on the
     * page — so an unrecognised name is dropped in favour of the service's zone
     * rather than thrown.
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
}
