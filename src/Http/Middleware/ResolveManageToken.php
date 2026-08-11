<?php

/**
 * Manage token resolution middleware.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Http\Middleware;

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function __;
use function is_string;
use function preg_match;

/**
 * Turns the token in a manage URL into the booking it stands for.
 *
 * This is the whole authentication layer for the self-serve endpoints. A public
 * booking has no account behind it, so the 64 characters in the confirmation
 * email are the only thing distinguishing the customer from a stranger — which
 * makes this the one place that decides whether a request may touch somebody's
 * appointment.
 *
 * Three things happen here, in this order, and the order is the point:
 *
 * 1. **The shape is checked before the database is.** A token is 64 hexadecimal
 *    characters and nothing else can be one, so anything else is refused without
 *    a query. A scanner walking the manage path costs a regular expression
 *    rather than an indexed lookup per request.
 * 2. **The lookup is by hash, and the decision is by `hash_equals()`.** Both
 *    live in {@see ManageTokenService::findBooking()} so that no caller can get
 *    half of it right.
 * 3. **Every failure is the same 404.** An unknown token, a malformed one, and a
 *    token belonging to another site all produce one answer with one message. A
 *    401 here, or a message that distinguished "no such booking" from "not
 *    yours", would confirm which guesses were closer.
 *
 * The resolved booking is attached to the request rather than returned, because
 * middleware cannot hand a value to a controller any other way;
 * {@see \ArtisanPackUI\Bookings\Http\Controllers\Public\Concerns\ResolvesManagedBooking}
 * is the other half of that.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ResolveManageToken
{
    /**
     * The request attribute the resolved booking is left under.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const BOOKING_ATTRIBUTE = 'artisanpack_bookings_booking';

    /**
     * What a manage token has to look like before it is worth a query.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * Constructs the middleware.
     *
     * @since 1.0.0
     *
     * @param  ManageTokenService  $tokens  The service that owns token lookups.
     */
    public function __construct( protected ManageTokenService $tokens )
    {
    }

    /**
     * Handles an incoming request.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure  $next  The rest of the pipeline.
     *
     * @throws NotFoundHttpException When the token does not manage a booking.
     *
     * @return Response The response.
     */
    public function handle( Request $request, Closure $next ): Response
    {
        $token = $request->route( 'token' );

        if ( ! is_string( $token ) || 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
            throw $this->unknown();
        }

        $booking = $this->tokens->findBooking( $token );

        if ( ! $booking instanceof Booking ) {
            throw $this->unknown();
        }

        $request->attributes->set( self::BOOKING_ATTRIBUTE, $booking );

        return $next( $request );
    }

    /**
     * Builds the one answer every rejected token gets.
     *
     * @since 1.0.0
     *
     * @return NotFoundHttpException The refusal.
     */
    protected function unknown(): NotFoundHttpException
    {
        return new NotFoundHttpException( __( 'That booking link is no longer valid. Please check your confirmation email.' ) );
    }
}
