<?php

/**
 * Admin screen controller.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Http\Controllers\Admin;

use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Renders the staff-facing admin screens (plan §6.2).
 *
 * Every action does one thing: hand a page view whatever it needs to mount the
 * admin Livewire components. The gate that guards them is the `bookings.admin`
 * middleware on the route group, not this controller; the layout the pages
 * render inside is chosen by the view composer in the service provider, not
 * here.
 *
 * These are controller actions rather than route closures for one concrete
 * reason: a host application runs `php artisan route:cache` in production, and
 * Laravel cannot serialize a Closure — so a package that shipped closure routes
 * would break the caching command for the whole application, not merely for its
 * own routes. The public and widget routes already avoid closures for the same
 * reason.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class AdminScreenController extends Controller
{
    /**
     * Shows the bookings list, with the detail of a chosen row beside it.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return View The rendered screen.
     */
    public function bookings( Request $request ): View
    {
        $bookingId = $request->integer( 'booking' ) ?: null;

        return view( 'bookings::admin.pages.bookings', [
            'booking' => null === $bookingId ? null : Booking::query()->find( $bookingId ),
        ] );
    }

    /**
     * Shows the admin-authored booking form (plan §6.5).
     *
     * @since 1.0.0
     *
     * @return View The rendered screen.
     */
    public function createBooking(): View
    {
        return view( 'bookings::admin.pages.booking-create' );
    }

    /**
     * Shows a single booking's detail.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking, resolved by route-model binding.
     *
     * @return View The rendered screen.
     */
    public function showBooking( Booking $booking ): View
    {
        return view( 'bookings::admin.pages.booking-detail', [
            'booking' => $booking,
        ] );
    }

    /**
     * Shows the month calendar of bookings.
     *
     * @since 1.0.0
     *
     * @return View The rendered screen.
     */
    public function calendar(): View
    {
        return view( 'bookings::admin.pages.calendar' );
    }

    /**
     * Shows the services list, with the editor beside it.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return View The rendered screen.
     */
    public function services( Request $request ): View
    {
        return view( 'bookings::admin.pages.services', [
            'serviceId' => $this->editableId( $request, 'service' ),
        ] );
    }

    /**
     * Shows a service's intake-schema editor.
     *
     * @since 1.0.0
     *
     * @param  int  $service  The service id from the route.
     *
     * @return View The rendered screen.
     */
    public function intakeSchema( int $service ): View
    {
        return view( 'bookings::admin.pages.intake-schema', [
            'serviceId' => $service,
        ] );
    }

    /**
     * Shows the providers list, with the editor and schedule editor beside it.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return View The rendered screen.
     */
    public function providers( Request $request ): View
    {
        return view( 'bookings::admin.pages.providers', [
            'providerId'   => $this->editableId( $request, 'provider' ),
            'availability' => $request->integer( 'availability' ) ?: null,
        ] );
    }

    /**
     * Shows the service-level blackout dates.
     *
     * @since 1.0.0
     *
     * @return View The rendered screen.
     */
    public function blackoutDates(): View
    {
        return view( 'bookings::admin.pages.blackout-dates' );
    }

    /**
     * Shows the recurring-series list, with its editor beside it.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return View The rendered screen.
     */
    public function series( Request $request ): View
    {
        return view( 'bookings::admin.pages.series', [
            'seriesId' => $request->integer( 'series' ) ?: null,
        ] );
    }

    /**
     * Shows the per-provider calendar connections.
     *
     * @since 1.0.0
     *
     * @return View The rendered screen.
     */
    public function calendarConnections(): View
    {
        return view( 'bookings::admin.pages.calendar-connections' );
    }

    /**
     * Shows the webhook endpoints, with a chosen endpoint's delivery log.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return View The rendered screen.
     */
    public function webhooks( Request $request ): View
    {
        return view( 'bookings::admin.pages.webhooks', [
            'webhookId' => $request->integer( 'webhook' ) ?: null,
        ] );
    }

    /**
     * Shows the notification delivery log.
     *
     * @since 1.0.0
     *
     * @return View The rendered screen.
     */
    public function notifications(): View
    {
        return view( 'bookings::admin.pages.notifications' );
    }

    /**
     * Shows the general configuration surface.
     *
     * @since 1.0.0
     *
     * @return View The rendered screen.
     */
    public function settings(): View
    {
        return view( 'bookings::admin.pages.settings' );
    }

    /**
     * Reads an "edit this row, or a new one" query parameter.
     *
     * The list components dispatch their edit intent with a null id for a new
     * row, and the hand-off turns that into `?service=new`. So the parameter has
     * three states the page has to tell apart: an integer id to edit, the string
     * `new` to open an empty editor, and absent to show no editor at all. An
     * integer wins; `new` becomes the string; anything else is absent.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     * @param  string  $key  The query parameter name.
     *
     * @return int|string|null The row id, the string `new`, or null.
     */
    protected function editableId( Request $request, string $key ): int|string|null
    {
        $id = $request->integer( $key );

        if ( 0 !== $id ) {
            return $id;
        }

        return 'new' === $request->query( $key ) ? 'new' : null;
    }
}
