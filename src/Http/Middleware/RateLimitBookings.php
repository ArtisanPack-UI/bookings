<?php

/**
 * Public booking rate limit middleware.
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

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

use function __;
use function array_key_exists;
use function array_keys;
use function config;
use function implode;
use function is_numeric;
use function sha1;
use function sprintf;

/**
 * Bounds how often one caller may hit an unauthenticated booking endpoint.
 *
 * Every route this guards is reachable without credentials, so the only thing
 * standing between the endpoint and a script is a bucket. The buckets are named
 * rather than numeric — `bookings.rate-limit:post` rather than
 * `throttle:5,1` — so the limits live in configuration an installation can
 * raise for its own traffic, in one place, instead of being spelled out at each
 * route.
 *
 * **The buckets are keyed by the client IP**, which means they are only as
 * truthful as `Request::ip()`. Behind a reverse proxy or a CDN, an application
 * that has not configured Laravel's `TrustedProxies` middleware sees every
 * request as coming from the proxy — so every customer in the world shares one
 * bucket and the fifth booking of the minute is refused. Configure trusted
 * proxies before putting this in front of real traffic.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class RateLimitBookings
{
    /**
     * The limit each bucket falls back to, in requests per minute.
     *
     * These mirror the shipped configuration. They are read when the setting is
     * missing or does not describe a positive number of requests, rather than
     * letting either case read as "no limit at all" — a blank environment
     * variable is a likelier way to reach zero than a decision to leave the one
     * unauthenticated write endpoint in the package unguarded.
     *
     * @since 1.0.0
     *
     * @var array<string, int>
     */
    protected const DEFAULTS = [
        'post'         => 5,
        'manage_get'   => 20,
        'manage_token' => 60,
        'ical'         => 30,
    ];

    /**
     * The length of one bucket's window, in seconds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const WINDOW_SECONDS = 60;

    /**
     * Constructs the middleware.
     *
     * @since 1.0.0
     *
     * @param  RateLimiter  $limiter  The application's rate limiter.
     */
    public function __construct( protected RateLimiter $limiter )
    {
    }

    /**
     * Handles an incoming request.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure  $next  The rest of the pipeline.
     * @param  string  $bucket  Which configured limit to apply.
     *
     * @throws InvalidArgumentException When the bucket is not one this package
     *                                  defines.
     *
     * @return Response The response.
     */
    public function handle( Request $request, Closure $next, string $bucket = 'post' ): Response
    {
        $limit = $this->limitFor( $bucket );
        $key   = $this->keyFor( $bucket, $request );

        if ( $this->limiter->tooManyAttempts( $key, $limit ) ) {
            return $this->refuse( $key, $limit );
        }

        $this->limiter->hit( $key, self::WINDOW_SECONDS );

        $response = $next( $request );

        $response->headers->add( [
            'X-RateLimit-Limit'     => (string) $limit,
            'X-RateLimit-Remaining' => (string) $this->limiter->remaining( $key, $limit ),
        ] );

        return $response;
    }

    /**
     * Gets how many requests a minute the named bucket allows.
     *
     * An unrecognised name throws rather than falling back to a default. The
     * name is written into a route by a developer, never by an operator, so a
     * typo here is a mistake that should surface the first time the route is
     * hit — the alternative is an endpoint that looks guarded in the route file
     * and is guarded by nothing.
     *
     * @since 1.0.0
     *
     * @param  string  $bucket  The bucket named on the route.
     *
     * @throws InvalidArgumentException When the bucket is unknown.
     *
     * @return int The number of requests allowed per minute.
     */
    protected function limitFor( string $bucket ): int
    {
        if ( ! array_key_exists( $bucket, self::DEFAULTS ) ) {
            throw new InvalidArgumentException( sprintf(
                'bookings.rate-limit was given the unknown bucket "%s"; it knows [%s].',
                $bucket,
                implode( ', ', array_keys( self::DEFAULTS ) ),
            ) );
        }

        $configured = config( 'artisanpack.bookings.public.rate_limits.' . $bucket );

        if ( is_numeric( $configured ) && (int) $configured > 0 ) {
            return (int) $configured;
        }

        return self::DEFAULTS[ $bucket ];
    }

    /**
     * Gets the cache key one caller's bucket is counted under.
     *
     * The address is hashed rather than stored plainly. Cache keys are read by
     * anybody who can see the store — a shared Redis, a log line, a dashboard —
     * and an IP address is personal data in every regime this package is likely
     * to be deployed under.
     *
     * @since 1.0.0
     *
     * @param  string  $bucket  The bucket being counted.
     * @param  Request  $request  The incoming request.
     *
     * @return string The cache key.
     */
    protected function keyFor( string $bucket, Request $request ): string
    {
        return sprintf( 'artisanpack:bookings:%s:%s', $bucket, sha1( (string) $request->ip() ) );
    }

    /**
     * Builds the response for a caller who has used their allowance.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The bucket that is full.
     * @param  int  $limit  The bucket's size.
     *
     * @return JsonResponse The refusal.
     */
    protected function refuse( string $key, int $limit ): JsonResponse
    {
        $retryAfter = $this->limiter->availableIn( $key );

        return new JsonResponse(
            [ 'message' => __( 'Too many requests. Please wait a moment and try again.' ) ],
            JsonResponse::HTTP_TOO_MANY_REQUESTS,
            [
                'Retry-After'           => (string) $retryAfter,
                'X-RateLimit-Limit'     => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ],
        );
    }
}
