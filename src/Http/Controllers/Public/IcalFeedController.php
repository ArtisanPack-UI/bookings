<?php

/**
 * Public iCal feed endpoints.
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

use ArtisanPackUI\Bookings\Http\Controllers\Public\Concerns\ResolvesManagedBooking;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\IcalFeedService;
use ArtisanPackUI\Bookings\Services\IcalTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function __;
use function config;
use function explode;
use function in_array;
use function is_string;
use function max;
use function preg_replace;
use function sprintf;
use function str_starts_with;
use function substr;
use function trim;

/**
 * The two feeds a calendar client subscribes to.
 *
 * These are not endpoints a person visits. A subscribed feed is refetched on a
 * timer nobody controls — Google roughly hourly, Apple about every fifteen
 * minutes — for as long as the subscription exists, and every subscriber does it
 * independently. The origin's whole defence is that almost every one of those
 * fetches is answered with `304 Not Modified` before a single booking is read:
 *
 * 1. The provider (or the token's booking) is resolved — one indexed lookup.
 * 2. {@see IcalFeedService} computes the entity tag from an aggregate.
 * 3. A matching `If-None-Match` ends the request there, with no rows fetched and
 *    nothing serialised.
 *
 * `Cache-Control: private, max-age=300` is the second half of it. Private
 * because the body is somebody's diary and a shared cache has no business
 * holding it; five minutes because that is short enough that a booking made now
 * shows up in the client's next poll and long enough to absorb a client that
 * fetches the same URL from two places at once.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class IcalFeedController extends Controller
{
    use ResolvesManagedBooking;

    /**
     * How long a client may reuse a feed before revalidating, in seconds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const MAX_AGE = 300;

    /**
     * Constructs the controller.
     *
     * @since 1.0.0
     *
     * @param  IcalFeedService  $feeds  The service that builds calendars and stamps.
     * @param  IcalTokenService  $tokens  The service that owns feed token lookups.
     */
    public function __construct(
        protected IcalFeedService $feeds,
        protected IcalTokenService $tokens,
    ) {
    }

    /**
     * Serves a provider's diary.
     *
     * The address is a token issued to that provider and to nobody else, which
     * is what lets the feed carry the customer's name and email at all. It was
     * the provider's slug once — and a slug is published by
     * `GET api/bookings/services/{slug}/providers`, so that URL was one anybody
     * who could read the booking widget could construct.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     * @param  string  $token  The provider's feed token.
     *
     * @throws NotFoundHttpException When the token addresses no provider's feed.
     *
     * @return Response The calendar, or an empty 304.
     */
    public function provider( Request $request, string $token ): Response
    {
        $provider = $this->resolveProvider( $token );
        $window   = $this->feeds->window();
        $etag     = $this->feeds->providerStamp( $provider, $window );

        if ( $this->matches( $request, $etag ) ) {
            return $this->notModified( $etag );
        }

        return $this->calendar(
            $this->feeds->providerCalendar( $provider, $this->feeds->providerBookings( $provider, $window ) ),
            $etag,
            $provider->slug,
        );
    }

    /**
     * Serves the booking a manage token stands for.
     *
     * The token is the same credential the manage link uses, and it reaches this
     * route through the same middleware — so an unknown token, a malformed one,
     * and one belonging to another site are all the one 404 they are everywhere
     * else.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return Response The calendar, or an empty 304.
     */
    public function customer( Request $request ): Response
    {
        $booking = $this->managedBooking( $request );
        $etag    = $this->feeds->bookingStamp( $booking );

        if ( $this->matches( $request, $etag ) ) {
            return $this->notModified( $etag );
        }

        $booking->loadMissing( [ 'service', 'provider' ] );

        return $this->calendar(
            $this->feeds->customerCalendar( $booking ),
            $etag,
            $booking->booking_number,
        );
    }

    /**
     * Gets the provider a feed token belongs to, or gives up.
     *
     * Every failure is the same 404 with the same message: an unknown token, one
     * belonging to a provider who has been deactivated, one whose feed has been
     * revoked, and one belonging to another site are indistinguishable from
     * outside. Anything more specific would confirm which guesses were closer,
     * and the token is the whole credential.
     *
     * A provider who has left 404s rather than serving an empty calendar, which
     * would be indistinguishable from a quiet week — their subscribers would go
     * on polling a diary nobody maintains, showing nothing, forever.
     *
     * @since 1.0.0
     *
     * @param  string  $token  The feed token from the request.
     *
     * @throws NotFoundHttpException When the token addresses no feed.
     *
     * @return ServiceProvider The provider.
     */
    protected function resolveProvider( string $token ): ServiceProvider
    {
        $provider = $this->tokens->findProvider( $token );

        if ( ! $provider instanceof ServiceProvider ) {
            throw new NotFoundHttpException( __( 'No calendar was found for that address.' ) );
        }

        return $provider;
    }

    /**
     * Determines whether the caller already holds this version of the feed.
     *
     * `If-None-Match` is a list, and each entry may be weak. Both are handled
     * rather than compared as one opaque string: a client that sends two tags,
     * or a proxy that weakens the one it stored, would otherwise be told the feed
     * had changed on every single poll — which is the entire cost this endpoint
     * exists to avoid.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     * @param  string  $etag  The entity tag the feed would be served with.
     *
     * @return bool True when the caller's copy is current.
     */
    protected function matches( Request $request, string $etag ): bool
    {
        $header = trim( (string) $request->headers->get( 'If-None-Match', '' ) );

        if ( '' === $header ) {
            return false;
        }

        if ( '*' === $header ) {
            return true;
        }

        $presented = [];

        foreach ( explode( ',', $header ) as $candidate ) {
            $candidate = trim( $candidate );

            if ( str_starts_with( $candidate, 'W/' ) ) {
                $candidate = substr( $candidate, 2 );
            }

            $presented[] = trim( $candidate, '"' );
        }

        return in_array( $etag, $presented, true );
    }

    /**
     * Builds the answer for a caller whose copy is current.
     *
     * The caching headers go out again with it. A 304 that omitted them would
     * leave the client with a response it may not reuse, and the next poll would
     * be a full fetch of a feed nothing had changed in.
     *
     * @since 1.0.0
     *
     * @param  string  $etag  The entity tag the caller presented.
     *
     * @return Response The empty 304.
     */
    protected function notModified( string $etag ): Response
    {
        return ( new Response( '', Response::HTTP_NOT_MODIFIED ) )
            ->withHeaders( $this->cacheHeaders( $etag ) );
    }

    /**
     * Builds the answer that carries a calendar.
     *
     * @since 1.0.0
     *
     * @param  string  $body  The serialised calendar.
     * @param  string  $etag  The entity tag it was stamped with.
     * @param  string  $filename  What to call the file, without its extension.
     *
     * @return Response The calendar.
     */
    protected function calendar( string $body, string $etag, string $filename ): Response
    {
        return ( new Response( $body, Response::HTTP_OK ) )
            ->withHeaders( $this->cacheHeaders( $etag ) + [
                'Content-Type'        => 'text/calendar; charset=utf-8',
                'Content-Disposition' => sprintf( 'inline; filename="%s.ics"', $this->filename( $filename ) ),
            ] );
    }

    /**
     * Reduces a name to what may safely be interpolated into a header.
     *
     * A provider's slug is written by staff rather than derived, so it reaches
     * this method as arbitrary text — and it is being put inside a quoted header
     * value, where a `"` ends the quoting early and a carriage return ends the
     * header. Everything outside the set a slug is actually made of is dropped
     * rather than escaped, because there is no legitimate slug this changes and
     * an escaping scheme is a thing to get subtly wrong.
     *
     * A name that survives as nothing falls back to a fixed one, so the header is
     * never `filename=".ics"`.
     *
     * @since 1.0.0
     *
     * @param  string  $filename  The name to use, without its extension.
     *
     * @return string The name, safe to quote.
     */
    protected function filename( string $filename ): string
    {
        $safe = preg_replace( '/[^A-Za-z0-9._-]/', '', $filename );

        return is_string( $safe ) && '' !== $safe ? $safe : 'calendar';
    }

    /**
     * Gets the headers every answer from these routes carries.
     *
     * @since 1.0.0
     *
     * @param  string  $etag  The entity tag.
     *
     * @return array<string, string> The headers.
     */
    protected function cacheHeaders( string $etag ): array
    {
        return [
            'ETag'          => sprintf( '"%s"', $etag ),
            'Cache-Control' => sprintf( 'private, max-age=%d', $this->maxAge() ),
        ];
    }

    /**
     * Gets how long a client may reuse a feed before revalidating.
     *
     * @since 1.0.0
     *
     * @return int The lifetime, in seconds.
     */
    protected function maxAge(): int
    {
        $configured = (int) config( 'artisanpack.bookings.public.ical.max_age', self::MAX_AGE );

        return max( 0, $configured );
    }
}
