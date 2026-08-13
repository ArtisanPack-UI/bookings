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
 * **Most buckets are keyed by the client IP**, which means they are only as
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
        'ical_token'   => 30,
    ];

    /**
     * The buckets counted per manage token rather than per caller.
     *
     * The manage endpoints and the provider calendar feed are guarded twice
     * over, and the two limits answer different questions. The per-IP bucket
     * bounds one machine hammering the path; these bound one *booking* or one
     * *feed* being read at that rate however many addresses it comes from, which
     * is what a leaked link being passed around looks like.
     *
     * A feed URL leaks the way a manage link does, and then some: it is pasted
     * into a calendar client and sits in its settings for years, syncing to
     * whatever else that account is signed into.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const TOKEN_KEYED = [ 'manage_token', 'ical_token' ];

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

        $this->report( $response, $limit, $this->limiter->remaining( $key, $limit ) );

        return $response;
    }

    /**
     * Spends one request from a bucket, or reports that it is empty.
     *
     * The Livewire widget never travels through this middleware — its calls go
     * to Livewire's own update endpoint, on the host application's routes — so
     * without this the one guarded write in the package would be unguarded for
     * whichever half of the widget a visitor happens to be using. The component
     * spends from the same bucket, under the same key, so the two halves share
     * one allowance rather than each getting a fresh one.
     *
     * @since 1.0.0
     *
     * @param  string  $bucket  Which configured limit to spend from.
     * @param  Request  $request  The request being counted.
     *
     * @throws InvalidArgumentException When the bucket is not one this package
     *                                  defines.
     *
     * @return bool True when the request may proceed.
     */
    public function consume( string $bucket, Request $request ): bool
    {
        $limit = $this->limitFor( $bucket );
        $key   = $this->keyFor( $bucket, $request );

        if ( $this->limiter->tooManyAttempts( $key, $limit ) ) {
            return false;
        }

        $this->limiter->hit( $key, self::WINDOW_SECONDS );

        return true;
    }

    /**
     * Puts the tightest allowance the request passed through on the response.
     *
     * A route may be guarded by more than one of these — the manage read carries
     * a per-address bucket and a per-token one — and each of them would
     * otherwise overwrite the last one's headers on the way out. Whichever
     * happened to be outermost would be the number a widget backed off on, and
     * pacing against the roomier of two buckets is how a client walks into a 429
     * it was told it had room for.
     *
     * @since 1.0.0
     *
     * @param  Response  $response  The response on its way out.
     * @param  int  $limit  This bucket's size.
     * @param  int  $remaining  What is left in this bucket.
     *
     * @return void
     */
    protected function report( Response $response, int $limit, int $remaining ): void
    {
        $reported = $response->headers->get( 'X-RateLimit-Remaining' );

        if ( null !== $reported && (int) $reported <= $remaining ) {
            return;
        }

        $response->headers->set( 'X-RateLimit-Limit', (string) $limit );
        $response->headers->set( 'X-RateLimit-Remaining', (string) $remaining );
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
     * to be deployed under. A manage token is hashed for a stronger reason: it
     * is the customer's entire credential, and a cache key holding the plain
     * value would put a working link in every one of those places.
     *
     * A token-keyed bucket on a request carrying no token falls back to the
     * address rather than counting every such request together — the shared key
     * a missing token would otherwise produce is one bucket for the whole world,
     * which the first handful of requests would exhaust for everybody.
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
        $subject = (string) $request->ip();

        if ( in_array( $bucket, self::TOKEN_KEYED, true ) ) {
            $token = $request->route( 'token' );

            $subject = is_string( $token ) && '' !== $token ? 'token:' . $token : $subject;
        }

        return sprintf( 'artisanpack:bookings:%s:%s', $bucket, sha1( $subject ) );
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
