<?php

/**
 * Admin booking creator.
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

use ArtisanPackUI\Bookings\Enums\BookingAssignmentStrategy;
use ArtisanPackUI\Bookings\Exceptions\IntakeValidationException;
use ArtisanPackUI\Bookings\Exceptions\SlotLockTimeoutException;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\BookingService;
use ArtisanPackUI\Bookings\Services\IntakeFieldValidator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Creates a booking on behalf of a customer who booked by phone or email.
 *
 * The customer-facing widget is built around what only the browser knows — the
 * visitor's timezone, captured through JavaScript and used to render the slot
 * list in their own clock. An administrator taking a booking down the phone has
 * no browser to capture, so this form does the opposite: the timezone is stated
 * outright, defaulting to the chosen provider's and editable when the customer
 * is somewhere else, and the appointment time is typed as a wall-clock time in
 * that zone rather than picked off a calendar.
 *
 * Everything else is the widget's booking, unchanged. The write goes through
 * {@see BookingService}, so the same advisory lock, the same unique index, and
 * the same `ap.bookings.creating` / `created` / `confirmed` hooks fire for an
 * admin-authored booking as for a public one — an integration listening for new
 * bookings must not have to special-case the admin path. What the form records
 * is that the administrator chose the provider by name: the assignment strategy
 * is {@see BookingAssignmentStrategy::Admin}, not the rota.
 *
 * **Not notifying the customer suppresses the send, not the booking's history.**
 * An administrator sitting across the desk from the customer does not need to
 * email them a confirmation, and the checkbox says so — but the booking is still
 * created, still confirmed, and `ap.bookings.confirmed` still fires, because a
 * CRM or a calendar sync listening for it should hear about this booking exactly
 * as it hears about every other. The suppression is expressed the one way the
 * package already understands: the confirmation's channel list is filtered to
 * empty through `ap.bookings.notification.channels`, so nothing is sent and
 * everything downstream still runs.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingCreate extends Component
{
    /**
     * The service being booked, by id.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    public ?int $serviceId = null;

    /**
     * The provider the administrator assigned the booking to, by id.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    public ?int $providerId = null;

    /**
     * The appointment's start, as a local `Y-m-d\TH:i` wall-clock time.
     *
     * Read in {@see self::$timezone}, not the server's zone: the administrator
     * types the time the customer will keep, and the two are the same only by
     * coincidence.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $startTime = '';

    /**
     * The zone the appointment time is stated in.
     *
     * Defaults to the chosen provider's zone and is editable for a customer in
     * another. Stored on the booking as its customer timezone, so a later
     * reminder or reschedule reads back the clock the booking was taken against.
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
     * Anything else worth recording against the booking.
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
     * Whether the customer is emailed a confirmation.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $notifyCustomer = true;

    /**
     * Whether the administrator has set the timezone by hand.
     *
     * Kept so that choosing a provider prefills the zone only while the
     * administrator has not overruled it — a customer marked as being in another
     * zone must not have that quietly rewritten by a later provider change.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $timezoneOverridden = false;

    /**
     * Preselects the service being booked, when one is known.
     *
     * @since 1.0.0
     *
     * @param  int|null  $serviceId  The service to book, or null to choose one.
     *
     * @return void
     */
    public function mount( ?int $serviceId = null ): void
    {
        if ( null === $serviceId ) {
            return;
        }

        if ( ! Service::query()->whereKey( $serviceId )->exists() ) {
            return;
        }

        $this->serviceId = $serviceId;

        $this->seedMultiAnswerIntake();
    }

    /**
     * Starts the intake form over when the service changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedServiceId(): void
    {
        // The new service asks its own questions, and its providers are its own,
        // so the old service's answers and the chosen provider go together.
        $this->providerId = null;
        $this->intake     = [];

        unset( $this->intakeFields, $this->providers );

        if ( ! $this->timezoneOverridden ) {
            $this->timezone = '';
        }

        $this->seedMultiAnswerIntake();
    }

    /**
     * Prefills the timezone from the provider, unless it was set by hand.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedProviderId(): void
    {
        if ( $this->timezoneOverridden ) {
            return;
        }

        $this->timezone = (string) ( $this->provider()?->timezone ?? '' );
    }

    /**
     * Remembers that the timezone is now the administrator's choice.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedTimezone(): void
    {
        $this->timezoneOverridden = '' !== trim( $this->timezone );
    }

    /**
     * Validates the form and creates the booking.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function save(): void
    {
        $this->scrubCustomerInput();

        $this->validate();

        // `serviceId` is a plain form field, not locked, so the id that arrives
        // is whatever the client sent — and `BookingService::create()` resolves a
        // service without checking that it is active. Gating on `service()`, which
        // reads the same active-only list the dropdown offers, is what stops a
        // tampered id booking a service that was retired from sale.
        if ( null === $this->service() ) {
            $this->addError( 'serviceId', __( 'Choose a service that can be booked.' ) );

            return;
        }

        $timezone = $this->bookingTimezone();

        try {
            $start = CarbonImmutable::parse( $this->startTime, $timezone );
        } catch ( Throwable ) {
            $this->addError( 'startTime', __( 'That is not a valid date and time.' ) );

            return;
        }

        $attributes = [
            'service_id'          => $this->serviceId,
            'provider_id'         => $this->providerId,
            'start_time'          => $start,
            'customer_name'       => $this->customerName,
            'customer_email'      => $this->customerEmail,
            'customer_phone'      => '' === $this->customerPhone ? null : $this->customerPhone,
            'customer_timezone'   => $timezone,
            'notes'               => '' === $this->notes ? null : $this->notes,
            'intake_data'         => $this->intake,
            'assignment_strategy' => BookingAssignmentStrategy::Admin,
        ];

        try {
            $booking = $this->withCustomerNotification(
                static fn (): Booking => app( BookingService::class )->create( $attributes ),
            );
        } catch ( IntakeValidationException $refused ) {
            foreach ( $refused->errors()->toArray() as $field => $messages ) {
                $this->addError( 'intake.' . $field, (string) ( $messages[0] ?? __( 'That answer could not be accepted.' ) ) );
            }

            return;
        } catch ( SlotUnavailableException ) {
            $this->addError( 'startTime', __( 'That appointment time is no longer available. Please choose another.' ) );

            return;
        } catch ( SlotLockTimeoutException ) {
            $this->addError( 'startTime', __( 'That appointment time is busy right now. Please try again.' ) );

            return;
        } catch ( InvalidArgumentException $rejected ) {
            // Logged rather than shown: BookingService raises this from several
            // places — an unknown service, a provider who does not offer it — and
            // only some carry a sentence an administrator should be handed.
            Log::warning( 'An admin-authored booking was refused.', [
                'exception' => $rejected->getMessage(),
            ] );

            $this->addError( 'startTime', __( 'That booking could not be created. Please check the details and try again.' ) );

            return;
        }

        $this->dispatch( 'bookings-booking-created', bookingId: $booking->getKey() );

        $this->resetForm();
    }

    /**
     * Signals that the administrator wants to leave without booking.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->dispatch( 'bookings-booking-create-cancelled' );
    }

    /**
     * Gets the services that can be booked.
     *
     * @since 1.0.0
     *
     * @return Collection<int, Service> The active services, by name.
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()
            ->active()
            ->orderBy( 'name' )
            ->get();
    }

    /**
     * Gets the providers the chosen service can be booked with.
     *
     * @since 1.0.0
     *
     * @return array<int, ServiceProvider> The bookable providers.
     */
    #[Computed]
    public function providers(): array
    {
        $service = $this->service();

        return null === $service ? [] : $service->bookableProviders();
    }

    /**
     * Gets the questions the chosen service's intake form asks.
     *
     * @since 1.0.0
     *
     * @return array<int, array{name: string, type: string, label: string, required: bool, options: array<int, string>, rules: array<int, string>}> The fields.
     */
    #[Computed]
    public function intakeFields(): array
    {
        $service = $this->service();

        if ( null === $service ) {
            return [];
        }

        return app( IntakeFieldValidator::class )->fieldsFor(
            $service,
            (int) $service->intake_schema_version,
        );
    }

    /**
     * Renders the form.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'bookings::livewire.admin.booking-create' );
    }

    /**
     * Gets the service being booked, when one is chosen.
     *
     * @since 1.0.0
     *
     * @return Service|null The service, or null while none is chosen.
     */
    protected function service(): ?Service
    {
        if ( null === $this->serviceId ) {
            return null;
        }

        foreach ( $this->services() as $service ) {
            if ( (int) $service->getKey() === $this->serviceId ) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Gets the provider the administrator assigned, when one is chosen.
     *
     * @since 1.0.0
     *
     * @return ServiceProvider|null The provider, or null while none is chosen.
     */
    protected function provider(): ?ServiceProvider
    {
        if ( null === $this->providerId ) {
            return null;
        }

        foreach ( $this->providers() as $provider ) {
            if ( (int) $provider->getKey() === $this->providerId ) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Gets the zone the appointment time is read and stored in.
     *
     * The administrator's choice first; the provider's, then the application's,
     * until they make one — so a booking always carries a zone even before a
     * provider is picked.
     *
     * @since 1.0.0
     *
     * @return string The IANA zone name.
     */
    protected function bookingTimezone(): string
    {
        if ( '' !== trim( $this->timezone ) ) {
            return trim( $this->timezone );
        }

        $providerTimezone = $this->provider()?->timezone;

        if ( is_string( $providerTimezone ) && '' !== $providerTimezone ) {
            return $providerTimezone;
        }

        return (string) ( config( 'artisanpack.bookings.timezone' ) ?: config( 'app.timezone', 'UTC' ) );
    }

    /**
     * Runs a booking write with the customer confirmation on or off.
     *
     * When the administrator has left "notify customer" ticked, the callback runs
     * untouched and the confirmation goes out the ordinary way. When they have
     * cleared it, a filter that empties the confirmation's channel list is
     * registered for exactly the length of the write and taken straight back off
     * — so the send is suppressed for this booking without touching any channel
     * an application configured for the next one.
     *
     * The filter is removed in a `finally` rather than after the call because the
     * write can throw — a taken slot, a refused intake answer — and a suppression
     * left registered on a component that stays alive for the request would then
     * silence the next booking the administrator tries.
     *
     * One timing hole is worth naming. The confirmation rides `BookingConfirmed`,
     * which is `ShouldDispatchAfterCommit`, so the send only lands inside this
     * closure while `create()` owns its own transaction — which it does on every
     * ordinary request. A host that wraps the whole Livewire update in an outer
     * transaction of its own defers the listener past this `finally`, and an
     * unchecked "notify customer" would then send anyway. That is the same caveat
     * {@see BookingService::create()} documents for its own veto, and the fix is
     * the same: do not wrap this component's writes in an outer transaction.
     *
     * @since 1.0.0
     *
     * @param  callable(): Booking  $write  The booking write to run.
     *
     * @return Booking The booking the write created.
     */
    protected function withCustomerNotification( callable $write ): Booking
    {
        if ( $this->notifyCustomer ) {
            return $write();
        }

        $suppress = static fn ( array $channels ): array => [];

        // Registered last so it has the final word: a subscriber that added a
        // channel earlier in the chain does not get to put the send back.
        addFilter( 'ap.bookings.notification.channels', $suppress, PHP_INT_MAX );

        try {
            return $write();
        } finally {
            removeFilter( 'ap.bookings.notification.channels', $suppress, PHP_INT_MAX );
        }
    }

    /**
     * Empties the form once a booking has been made.
     *
     * The service and its provider are kept: an administrator taking several
     * bookings for one clinic session should not reselect the service every time.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function resetForm(): void
    {
        $this->reset( [ 'startTime', 'customerName', 'customerEmail', 'customerPhone', 'notes', 'intake', 'notifyCustomer' ] );

        $this->seedMultiAnswerIntake();
    }

    /**
     * Gives every multi-answer intake field an array to collect answers into.
     *
     * Livewire's grouped-checkbox binding appends to the bound property, which it
     * can only do if the property is already an array. Bound to a key that does
     * not exist, the first box ticked writes a scalar — and the intake validator
     * then refuses the whole booking against the `array` rule those types carry,
     * naming a field the administrator can see they answered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function seedMultiAnswerIntake(): void
    {
        foreach ( $this->intakeFields() as $field ) {
            if ( ! in_array( $field['type'], [ 'multiselect', 'checkboxes' ], true ) ) {
                continue;
            }

            if ( ! array_key_exists( $field['name'], $this->intake ) || ! is_array( $this->intake[ $field['name'] ] ) ) {
                $this->intake[ $field['name'] ] = [];
            }
        }
    }

    /**
     * Cleans the customer's answers before they are validated and stored.
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
     * Gets the validation rules the form is checked against.
     *
     * The intake answers are absent on purpose: they are judged by
     * {@see IntakeFieldValidator} against the schema version the booking is
     * captured at, inside {@see BookingService::create()}, and a second copy of
     * those rules here could only ever disagree with it.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>> The rules.
     */
    protected function rules(): array
    {
        return [
            'serviceId'      => [ 'required', 'integer' ],
            'providerId'     => [ 'required', 'integer' ],
            'startTime'      => [ 'required', 'string', 'date' ],
            'timezone'       => [ 'nullable', 'string', 'timezone' ],
            'customerName'   => [ 'required', 'string', 'max:255' ],
            'customerEmail'  => [ 'required', 'string', 'email', 'max:255' ],
            'customerPhone'  => [ 'nullable', 'string', 'max:50' ],
            'notes'          => [ 'nullable', 'string', 'max:2000' ],
            'notifyCustomer' => [ 'boolean' ],
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
            'serviceId'     => __( 'service' ),
            'providerId'    => __( 'provider' ),
            'startTime'     => __( 'appointment time' ),
            'timezone'      => __( 'timezone' ),
            'customerName'  => __( 'name' ),
            'customerEmail' => __( 'email address' ),
            'customerPhone' => __( 'telephone number' ),
            'notes'         => __( 'notes' ),
        ];
    }
}
