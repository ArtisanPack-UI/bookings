<?php

/**
 * The booking_slot form field type.
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

use ArtisanPackUI\Bookings\Models\Service;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View as ViewFactory;

/**
 * The `booking_slot` field type this package contributes to the forms builder.
 *
 * `artisanpack-ui/forms` renders a form field two ways, and both are open to an
 * integration by filter. Its palette is the list {@see \ArtisanPackUI\Forms\Config\FieldTypes}
 * runs through `ap.forms.fieldTypes`, so {@see self::register()} adds one entry
 * shaped exactly like a built-in type — a missing key surfaces the field in the
 * builder half-drawn. Its public form asks {@see \ArtisanPackUI\Forms\Support\FieldRenderer}
 * for each field's HTML, and a custom type ships no blade partial, so forms
 * hands the empty render to `ap.forms.fieldRender` for an integration to fill:
 * {@see self::render()} answers for `booking_slot` and passes every other type
 * straight through untouched.
 *
 * Nothing here references a forms class. The field forms hands {@see self::render()}
 * is typed `object` and read for the three properties every form field carries —
 * `type`, `name`, and `field_config` — because this class is loaded on every
 * installation while forms is a `suggest`, and a signature naming a class that
 * is only sometimes present would fatal the ones it is absent from. The gate in
 * {@see FormsIntegration} is what guarantees the methods are only ever reached
 * with a real form field.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class BookingSlotField
{
    /**
     * The field type key, as it is stored on a form field and matched on render.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const TYPE = 'booking_slot';

    /**
     * The palette category the booking_slot type is filed under.
     *
     * Matches the `category` on {@see self::definition()}. It is named here as
     * well because the two forms surfaces are keyed differently: a type carries
     * its category, but the builder's palette is driven by the reverse map — a
     * category listing the types under it — and a type absent from that list
     * never reaches the palette however complete its own definition is.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CATEGORY = 'advanced';

    /**
     * The `field_config` key holding the slugs of the services the field books.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CONFIG_SERVICES = 'service_slugs';

    /**
     * The `field_config` key mapping the customer's name to a form field.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CONFIG_NAME_FIELD = 'name_field';

    /**
     * The `field_config` key mapping the customer's email to a form field.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CONFIG_EMAIL_FIELD = 'email_field';

    /**
     * The `field_config` key mapping the customer's phone to a form field.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CONFIG_PHONE_FIELD = 'phone_field';

    /**
     * The `field_config` key mapping the opt-in answer to a form field.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CONFIG_OPTIN_FIELD = 'optin_field';

    /**
     * The `field_config` key holding the description shown under the field.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CONFIG_DESCRIPTION = 'description';

    /**
     * Adds the booking_slot type to the forms field palette.
     *
     * Bound to `ap.forms.fieldTypes`, which passes the whole type map and takes
     * back a whole type map. An existing `booking_slot` — an application that has
     * defined its own, or a second call in one request — is left alone rather
     * than overwritten, so the integration cannot clobber a deliberate override.
     *
     * @since 1.0.0
     *
     * @param  array<string, array<string, mixed>>  $types  The field types map.
     *
     * @return array<string, array<string, mixed>> The map with booking_slot in it.
     */
    public static function register( array $types ): array
    {
        if ( ! isset( $types[ self::TYPE ] ) ) {
            $types[ self::TYPE ] = self::definition();
        }

        return $types;
    }

    /**
     * Files the booking_slot type under its palette category.
     *
     * Bound to `ap.forms.fieldCategories`. The palette groups the builder's
     * field buttons by category and reads each category's `fields` list to know
     * which types belong to it, so registering the type on `ap.forms.fieldTypes`
     * is not enough on its own — without this the button never appears, because
     * no category claims it. The type is appended rather than the list replaced,
     * and a category that already lists it is left alone, so a second call or an
     * application that has placed it by hand does not double it up.
     *
     * @since 1.0.0
     *
     * @param  array<string, array{label: string, fields: array<int, string>}>  $categories  The palette categories.
     *
     * @return array<string, array{label: string, fields: array<int, string>}> The categories with booking_slot filed.
     */
    public static function registerCategory( array $categories ): array
    {
        if ( ! isset( $categories[ self::CATEGORY ] ) ) {
            return $categories;
        }

        $fields = $categories[ self::CATEGORY ]['fields'] ?? [];

        if ( ! in_array( self::TYPE, $fields, true ) ) {
            $fields[]                                 = self::TYPE;
            $categories[ self::CATEGORY ]['fields']   = $fields;
        }

        return $categories;
    }

    /**
     * Gets the palette entry for the booking_slot type.
     *
     * The keys mirror a built-in forms type: `label` and `icon` for the palette,
     * the three `supports_*`/`has_options` flags the builder reads to decide
     * which editors to show, `validation_options` for the rules panel, and
     * `defaults` for a freshly dropped field. `booking_slot` carries no
     * placeholder, default value, or options of its own — the slot the customer
     * picks is the value, and where the times come from is the configured
     * service, not a static list — so those flags are all false and the lists
     * empty.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The palette entry.
     */
    public static function definition(): array
    {
        return [
            'label'                  => __( 'Booking Slot' ),
            'icon'                   => 'o-calendar-days',
            'category'               => 'advanced',
            'has_options'            => false,
            'supports_placeholder'   => false,
            'supports_default_value' => false,
            'validation_options'     => [],
            'defaults'               => [
                'label' => __( 'Choose a time' ),
            ],
        ];
    }

    /**
     * Supplies the interactive availability picker for the public form.
     *
     * Bound to `ap.forms.fieldRender`, which forms applies to every field's HTML
     * — the empty string for a custom type it has no partial for. This answers
     * only for `booking_slot` and returns `$html` unchanged for anything else,
     * so it is safe to leave bound for the whole form: a lead-gen form with one
     * booking field and ten ordinary ones passes through here eleven times and
     * is rewritten once.
     *
     * The picker writes the chosen slot into the submission the way every forms
     * field does — `$wire.set` on `formData.{name}` — as a small JSON object
     * naming the service, the instant, and the provider, so the listener can book
     * exactly what the visitor tapped even when the field offers more than one
     * service. A field with no service configured renders a notice rather than an
     * empty calendar.
     *
     * @since 1.0.0
     *
     * @param  string  $html  The HTML forms has for the field so far.
     * @param  object  $field  The form field being rendered.
     *
     * @return string The picker HTML for a booking_slot field, else $html.
     */
    public static function render( string $html, object $field ): string
    {
        if ( self::TYPE !== ( $field->type ?? null ) ) {
            return $html;
        }

        return self::pickerView( $field, false );
    }

    /**
     * Draws the booking_slot field as a live preview on the builder canvas.
     *
     * Bound to `ap.forms.fieldCardPreview`. The same picker the public form
     * shows, in preview mode: the surrounding Livewire component on the canvas is
     * the builder rather than a form being filled in, so a tapped slot is shown
     * but not stored. It lets whoever is building the form see the appointment
     * widget as their visitors will, instead of a bare field label.
     *
     * @since 1.0.0
     *
     * @param  string  $html  The HTML the card has so far.
     * @param  object  $field  The form field being previewed.
     *
     * @return string The preview HTML for a booking_slot field, else $html.
     */
    public static function preview( string $html, object $field ): string
    {
        if ( self::TYPE !== ( $field->type ?? null ) ) {
            return $html;
        }

        return self::pickerView( $field, true );
    }

    /**
     * Supplies the appointment settings panel for the field editor.
     *
     * Bound to `ap.forms.fieldSettings`, which forms applies in the builder's
     * field editor with the field and the form it belongs to. This answers only
     * for `booking_slot`, and renders the service picker and the mappings from
     * the appointment's contact details to fields already on the form — so the
     * appointment reuses the form's own name, email, and phone questions rather
     * than standing up its own.
     *
     * @since 1.0.0
     *
     * @param  string  $html  The settings HTML forms has so far.
     * @param  object  $field  The form field being edited.
     * @param  object  $form  The form the field belongs to.
     *
     * @return string The settings HTML for a booking_slot field, else $html.
     */
    public static function settings( string $html, object $field, object $form ): string
    {
        if ( self::TYPE !== ( $field->type ?? null ) ) {
            return $html;
        }

        return ViewFactory::make( 'bookings::integrations.forms.booking-slot-settings', [
            'field'        => $field,
            'services'     => self::activeServices(),
            'fieldOptions' => self::formFieldOptions( $form, $field ),
        ] )->render();
    }

    /**
     * Renders the picker view for a field, in either live or preview mode.
     *
     * @since 1.0.0
     *
     * @param  object  $field  The form field.
     * @param  bool  $preview  True to render the read-only builder preview.
     *
     * @return string The rendered picker.
     */
    private static function pickerView( object $field, bool $preview ): string
    {
        $config = (array) ( $field->field_config ?? [] );

        return ViewFactory::make( 'bookings::integrations.forms.booking-slot-picker', [
            'services'    => self::pickerServices( (array) ( $config[ self::CONFIG_SERVICES ] ?? [] ) ),
            'fieldName'   => (string) ( $field->name ?? '' ),
            'preview'     => $preview,
            'label'       => (string) ( $field->label ?? '' ),
            'description' => (string) ( $config[ self::CONFIG_DESCRIPTION ] ?? '' ),
            'required'    => (bool) ( $field->is_required ?? false ),
        ] )->render();
    }

    /**
     * Resolves the services a field books into the shape the picker reads.
     *
     * Each entry carries what the picker draws without another round trip — the
     * service name and a human duration for the header — and the URL its month of
     * availability is fetched from. A slug that no active service answers to is
     * dropped rather than shown as a broken option. The slots route is resolved
     * by name, so an application that has renamed the public prefix is followed;
     * when the route is not registered at all the URL is empty and the picker
     * simply finds nothing to load.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $slugs  The configured service slugs.
     *
     * @return array<int, array{slug: string, name: string, durationLabel: string, slotsUrl: string}> The services.
     */
    private static function pickerServices( array $slugs ): array
    {
        $slugs = array_values( array_filter( array_map(
            static fn ( $slug ): string => trim( (string) $slug ),
            $slugs,
        ) ) );

        if ( [] === $slugs ) {
            return [];
        }

        $hasRoute = Route::has( 'artisanpack.bookings.api.services.slots' );

        return Service::query()
            ->active()
            ->whereIn( 'slug', $slugs )
            ->get()
            ->map( static fn ( Service $service ): array => [
                'slug'          => (string) $service->slug,
                'name'          => (string) $service->name,
                'durationLabel' => self::durationLabel( (int) $service->duration ),
                'slotsUrl'      => $hasRoute
                    ? route( 'artisanpack.bookings.api.services.slots', [ 'slug' => $service->slug ] )
                    : '',
            ] )
            ->all();
    }

    /**
     * Gets the active services offered to the settings panel.
     *
     * @since 1.0.0
     *
     * @return array<int, array{slug: string, name: string}> The services.
     */
    private static function activeServices(): array
    {
        return Service::query()
            ->active()
            ->orderBy( 'name' )
            ->get()
            ->map( static fn ( Service $service ): array => [
                'slug' => (string) $service->slug,
                'name' => (string) $service->name,
            ] )
            ->all();
    }

    /**
     * Gets the form's other fields the appointment can map its contacts onto.
     *
     * The booking_slot field itself and forms' layout elements are left out —
     * neither carries an answer a booking could read — so the mapping dropdowns
     * only offer fields that actually collect something.
     *
     * @since 1.0.0
     *
     * @param  object  $form  The form the field belongs to.
     * @param  object  $field  The booking_slot field to exclude.
     *
     * @return array<int, array{name: string, label: string}> The mappable fields.
     */
    private static function formFieldOptions( object $form, object $field ): array
    {
        $fields  = $form->fields ?? [];
        $skip    = [ self::TYPE, 'heading', 'paragraph', 'divider', 'html' ];
        $self    = (string) ( $field->name ?? '' );
        $options = [];

        foreach ( $fields as $candidate ) {
            $name = (string) ( $candidate->name ?? '' );

            if ( '' === $name || $name === $self || in_array( $candidate->type ?? '', $skip, true ) ) {
                continue;
            }

            $options[] = [
                'name'  => $name,
                'label' => (string) ( $candidate->label ?? $name ),
            ];
        }

        return $options;
    }

    /**
     * Renders a whole number of minutes as a human duration.
     *
     * @since 1.0.0
     *
     * @param  int  $minutes  The duration in minutes.
     *
     * @return string The duration, e.g. "1 hour", "45 minutes", "1 hour 30 minutes".
     */
    private static function durationLabel( int $minutes ): string
    {
        if ( $minutes < 60 ) {
            return trans_choice( ':count minute|:count minutes', $minutes, [ 'count' => $minutes ] );
        }

        $hours     = intdiv( $minutes, 60 );
        $remainder = $minutes % 60;
        $hourLabel = trans_choice( ':count hour|:count hours', $hours, [ 'count' => $hours ] );

        if ( 0 === $remainder ) {
            return $hourLabel;
        }

        return $hourLabel . ' ' . trans_choice( ':count minute|:count minutes', $remainder, [ 'count' => $remainder ] );
    }
}
