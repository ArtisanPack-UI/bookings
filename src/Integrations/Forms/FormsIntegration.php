<?php

/**
 * The forms integration's boot-time wiring.
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

use ArtisanPackUI\Bookings\Support\HookSubscriptions;
use Illuminate\Support\Facades\Event;

/**
 * Subscribes this package to `artisanpack-ui/forms`, when forms is installed.
 *
 * The one place the forms integration attaches itself to the running
 * application. It binds three seams, all behind the same gate: the palette
 * filter that adds the `booking_slot` type, the render filter that draws its
 * picker, and the event listener that books what the picker captured. Grouping
 * them here means a single call from the service provider stands the whole
 * integration up, and the parts that are only meaningful together are wired
 * together.
 *
 * The gate is {@see HookSubscriptions::whenInstalled()} rather than a bare
 * `addFilter()`, for the reason the whole {@see HookSubscriptions} class exists:
 * forms is a `suggest`, so on an installation without it the closure below is
 * never entered and nothing of ours is left hanging off a filter that will never
 * be applied or an event that will never fire. The listener is registered by its
 * class name — `Event::listen()` resolves it from the container when the event
 * fires — so binding it costs nothing until a form is actually submitted.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class FormsIntegration
{
    /**
     * The forms event a submission fires, subscribed to by its name.
     *
     * Named as a string rather than a `::class` constant so this file does not
     * refer to a forms class at all: the string is only ever handed to
     * `Event::listen()` inside the gated closure, where forms is present to
     * dispatch it.
     *
     * @since 1.0.0
     *
     * @var string
     */
    private const SUBMITTED_EVENT = 'ArtisanPackUI\\Forms\\Events\\FormSubmitted';

    /**
     * Wires the forms integration, when the application wants it and forms is here.
     *
     * Two gates, in order. First config: an installation that mounts the booking
     * field or the submission listener by hand turns `forms.auto_register` off and
     * keeps the wiring its own. Then the package gate: forms has to actually be
     * installed for any of the three subscriptions to mean anything.
     *
     * @since 1.0.0
     *
     * @return bool True when the subscriptions were bound.
     */
    public static function subscribe(): bool
    {
        if ( ! config( 'artisanpack.bookings.forms.auto_register', true ) ) {
            return false;
        }

        return HookSubscriptions::whenInstalled( 'forms', static function (): void {
            addFilter( 'ap.forms.fieldTypes', [ BookingSlotField::class, 'register' ] );
            addFilter( 'ap.forms.fieldCategories', [ BookingSlotField::class, 'registerCategory' ] );
            addFilter( 'ap.forms.fieldSettings', [ BookingSlotField::class, 'settings' ] );
            addFilter( 'ap.forms.fieldCardPreview', [ BookingSlotField::class, 'preview' ] );
            addFilter( 'ap.forms.fieldRender', [ BookingSlotField::class, 'render' ] );

            Event::listen( self::SUBMITTED_EVENT, [ FormBookingListener::class, 'handle' ] );
        } );
    }
}
