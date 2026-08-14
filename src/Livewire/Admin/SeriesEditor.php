<?php

/**
 * Admin series editor.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Livewire\Admin;

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\SeriesEditScope;
use ArtisanPackUI\Bookings\Exceptions\SeriesException;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\SeriesService;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Edits a recurring series, as far through it as the chosen scope reaches.
 *
 * The scope selector is the three-radio picker every calendar application
 * offers when you edit a repeating appointment — this occurrence, this and
 * everything following, or the whole series — and each choice edits genuinely
 * different things, so the form changes shape with it. `all` and
 * `this_and_following` edit the series' own columns (its provider, the customer's
 * details, the rule, its start and end); `this` edits a single occurrence and
 * detaches it from the rule. The occurrence picker is shown for the two scopes
 * that need a point to work from, and hidden for the whole-series edit that does
 * not. Detached occurrences are flagged in the picker, because they no longer
 * follow the rule and a `this_and_following` split will not touch them.
 *
 * Nothing here writes to the database directly. Every edit is handed to
 * {@see SeriesService::edit()} so it takes the slot lock, fires the
 * `ap.bookings.*` lifecycle hooks, and splits or rewrites the rule the way the
 * scope demands — and every field the form sends is one
 * {@see SeriesService::SERIES_FIELDS} or {@see SeriesService::OCCURRENCE_FIELDS}
 * allows, so a request cannot reach a column it has no business setting.
 *
 * Only fields the administrator actually changed are sent. This is not a
 * nicety: a `this_and_following` split reads an omitted `dtstart_local` as "the
 * occurrence's own time" and an omitted `rrule` as "carry the head's rule over,
 * dividing its count between the halves". Echoing the prefilled values back
 * would defeat both, starting the tail at the head's original start and handing
 * a twelve-week count to each half. Diffing against the stored row is what keeps
 * the unchanged fields genuinely unchanged.
 *
 * Site scoping is left to the models: a series loaded here came through the
 * site-scoped query {@see SeriesIndex} lists from, and the edit runs pinned to
 * that series' own site, so nothing on this component names a tenant.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SeriesEditor extends Component
{
    /**
     * The format the form's `datetime-local` inputs read and write.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected const LOCAL_FORMAT = 'Y-m-d\TH:i';

    /**
     * The series being edited.
     *
     * Locked: it is the row every edit is applied to, and a client that could
     * repoint it could edit a series the administrator never opened — including,
     * if the id crossed a tenant boundary, one the site-scoped list would never
     * have shown them.
     *
     * @since 1.0.0
     *
     * @var int
     */
    #[Locked]
    public int $seriesId = 0;

    /**
     * How far through the series the edit reaches.
     *
     * One of the {@see SeriesEditScope} values. Defaults to a whole-series edit,
     * the scope that needs no occurrence chosen first.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $scope = SeriesEditScope::All->value;

    /**
     * The occurrence a scoped edit works from, for the scopes that need one.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    public ?int $occurrenceId = null;

    /**
     * The provider the series is assigned to, for a rule-touching edit.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    public ?int $providerId = null;

    /**
     * The customer's name, for a rule-touching edit.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $customerName = '';

    /**
     * The customer's email, for a rule-touching edit.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $customerEmail = '';

    /**
     * The customer's phone, for a rule-touching edit.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $customerPhone = '';

    /**
     * The RFC 5545 recurrence rule, for a rule-touching edit.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $rrule = '';

    /**
     * The floating start of the series — a clock face — for a rule-touching edit.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $dtstartLocal = '';

    /**
     * The IANA zone the series' clock faces are read in.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $dtstartTimezone = 'UTC';

    /**
     * The floating upper bound of the series, or blank for none.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $untilLocal = '';

    /**
     * The new time to move a single occurrence to, for a `this` edit.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $occurrenceStart = '';

    /**
     * The occurrence's customer name, for a `this` edit.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $occurrenceCustomerName = '';

    /**
     * The occurrence's customer email, for a `this` edit.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $occurrenceCustomerEmail = '';

    /**
     * The occurrence's customer phone, for a `this` edit.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $occurrenceCustomerPhone = '';

    /**
     * The occurrence's notes, for a `this` edit.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $occurrenceNotes = '';

    /**
     * The series loaded for the current request, read once and reused.
     *
     * @since 1.0.0
     *
     * @var BookingSeries|null
     */
    protected ?BookingSeries $seriesModel = null;

    /**
     * Loads the series being edited and prefills the rule-touching form from it.
     *
     * @since 1.0.0
     *
     * @param  int  $seriesId  The series to edit.
     *
     * @return void
     */
    public function mount( int $seriesId ): void
    {
        $series = BookingSeries::query()->findOrFail( $seriesId );

        $this->seriesId        = $series->getKey();
        $this->providerId      = $series->provider_id;
        $this->customerName    = $series->customer_name;
        $this->customerEmail   = $series->customer_email;
        $this->customerPhone   = (string) $series->customer_phone;
        $this->rrule           = $series->rrule;
        $this->dtstartTimezone = $series->dtstart_timezone;
        $this->dtstartLocal    = $series->dtstart_local->format( self::LOCAL_FORMAT );
        $this->untilLocal      = null === $series->until_local
            ? ''
            : $series->until_local->format( self::LOCAL_FORMAT );
    }

    /**
     * Prefills the single-occurrence form when the chosen occurrence changes.
     *
     * The occurrence's time is shown in the series' own authoring zone, the same
     * zone its clock face was written in, so the number the administrator reads
     * back is the number they would have typed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedOccurrenceId(): void
    {
        $occurrence = $this->occurrence();

        if ( ! $occurrence instanceof Booking ) {
            return;
        }

        $this->occurrenceStart         = Carbon::instance( $occurrence->start_time )
            ->setTimezone( $this->series()->dtstart_timezone )
            ->format( self::LOCAL_FORMAT );
        $this->occurrenceCustomerName  = $occurrence->customer_name;
        $this->occurrenceCustomerEmail = $occurrence->customer_email;
        $this->occurrenceCustomerPhone = (string) $occurrence->customer_phone;
        $this->occurrenceNotes         = (string) $occurrence->notes;
    }

    /**
     * Applies the edit to the series, as far as the chosen scope reaches.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function save(): void
    {
        // `scope` is an unlocked public property, so a client can send any
        // string. `from()` would throw a `\ValueError` — which extends `\Error`,
        // not `\Exception`, so nothing below would catch it — where a validation
        // message is the honest answer to an unknown scope.
        $scope = SeriesEditScope::tryFrom( $this->scope );

        if ( ! $scope instanceof SeriesEditScope ) {
            $this->addError( 'scope', __( 'Choose how much of the series this change applies to.' ) );

            return;
        }

        $series = $this->series();

        if ( SeriesEditScope::All !== $scope && null === $this->occurrenceId ) {
            $this->addError( 'occurrenceId', __( 'Choose the occurrence this edit starts from.' ) );

            return;
        }

        $this->validate( $this->rulesFor( $scope ) );

        $occurrence = SeriesEditScope::All === $scope ? null : $this->occurrence();

        if ( SeriesEditScope::All !== $scope && ! $occurrence instanceof Booking ) {
            $this->addError( 'occurrenceId', __( 'That occurrence is no longer part of this series.' ) );

            return;
        }

        try {
            $changes = SeriesEditScope::This === $scope
                ? $this->occurrenceChanges( $occurrence )
                : $this->seriesChanges( $series );
        } catch ( InvalidFormatException ) {
            $this->addError( 'occurrenceStart', __( 'That is not a valid date and time.' ) );

            return;
        }

        if ( [] === $changes ) {
            $this->addError( 'scope', __( 'Change something before saving.' ) );

            return;
        }

        try {
            app( SeriesService::class )->edit( $series, $scope, $changes, $occurrence, BookingActor::Admin );
        } catch ( SeriesException|SlotUnavailableException|InvalidArgumentException $exception ) {
            $this->addError( 'scope', $exception->getMessage() );

            return;
        }

        $this->resetErrorBag();

        // The edit rewrote the row — or bounded it and split the rest onto a new
        // series — so the memoised instance now holds pre-edit attributes. Drop
        // it, so the render that follows reads the rule, start, and bounds back
        // as they now stand rather than as they were.
        $this->seriesModel = null;

        $this->dispatch( 'bookings-series-saved', seriesId: $this->seriesId );
    }

    /**
     * Calls off the whole arrangement, along with everything still to come.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function cancelSeries(): void
    {
        try {
            app( SeriesService::class )->cancel( $this->series(), BookingActor::Admin );
        } catch ( SeriesException $exception ) {
            $this->addError( 'scope', $exception->getMessage() );

            return;
        }

        $this->resetErrorBag();

        // The row now carries `cancelled_at`, so the memoised instance would
        // render the status badge as still active. Drop it and read it back.
        $this->seriesModel = null;

        $this->dispatch( 'bookings-series-cancelled', seriesId: $this->seriesId );
    }

    /**
     * Signals that the administrator wants to leave without saving.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->dispatch( 'bookings-series-editor-cancelled' );
    }

    /**
     * Gets the series being edited, read once per request and reused.
     *
     * @since 1.0.0
     *
     * @return BookingSeries The series, with its service and provider loaded.
     */
    public function series(): BookingSeries
    {
        return $this->seriesModel ??= BookingSeries::query()
            ->with( [ 'service', 'provider' ] )
            ->findOrFail( $this->seriesId );
    }

    /**
     * Gets the occurrences an administrator can start a scoped edit from.
     *
     * @since 1.0.0
     *
     * @return Collection<int, Booking> The series' occurrences, in order.
     */
    public function occurrences(): Collection
    {
        return $this->series()->occurrences()->get();
    }

    /**
     * Gets the providers a rule-touching edit can reassign the series to.
     *
     * @since 1.0.0
     *
     * @return Collection<int, ServiceProvider> The site's providers, by name.
     */
    public function providers(): Collection
    {
        return ServiceProvider::query()->orderBy( 'name' )->get();
    }

    /**
     * Renders the editor.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'bookings::livewire.admin.series-editor', [
            'series'      => $this->series(),
            'occurrences' => $this->occurrences(),
            'providers'   => $this->providers(),
        ] );
    }

    /**
     * Gets the occurrence a scoped edit works from, when one is chosen.
     *
     * Constrained to the series being edited, so a client that alters
     * {@see self::$occurrenceId} to an occurrence of another series — its own or
     * another tenant's — gets null rather than a foothold on a row this editor
     * has no business touching.
     *
     * @since 1.0.0
     *
     * @return Booking|null The chosen occurrence, or null when there is none.
     */
    protected function occurrence(): ?Booking
    {
        if ( null === $this->occurrenceId ) {
            return null;
        }

        return $this->series()->occurrences()->whereKey( $this->occurrenceId )->first();
    }

    /**
     * Builds the changes a rule-touching edit sends, keeping only what changed.
     *
     * @since 1.0.0
     *
     * @param  BookingSeries  $series  The series being compared against.
     *
     * @return array<string, mixed> The changed series fields.
     */
    protected function seriesChanges( BookingSeries $series ): array
    {
        $changes = [];

        // `provider_id` is not cast on the model, and Postgres hands integer
        // columns back as strings — so a strict comparison against the `?int`
        // property would read every unchanged provider as changed, and turn a
        // no-op save into a full re-materialisation. Both sides are narrowed to
        // `?int` before comparing.
        $currentProviderId = null === $series->provider_id ? null : (int) $series->provider_id;

        if ( $this->providerId !== $currentProviderId ) {
            $changes['provider_id'] = $this->providerId;
        }

        if ( trim( $this->customerName ) !== $series->customer_name ) {
            $changes['customer_name'] = trim( $this->customerName );
        }

        if ( trim( $this->customerEmail ) !== $series->customer_email ) {
            $changes['customer_email'] = trim( $this->customerEmail );
        }

        if ( trim( $this->customerPhone ) !== (string) $series->customer_phone ) {
            $changes['customer_phone'] = '' === trim( $this->customerPhone ) ? null : trim( $this->customerPhone );
        }

        if ( trim( $this->rrule ) !== $series->rrule ) {
            $changes['rrule'] = trim( $this->rrule );
        }

        if ( $this->dtstartTimezone !== $series->dtstart_timezone ) {
            $changes['dtstart_timezone'] = $this->dtstartTimezone;
        }

        if ( $this->dtstartLocal !== $series->dtstart_local->format( self::LOCAL_FORMAT ) ) {
            $changes['dtstart_local'] = $this->dtstartLocal;
        }

        $currentUntil = null === $series->until_local
            ? ''
            : $series->until_local->format( self::LOCAL_FORMAT );

        if ( trim( $this->untilLocal ) !== $currentUntil ) {
            $changes['until_local'] = '' === trim( $this->untilLocal ) ? null : trim( $this->untilLocal );
        }

        return $changes;
    }

    /**
     * Builds the changes a single-occurrence edit sends, keeping only what changed.
     *
     * The new time is read in the series' authoring zone and passed as an
     * instant, so {@see SeriesService::editOccurrence()} moves the occurrence
     * through {@see \ArtisanPackUI\Bookings\Services\BookingService::reschedule()}
     * to exactly the wall-clock time the administrator typed.
     *
     * @since 1.0.0
     *
     * @param  Booking  $occurrence  The occurrence being compared against.
     *
     * @throws InvalidFormatException When the typed time cannot be read.
     *
     * @return array<string, mixed> The changed occurrence fields.
     */
    protected function occurrenceChanges( Booking $occurrence ): array
    {
        $changes = [];

        $currentStart = Carbon::instance( $occurrence->start_time )
            ->setTimezone( $this->series()->dtstart_timezone )
            ->format( self::LOCAL_FORMAT );

        if ( '' !== trim( $this->occurrenceStart ) && $this->occurrenceStart !== $currentStart ) {
            $changes['start_time'] = Carbon::createFromFormat(
                self::LOCAL_FORMAT,
                $this->occurrenceStart,
                $this->series()->dtstart_timezone,
            );
        }

        if ( trim( $this->occurrenceCustomerName ) !== $occurrence->customer_name ) {
            $changes['customer_name'] = trim( $this->occurrenceCustomerName );
        }

        if ( trim( $this->occurrenceCustomerEmail ) !== $occurrence->customer_email ) {
            $changes['customer_email'] = trim( $this->occurrenceCustomerEmail );
        }

        if ( trim( $this->occurrenceCustomerPhone ) !== (string) $occurrence->customer_phone ) {
            $changes['customer_phone'] = '' === trim( $this->occurrenceCustomerPhone ) ? null : trim( $this->occurrenceCustomerPhone );
        }

        if ( trim( $this->occurrenceNotes ) !== (string) $occurrence->notes ) {
            $changes['notes'] = '' === trim( $this->occurrenceNotes ) ? null : trim( $this->occurrenceNotes );
        }

        return $changes;
    }

    /**
     * Gets the validation rules for the fields the chosen scope reads.
     *
     * @since 1.0.0
     *
     * @param  SeriesEditScope  $scope  The scope being applied.
     *
     * @return array<string, mixed> The rules.
     */
    protected function rulesFor( SeriesEditScope $scope ): array
    {
        if ( SeriesEditScope::This === $scope ) {
            return [
                'occurrenceStart'         => [ 'nullable', 'date' ],
                'occurrenceCustomerName'  => [ 'required', 'string', 'max:255' ],
                'occurrenceCustomerEmail' => [ 'required', 'email', 'max:255' ],
                'occurrenceCustomerPhone' => [ 'nullable', 'string', 'max:50' ],
                'occurrenceNotes'         => [ 'nullable', 'string' ],
            ];
        }

        return [
            'providerId'      => [
                'nullable',
                'integer',
                'min:1',

                // Scoped through the model, not `Rule::exists`, which issues a
                // raw query that bypasses both the site scope and the
                // soft-delete scope — and would accept a provider from another
                // tenant, or a retired one, that the dropdown never offered.
                // Failing here matters because {@see SeriesService} rewrites the
                // rule and cancels the future occurrences before it reaches the
                // provider and throws, so a bad id caught only there would leave
                // the series' own appointments cancelled without replacement.
                function ( string $attribute, mixed $value, Closure $fail ): void {
                    if ( null !== $value && ! ServiceProvider::query()->whereKey( $value )->exists() ) {
                        $fail( __( 'The selected provider is unavailable.' ) );
                    }
                },
            ],
            'customerName'    => [ 'required', 'string', 'max:255' ],
            'customerEmail'   => [ 'required', 'email', 'max:255' ],
            'customerPhone'   => [ 'nullable', 'string', 'max:50' ],
            'rrule'           => [ 'required', 'string', 'max:1000' ],
            'dtstartLocal'    => [ 'required', 'date' ],
            'dtstartTimezone' => [ 'required', 'string', 'timezone' ],
            'untilLocal'      => [ 'nullable', 'date' ],
        ];
    }
}
