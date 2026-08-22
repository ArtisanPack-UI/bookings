<?php

/**
 * Admin booking detail.
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
use ArtisanPackUI\Bookings\Exceptions\BookingException;
use ArtisanPackUI\Bookings\Exceptions\InvalidBookingTransitionException;
use ArtisanPackUI\Bookings\Exceptions\SlotLockTimeoutException;
use ArtisanPackUI\Bookings\Exceptions\SlotUnavailableException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\IntakeSchemaVersion;
use ArtisanPackUI\Bookings\Services\BookingService;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The single booking an administrator opens to read it in full and act on it.
 *
 * Everything the list has no room for lives here: the customer's answers to the
 * intake form as it stood the day they booked, and the three transitions that
 * want more than a button — cancelling with a reason, moving the appointment to
 * a new time, and recording that nobody turned up. Each of those goes through
 * {@see BookingService}, so the same hooks, events, and slot bookkeeping that
 * fire when a customer cancels their own booking fire here too; the only
 * difference an administrator's action carries is the actor, which is stamped
 * {@see BookingActor::Admin} so a listener can tell who moved the row.
 *
 * The intake answers are rendered against the version of the form the booking
 * captured, not the version the service is asking today. A service that has
 * since added, removed, or relabelled a question still reads back exactly what
 * this customer was asked and what they said, because the booking recorded the
 * version number and this view resolves the questions from it.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingDetail extends Component
{
    /**
     * The booking being shown.
     *
     * Locked so a tampered request cannot repoint the component at a booking the
     * administrator never opened. The site scope already keeps a query inside the
     * tenant, but a single-tenant installation has no such scope to lean on, and
     * the writes here — cancel, reschedule, no-show — are exactly the actions that
     * must not act on a booking chosen by the client rather than the server.
     *
     * @since 1.0.0
     *
     * @var int
     */
    #[Locked]
    public int $bookingId;

    /**
     * The reason typed into the cancel form, when one is given.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $cancelReason = '';

    /**
     * The new start time typed into the reschedule form, as `Y-m-d\TH:i`.
     *
     * Read as a wall-clock time in the provider's own zone, the way an
     * administrator scheduling for that provider means it.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $rescheduleStart = '';

    /**
     * The booking resolved for the current request.
     *
     * Not part of the component's state — it is never serialised and starts null
     * on every request, so it memoises the read within one round-trip without
     * ever carrying a stale row into the next.
     *
     * @since 1.0.0
     *
     * @var Booking|null
     */
    protected ?Booking $bookingModel = null;

    /**
     * Loads the booking being shown.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to show.
     *
     * @return void
     */
    public function mount( Booking $booking ): void
    {
        $this->bookingId = $booking->id;
    }

    /**
     * Cancels the booking as an administrator.
     *
     * Routed through {@see BookingService} rather than writing the status here,
     * so `ap.bookings.cancelling` and `ap.bookings.cancelled` fire and the
     * cancellation notification is sent, with the actor stamped as the admin.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function cancel(): void
    {
        $reason = '' === trim( $this->cancelReason ) ? null : trim( $this->cancelReason );

        try {
            app( BookingService::class )->cancel( $this->booking(), BookingActor::Admin, $reason );
        } catch ( BookingException $exception ) {
            $this->addError( 'booking', $this->bookingErrorMessage( $exception ) );

            return;
        }

        $this->cancelReason = '';

        $this->afterAction();
    }

    /**
     * Moves the booking to a new time as an administrator.
     *
     * The typed wall-clock time is read in the provider's zone and handed to
     * {@see BookingService}, which rejects a time that clashes with another of
     * the provider's bookings. A clash or an illegal transition leaves the
     * booking where it was and shows the reason rather than throwing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function reschedule(): void
    {
        if ( '' === trim( $this->rescheduleStart ) ) {
            $this->addError( 'rescheduleStart', __( 'Choose a new time.' ) );

            return;
        }

        try {
            $start = CarbonImmutable::parse( $this->rescheduleStart, $this->rescheduleTimezone() );
        } catch ( InvalidFormatException ) {
            $this->addError( 'rescheduleStart', __( 'That is not a valid date and time.' ) );

            return;
        }

        try {
            app( BookingService::class )->reschedule( $this->booking(), $start, BookingActor::Admin );
        } catch ( BookingException $exception ) {
            $this->addError( 'rescheduleStart', $this->bookingErrorMessage( $exception ) );

            return;
        }

        $this->rescheduleStart = '';

        $this->afterAction();
    }

    /**
     * Records that nobody attended the booking.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function markNoShow(): void
    {
        try {
            app( BookingService::class )->markNoShow( $this->booking(), BookingActor::Admin );
        } catch ( BookingException $exception ) {
            $this->addError( 'booking', $this->bookingErrorMessage( $exception ) );

            return;
        }

        $this->afterAction();
    }

    /**
     * Gets the booking being shown, read once per request.
     *
     * Resolved fresh at the start of each request and then reused, so the several
     * callers within one action — the timezone the reschedule reads, the write
     * itself, the render that follows — share one row and one query. An action
     * mutates that shared instance, so the change it makes is still what the view
     * renders; the next request re-mounts the component and reads again.
     *
     * @since 1.0.0
     *
     * @return Booking The booking, with its service and provider loaded.
     */
    public function booking(): Booking
    {
        return $this->bookingModel ??= Booking::query()
            ->with( [ 'service', 'provider' ] )
            ->findOrFail( $this->bookingId );
    }

    /**
     * Gets the intake questions and answers as the booking captured them.
     *
     * Resolved from the schema version the booking recorded, so a form that has
     * changed since still reads back the questions this customer was actually
     * asked. A field the customer left blank is kept in the list with an empty
     * answer rather than dropped, because "asked and not answered" is a different
     * fact from "never asked". When the captured version can no longer be found,
     * the raw answers are shown against their own keys so nothing is hidden.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking whose intake to resolve.
     *
     * @return array<int, array{label: string, value: string}> The labelled answers.
     */
    public function intakeAnswers( Booking $booking ): array
    {
        $data = $booking->intake_data ?? [];

        $version = IntakeSchemaVersion::query()
            ->forService( (int) $booking->service_id )
            ->where( 'version', $booking->intake_schema_version )
            ->first();

        if ( null === $version ) {
            return array_map(
                static fn ( string $name, mixed $value ): array => [
                    'label' => $name,
                    'value' => self::stringifyAnswer( $value ),
                ],
                array_keys( $data ),
                array_values( $data ),
            );
        }

        $fields = $version->schema['fields'] ?? [];

        return array_map( static function ( array $field ) use ( $data ): array {
            $name = (string) ( $field['name'] ?? '' );

            return [
                'label' => (string) ( $field['label'] ?? $name ),
                'value' => self::stringifyAnswer( $data[ $name ] ?? null ),
            ];
        }, $fields );
    }

    /**
     * Renders the detail view.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        $booking = $this->booking();

        return view( 'bookings::livewire.admin.booking-detail', [
            'booking'       => $booking,
            'intakeAnswers' => $this->intakeAnswers( $booking ),
        ] );
    }

    /**
     * Maps a domain exception to translated, operator-facing copy.
     *
     * The exception messages are developer-phrased — raw ids, enum slugs — so
     * they are mapped by type rather than shown, keeping internal wording out of
     * the admin's error bag and leaving every message translatable.
     *
     * @since 1.0.0
     *
     * @param  BookingException  $exception  The exception the service raised.
     *
     * @return string The translated message.
     */
    protected function bookingErrorMessage( BookingException $exception ): string
    {
        return match ( true ) {
            $exception instanceof SlotUnavailableException          => __( 'That appointment time is no longer available. Please choose another.' ),
            $exception instanceof SlotLockTimeoutException          => __( 'That appointment time is busy right now. Please try again.' ),
            $exception instanceof InvalidBookingTransitionException => __( 'That change can no longer be made to this booking.' ),
            default                                                 => __( 'The booking could not be updated.' ),
        };
    }

    /**
     * Announces that the booking changed and clears any stale error.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function afterAction(): void
    {
        $this->resetErrorBag();

        $this->dispatch( 'bookings-booking-updated', bookingId: $this->bookingId );
    }

    /**
     * Gets the zone the reschedule time is read in.
     *
     * The provider's own working zone, since the appointment is on their
     * schedule; the application's zone stands in when the provider has none.
     *
     * @since 1.0.0
     *
     * @return string The timezone identifier.
     */
    protected function rescheduleTimezone(): string
    {
        $timezone = $this->booking()->provider?->timezone;

        if ( ! is_string( $timezone ) || '' === $timezone ) {
            $timezone = (string) config( 'app.timezone', 'UTC' );
        }

        return $timezone;
    }

    /**
     * Renders an intake answer as a single readable string.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The stored answer.
     *
     * @return string The answer as text.
     */
    protected static function stringifyAnswer( mixed $value ): string
    {
        if ( is_array( $value ) ) {
            return implode( ', ', array_map( static fn ( mixed $item ): string => (string) $item, $value ) );
        }

        if ( is_bool( $value ) ) {
            return $value ? __( 'Yes' ) : __( 'No' );
        }

        return null === $value ? '' : (string) $value;
    }
}
