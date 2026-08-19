<?php

/**
 * Turns a booking-shaped form submission into a booking.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Integrations\Forms;

use ArtisanPackUI\Bookings\Exceptions\BookingException;
use ArtisanPackUI\Bookings\Services\BookingService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Creates a booking as a side effect of a form submission that asked for one.
 *
 * Listens for `artisanpack-ui/forms`' `FormSubmitted` event and, when the
 * submission carries a `booking_slot` field, reads the booking-shaped answers
 * out of it and books them. A submission with no booking_slot field is an
 * ordinary form submission that has nothing to do with bookings, so the listener
 * returns having done nothing — the same form pipeline serves both, and the
 * presence of the appointment field is what tells them apart.
 *
 * The appointment field carries its own mappings: which service it books is in
 * the slot the visitor picked, and where the customer's name, email, and phone
 * come from is the `field_config` the form builder set — each pointing at a
 * field already on the form. So the listener reads the booking_slot value for
 * the service and instant, and the mapped fields for the contact details, with
 * conventional field names as a fallback for a form that set no mappings.
 *
 * The booking is placed through {@see BookingService::createFromFormSubmission()}
 * rather than written here, so a booking made from a form is indistinguishable
 * from one made through the widget: it fires `ap.bookings.creating`, `created`,
 * and `confirmed`, is assigned a provider, and is guarded against a double-book.
 * This class only translates — from the submission's values to the plain array
 * the service maps — and it names no forms class to do it. The event and the
 * submission it carries are typed `object` and read for the handful of accessors
 * every forms submission exposes, because forms is a `suggest` and this listener
 * is only ever bound behind {@see FormsIntegration}'s gate.
 *
 * A booking that cannot be placed — the slot went while the form was open, the
 * service was withdrawn, the intake answers do not satisfy the schema — must not
 * take the form submission down with it. The submission is already stored by the
 * time this runs, and the person who filled it in is owed their confirmation
 * whether or not the booking landed. So the expected failures are caught and
 * logged, and only a genuinely unexpected error is allowed to surface.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class FormBookingListener
{
    /**
     * The optional hidden field naming the service, used only as a fallback.
     *
     * The picked slot names its own service, so this is read only when the slot
     * did not — a bare-instant value from a no-JavaScript fallback. The name is
     * underscore-prefixed so it reads as a control field rather than a question
     * the person filling in the form was asked.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SERVICE_SLUG_FIELD = '_booking_service_slug';

    /**
     * The optional hidden field carrying the customer's timezone.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const TIMEZONE_FIELD = '_booking_customer_timezone';

    /**
     * The field names read as the customer's name, in order of preference.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const NAME_FIELDS = [ '_booking_customer_name', 'name', 'customer_name', 'full_name' ];

    /**
     * The field names read as the customer's email, in order of preference.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const EMAIL_FIELDS = [ '_booking_customer_email', 'email', 'customer_email' ];

    /**
     * The field names read as the customer's phone, in order of preference.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const PHONE_FIELDS = [ '_booking_customer_phone', 'phone', 'customer_phone' ];

    /**
     * Constructs the listener.
     *
     * @since 1.0.0
     *
     * @param  BookingService  $bookings  The service that places the booking.
     */
    public function __construct(
        private BookingService $bookings,
    ) {
    }

    /**
     * Books the slot a form submission chose, when it chose one.
     *
     * @since 1.0.0
     *
     * @param  object  $event  The `FormSubmitted` event, carrying `$submission`.
     *
     * @return void
     */
    public function handle( object $event ): void
    {
        $submission = $event->submission ?? null;

        if ( ! is_object( $submission ) ) {
            return;
        }

        $slotField = self::slotField( $submission );

        if ( null === $slotField ) {
            return;
        }

        $slot = self::parseSlot( (string) ( $slotField->value ?? '' ) );

        if ( null === $slot ) {
            Log::warning( 'ArtisanPack UI Bookings: a booking form submission carried no chosen slot.' );

            return;
        }

        $config      = (array) ( $slotField->field->field_config ?? [] );
        $serviceSlug = '' !== $slot['service_slug']
            ? $slot['service_slug']
            : ( self::firstValue( $submission, [ self::SERVICE_SLUG_FIELD ] ) ?? '' );

        if ( '' === trim( $serviceSlug ) ) {
            Log::warning( 'ArtisanPack UI Bookings: a booking form submission named no service.' );

            return;
        }

        try {
            $this->bookings->createFromFormSubmission( [
                'service_slug'      => $serviceSlug,
                'provider_id'       => $slot['provider_id'],
                'start_time'        => $slot['start'],
                'customer_name'     => self::mapped( $submission, $config, BookingSlotField::CONFIG_NAME_FIELD, self::NAME_FIELDS ) ?? '',
                'customer_email'    => self::email( $submission, $config ) ?? '',
                'customer_phone'    => self::mapped( $submission, $config, BookingSlotField::CONFIG_PHONE_FIELD, self::PHONE_FIELDS ),
                'customer_timezone' => '' !== $slot['timezone']
                    ? $slot['timezone']
                    : self::firstValue( $submission, [ self::TIMEZONE_FIELD ] ),
            ] );
        } catch ( BookingException|InvalidArgumentException $exception ) {
            Log::warning( 'ArtisanPack UI Bookings: could not book a form submission.', [
                'service_slug' => $serviceSlug,
                'reason'       => $exception->getMessage(),
            ] );
        }
    }

    /**
     * Finds the booking_slot answer in a submission.
     *
     * Found by the field's type rather than its name, so the form builder can
     * name the field whatever they like, and it is the only field of its type a
     * submission carries. Its presence is what marks a submission as a booking
     * request; the whole value object is returned rather than just its string so
     * the caller can read the field's `field_config` mappings from `->field`.
     *
     * @since 1.0.0
     *
     * @param  object  $submission  The form submission.
     *
     * @return object|null The booking_slot value, or null when the form had none.
     */
    private static function slotField( object $submission ): ?object
    {
        foreach ( $submission->values as $value ) {
            if ( BookingSlotField::TYPE === ( $value->field_type ?? null ) ) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Reads the service, instant, and provider out of a picked slot.
     *
     * The picker writes its choice as a small JSON object so a field offering
     * more than one service still says which was booked. A bare instant is also
     * accepted — the shape a no-JavaScript fallback or an older field would send
     * — and read as the start with the service left to the submission's own
     * `_booking_service_slug`, if any.
     *
     * @since 1.0.0
     *
     * @param  string  $raw  The stored booking_slot value.
     *
     * @return array{service_slug: string, start: string, provider_id: mixed, timezone: string}|null The slot, or null when empty.
     */
    private static function parseSlot( string $raw ): ?array
    {
        $raw = trim( $raw );

        if ( '' === $raw ) {
            return null;
        }

        $decoded = json_decode( $raw, true );

        if ( is_array( $decoded ) && '' !== trim( (string) ( $decoded['start'] ?? '' ) ) ) {
            return [
                'service_slug' => trim( (string) ( $decoded['service_slug'] ?? '' ) ),
                'start'        => (string) $decoded['start'],
                'provider_id'  => $decoded['provider_id'] ?? null,
                'timezone'     => trim( (string) ( $decoded['timezone'] ?? '' ) ),
            ];
        }

        return [
            'service_slug' => '',
            'start'        => $raw,
            'provider_id'  => null,
            'timezone'     => '',
        ];
    }

    /**
     * Reads a mapped contact value, falling back to well-known field names.
     *
     * The appointment field's `field_config` maps a contact detail to a field
     * already on the form; when that mapping is set and answered it wins. When it
     * is not, the conventional field names are tried, so a form that added no
     * mappings still books if it uses ordinary `name`/`email`/`phone` fields.
     *
     * @since 1.0.0
     *
     * @param  object  $submission  The form submission.
     * @param  array<string, mixed>  $config  The booking_slot field's config.
     * @param  string  $key  The config key holding the mapped field name.
     * @param  array<int, string>  $fallbacks  The field names to try otherwise.
     *
     * @return string|null The value, or null when nothing answered.
     */
    private static function mapped( object $submission, array $config, string $key, array $fallbacks ): ?string
    {
        $mapped = trim( (string) ( $config[ $key ] ?? '' ) );

        if ( '' !== $mapped ) {
            $value = $submission->getValue( $mapped );

            // A mapped field the visitor left blank falls through to the
            // conventional names rather than booking with an empty answer another
            // field on the form may hold.
            if ( null !== $value && '' !== trim( (string) $value ) ) {
                return (string) $value;
            }
        }

        return self::firstValue( $submission, $fallbacks );
    }

    /**
     * Reads the customer's email, preferring the mapped field.
     *
     * Falls back to the submission's own "first field that is an email type"
     * answer after the mapping and the conventional names, so a form with a plain
     * email question books even when nothing points the appointment at it.
     *
     * @since 1.0.0
     *
     * @param  object  $submission  The form submission.
     * @param  array<string, mixed>  $config  The booking_slot field's config.
     *
     * @return string|null The customer's email, or null when none was given.
     */
    private static function email( object $submission, array $config ): ?string
    {
        $mapped = self::mapped( $submission, $config, BookingSlotField::CONFIG_EMAIL_FIELD, self::EMAIL_FIELDS );

        if ( null !== $mapped && '' !== trim( $mapped ) ) {
            return $mapped;
        }

        $found = $submission->getEmailValue();

        return null === $found ? null : (string) $found;
    }

    /**
     * Reads the first of the named fields the submission actually answered.
     *
     * @since 1.0.0
     *
     * @param  object  $submission  The form submission.
     * @param  array<int, string>  $names  The field names to try, in order.
     *
     * @return string|null The first non-null value, or null when none matched.
     */
    private static function firstValue( object $submission, array $names ): ?string
    {
        foreach ( $names as $name ) {
            $value = $submission->getValue( $name );

            if ( null !== $value ) {
                return (string) $value;
            }
        }

        return null;
    }
}
