<?php

/**
 * Public service and availability endpoints.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Http\Controllers\Public;

use ArtisanPackUI\Bookings\Contracts\SlotResolver;
use ArtisanPackUI\Bookings\Http\Controllers\Public\Concerns\ResolvesPublicService;
use ArtisanPackUI\Bookings\Http\Requests\Public\SlotQueryRequest;
use ArtisanPackUI\Bookings\Http\Resources\ServiceProviderResource;
use ArtisanPackUI\Bookings\Http\Resources\ServiceResource;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Support\BookingWindow;
use ArtisanPackUI\Bookings\Support\Slot;
use ArtisanPackUI\Bookings\Support\TimeRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * What the public widget may read: services, their providers, their free slots.
 *
 * Every lookup here goes through `active()`. An inactive service is not merely
 * hidden from the list — it 404s by slug and resolves no availability — because
 * a widget that cached the list yesterday would otherwise keep taking bookings
 * for something an administrator has switched off.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ServiceController extends Controller
{
    use ResolvesPublicService;

    /**
     * Constructs the controller.
     *
     * @since 1.0.0
     *
     * @param  SlotResolver  $slots  The resolver that says what is bookable.
     */
    public function __construct( protected SlotResolver $slots )
    {
    }

    /**
     * Lists the services a customer can book.
     *
     * @since 1.0.0
     *
     * @return AnonymousResourceCollection The active services.
     */
    public function index(): AnonymousResourceCollection
    {
        $services = Service::query()
            ->active()
            ->orderBy( 'name' )
            ->get();

        return ServiceResource::collection( $services );
    }

    /**
     * Lists the providers a service can be booked with.
     *
     * @since 1.0.0
     *
     * @param  string  $slug  The service's slug.
     *
     * @return AnonymousResourceCollection The bookable providers.
     */
    public function providers( string $slug ): AnonymousResourceCollection
    {
        $service = $this->resolvePublicService( $slug );

        return ServiceProviderResource::collection( $service->bookableProviders() );
    }

    /**
     * Resolves the bookable slots in a month.
     *
     * The month is read in the service's own timezone, so "September" means the
     * September a customer looking at that service's page would recognise rather
     * than whatever the server's clock is set to.
     *
     * @since 1.0.0
     *
     * @param  SlotQueryRequest  $request  The validated query.
     * @param  string  $slug  The service's slug.
     *
     * @throws ValidationException When the provider named does not offer the
     *                             service.
     *
     * @return JsonResponse The bookable slots, ascending by start.
     */
    public function slots( SlotQueryRequest $request, string $slug ): JsonResponse
    {
        $service  = $this->resolvePublicService( $slug );
        $provider = $this->resolveProvider( $service, $request->input( 'provider_id' ) );
        $window   = $this->window( $service, (string) $request->input( 'date' ) );

        if ( null === $window ) {
            return response()->json( [ 'data' => [] ] );
        }

        $slots = $this->slots->resolve( $service, $provider, $window );

        return response()->json( [
            'data' => array_map( static fn ( Slot $slot ): array => $slot->toArray(), $slots ),
        ] );
    }

    /**
     * Gets the provider a query named, when it named one.
     *
     * Checked against the service's own bookable list rather than merely looked
     * up, so an id belonging to somebody who does not offer this service — or
     * who has been deactivated — is refused instead of quietly resolving an
     * empty day that reads as "fully booked".
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being queried.
     * @param  mixed  $providerId  The provider id from the query, if any.
     *
     * @throws ValidationException When the provider does not offer the service.
     *
     * @return ServiceProvider|null The provider, or null to resolve across all
     *                              of them.
     */
    protected function resolveProvider( Service $service, mixed $providerId ): ?ServiceProvider
    {
        if ( null === $providerId ) {
            return null;
        }

        foreach ( $service->bookableProviders() as $provider ) {
            if ( (int) $provider->getKey() === (int) $providerId ) {
                return $provider;
            }
        }

        throw ValidationException::withMessages( [
            'provider_id' => __( 'That provider does not offer this service.' ),
        ] );
    }

    /**
     * Gets the span of the named month that is actually bookable.
     *
     * The month is clipped to the configured booking window at both ends. Left
     * unclipped it would offer this morning's slots at four in the afternoon,
     * since availability is computed from schedules and existing bookings and
     * knows nothing about the current time.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being queried.
     * @param  string  $month  The month, validated as `YYYY-MM`.
     *
     * @return TimeRange|null The window to resolve within, or null when none of
     *                        the month can be booked.
     */
    protected function window( Service $service, string $month ): ?TimeRange
    {
        return BookingWindow::month( $service, $month );
    }
}
