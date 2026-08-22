<?php

/**
 * Webhook dispatcher.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Services;

use ArtisanPackUI\Bookings\Enums\WebhookDeliveryStatus;
use ArtisanPackUI\Bookings\Jobs\DispatchWebhookDelivery;
use ArtisanPackUI\Bookings\Models\Webhook;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers booking events to the endpoints that subscribed to them.
 *
 * Every attempt is a row in `booking_webhook_deliveries` rather than a log line,
 * because the questions asked about a webhook after the fact — what did you
 * send, when, what came back, are you going to try again — are questions about
 * state, and a consumer claiming an event never arrived is answered by showing
 * them the body and the response code.
 *
 * ## What one delivery is
 *
 * One row per endpoint per event, carrying its own attempt counter. A retry
 * updates that row rather than inserting beside it, so a delivery reads as one
 * thing that succeeded or died rather than as six unrelated attempts an operator
 * has to assemble in their head.
 *
 * ## Retry schedule
 *
 * `webhooks.delivery_backoff_minutes` is the list of delays *between* attempts,
 * so an endpoint gets one attempt plus one per entry. When the list is spent the
 * delivery is marked `dead`, which is terminal and distinct from `failed`: a
 * failed delivery is waiting for its next attempt, a dead one is not coming
 * back.
 *
 * ## Auto-disable
 *
 * Failures are counted on the endpoint, not on the delivery, so an endpoint that
 * has been returning 500 to everything is disabled after
 * `webhooks.failure_threshold` consecutive failures however those failures were
 * spread across events. A single success resets the count — the threshold is for
 * an endpoint that is *gone*, not one that flapped.
 *
 * ## Signing
 *
 * HMAC-SHA256 over `{timestamp}.{body}` with the endpoint's own secret, sent as
 * `X-ArtisanPack-Signature: sha256=<hex>` beside `X-ArtisanPack-Timestamp`. The
 * timestamp is inside the signed string rather than merely alongside it: a
 * signature over the body alone is replayable forever by anybody who captured
 * one request, and a consumer has no way to tell a replay from a retry. Signing
 * the pair lets them reject anything older than their own tolerance.
 *
 * The body is signed as the exact bytes that are sent, which is why the JSON is
 * encoded once here and posted raw rather than handed to the client as an array
 * for it to re-encode — two encoders that disagree about a slash or a unicode
 * escape produce a signature the consumer cannot reproduce.
 *
 * ## The endpoint URL
 *
 * This class posts wherever `booking_webhooks.url` says, from inside the
 * application, and writes the first 2,000 characters of the reply into a row an
 * admin screen can read. That is a request-forgery primitive if the URL is not
 * trusted: an internal service or a cloud metadata endpoint is reachable from
 * here and not from the browser, and the response comes back out through the
 * ledger. Redirects are refused so the reviewed address stays the called one.
 *
 * The address itself is checked by {@see WebhookUrlGuard}, consulted here before
 * every send: a scheme allowlist and a resolved-address check against the
 * loopback, private, link-local, and unique-local ranges, re-run at delivery
 * because a name approved when it was saved can be repointed afterwards. A
 * refused URL kills the delivery rather than failing it. The guard resolves the
 * host once and {@see self::send()} pins the vetted address into the connection,
 * so curl cannot resolve the name a second time and reach an address the guard
 * never saw — the DNS-rebinding case the re-check alone would only narrow. The
 * guard defaults to blocking those ranges and is configured off, or narrowed
 * with an allow list, by an installation that delivers to an internal host on
 * purpose — see `artisanpack.bookings.webhooks.url_guard`. The save-time half of
 * the same guard is {@see \ArtisanPackUI\Bookings\Rules\ValidWebhookUrl}, for a
 * screen that accepts a URL below operator trust.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class WebhookDispatcher
{
    /**
     * How much of a response body is kept on the delivery row.
     *
     * Enough to read an error message out of, and far short of what an endpoint
     * answering a webhook with an entire HTML page would otherwise write into
     * every row of the ledger.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const RESPONSE_BODY_LIMIT = 2000;

    /**
     * Constructs the dispatcher.
     *
     * @since 1.0.0
     *
     * @param  WebhookUrlGuard  $urlGuard  Decides whether a URL is safe to call.
     */
    public function __construct( protected WebhookUrlGuard $urlGuard )
    {
    }

    /**
     * Queues an event for every endpoint subscribed to it.
     *
     * Subscription is matched in PHP rather than in SQL, for the reason given on
     * {@see Webhook::subscribesTo()}: JSON containment is spelled three ways
     * across the engines this package supports, and the active endpoint list is
     * short.
     *
     * ## Which site's endpoints
     *
     * A caller that knows the site the event belongs to should say so, and every
     * caller inside this package does. Left to the ambient global scope, an
     * event raised where no site has been resolved — a console command sweeping
     * every tenant, a queue worker with no request behind it — would reach every
     * endpoint in the installation, which is one tenant's booking sent to
     * another tenant's integration. Naming the site turns that from a leak into
     * a query.
     *
     * Passing null keeps the ambient scope, which is right for a single-tenant
     * installation and for a caller raising an event that genuinely belongs to
     * whatever site is in context.
     *
     * @since 1.0.0
     *
     * @param  string  $event  The event name, such as `booking.confirmed`.
     * @param  array<string, mixed>  $payload  The event body.
     * @param  int|string|null  $siteId  The site whose endpoints should receive
     *                                   it, or null for the site in context.
     *
     * @return array<int, WebhookDelivery> The deliveries queued, one per endpoint.
     */
    public function dispatch( string $event, array $payload, int|string|null $siteId = null ): array
    {
        $query = Webhook::query()->active();

        if ( null !== $siteId ) {
            $query->acrossAllSites()->where( 'site_id', $siteId );
        }

        $endpoints = $query
            ->get()
            ->filter( static fn ( Webhook $webhook ): bool => $webhook->subscribesTo( $event ) );

        $queued = [];

        foreach ( $endpoints as $endpoint ) {
            $queued[] = $this->queue( $endpoint, $event, $payload );
        }

        return $queued;
    }

    /**
     * Queues an event for one endpoint.
     *
     * @since 1.0.0
     *
     * @param  Webhook  $webhook  The endpoint to deliver to.
     * @param  string  $event  The event name.
     * @param  array<string, mixed>  $payload  The event body.
     *
     * @return WebhookDelivery The queued delivery.
     */
    public function queue( Webhook $webhook, string $event, array $payload ): WebhookDelivery
    {
        $delivery = new WebhookDelivery( [
            'webhook_id'     => $webhook->getKey(),
            'event_type'     => $event,
            'payload'        => $payload,
            'attempt_number' => 1,
            'status'         => WebhookDeliveryStatus::Pending,
        ] );

        $delivery->save();

        DispatchWebhookDelivery::dispatch( (int) $delivery->getKey() )
            ->onQueue( self::queueName() );

        return $delivery;
    }

    /**
     * Makes one delivery attempt and records what came back.
     *
     * Nothing here throws. A delivery attempt fails for reasons that are the
     * consumer's — a refused connection, a 500, a name that no longer resolves —
     * and every one of them is an outcome to write down rather than an error to
     * surface. The job that calls this reads the row afterwards to decide
     * whether to come back.
     *
     * A delivery that is no longer retryable is left alone, so a queue that
     * redelivers a job whose attempt already succeeded finds a `success` row and
     * does nothing rather than sending the consumer the same event again.
     *
     * **That check is a terminal-state guard, not a claim** — but the caller has
     * claimed before it reaches here. {@see DispatchWebhookDelivery} calls
     * {@see WebhookDelivery::claim()} first, a compare-and-set that leases the
     * still-due row, so the two producers of a delivery — the in-flight job and
     * the retry sweep {@see WebhookDelivery::scopeDue()} feeds — cannot both send
     * the consumer the same event. This method assumes that claim was won and
     * simply records the outcome.
     *
     * @since 1.0.0
     *
     * @param  WebhookDelivery  $delivery  The delivery to attempt.
     *
     * @return bool True when the endpoint accepted it.
     */
    public function deliver( WebhookDelivery $delivery ): bool
    {
        if ( ! $delivery->isRetryable() ) {
            return WebhookDeliveryStatus::Success === $delivery->status;
        }

        $webhook = $this->endpointFor( $delivery );

        if ( null === $webhook ) {
            $this->markDead( $delivery, null, 'The endpoint no longer exists.' );

            return false;
        }

        // A disabled endpoint kills the delivery outright rather than failing
        // it. Retrying into an endpoint that has already been given up on is
        // precisely the queue traffic that disabling it was meant to stop.
        if ( $webhook->isDisabled() || ! $webhook->is_active ) {
            $this->markDead( $delivery, null, 'The endpoint is no longer active.' );

            return false;
        }

        // Re-checked here rather than only when the endpoint was saved, because
        // a name approved once can be repointed afterwards — the DNS-rebinding
        // case a save-time check never sees. A refused URL kills the delivery
        // the way a disabled endpoint does: it is not the consumer's fault, no
        // retry will change the answer, and the reason is left on the row.
        $decision = $this->urlGuard->decide( $webhook->url );

        if ( ! $decision->allowed ) {
            $this->markDead( $delivery, null, (string) $decision->reason );

            return false;
        }

        try {
            $body = json_encode(
                $delivery->payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch ( Throwable $encoding ) {
            // Unencodable in this attempt is unencodable in every one of them —
            // the payload is stored, not rebuilt — so there is nothing a retry
            // would do differently, and the endpoint did nothing wrong.
            $this->markDead( $delivery, null, 'The payload could not be encoded: ' . $encoding->getMessage() );

            return false;
        }

        $response = $this->send( $webhook, $delivery, $body, $decision );

        if ( $response instanceof Response && $response->successful() ) {
            $this->recordSuccess( $webhook, $delivery, $response );

            return true;
        }

        $this->recordFailure(
            $webhook,
            $delivery,
            $response instanceof Response ? $response->status() : null,
            $response instanceof Response ? $response->body() : (string) $response,
        );

        return false;
    }

    /**
     * Computes the signature a consumer verifies a delivery with.
     *
     * Compared with {@see hash_equals()} on the consumer's side; exposed here so
     * that an application receiving these webhooks in the same codebase — a
     * second package, a test harness — verifies with the implementation that
     * signed rather than with a second one written from the documentation.
     *
     * @since 1.0.0
     *
     * @param  string  $body  The exact request body bytes.
     * @param  string  $timestamp  The timestamp sent with the request.
     * @param  string  $secret  The endpoint's signing secret.
     *
     * @return string The `sha256=<hex>` signature header value.
     */
    public static function signature( string $body, string $timestamp, string $secret ): string
    {
        return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
    }

    /**
     * Determines whether a signature matches a body.
     *
     * @since 1.0.0
     *
     * @param  string  $signature  The signature presented by the request.
     * @param  string  $body  The exact request body bytes.
     * @param  string  $timestamp  The timestamp presented by the request.
     * @param  string  $secret  The endpoint's signing secret.
     *
     * @return bool True when the signature is the one this secret produces.
     */
    public static function signatureMatches(
        string $signature,
        string $body,
        string $timestamp,
        string $secret,
    ): bool {
        return hash_equals( self::signature( $body, $timestamp, $secret ), $signature );
    }

    /**
     * Gets the delays between attempts, in minutes.
     *
     * Non-positive and non-numeric entries are dropped rather than clamped. A
     * zero in the list would mean an immediate retry into an endpoint that has
     * just failed, which is the retry storm the schedule exists to prevent, and
     * a configuration file that has one in it is better read as wrong than as
     * asking for that.
     *
     * @since 1.0.0
     *
     * @return array<int, int> The delays, in configured order.
     */
    public static function backoffMinutes(): array
    {
        $configured = config( 'artisanpack.bookings.webhooks.delivery_backoff_minutes', [] );

        if ( ! is_array( $configured ) || ! array_is_list( $configured ) ) {
            return [];
        }

        return array_values( array_filter(
            array_map( static fn ( mixed $minutes ): int => (int) $minutes, $configured ),
            static fn ( int $minutes ): bool => $minutes > 0,
        ) );
    }

    /**
     * Gets the queue the delivery jobs are pushed onto.
     *
     * @since 1.0.0
     *
     * @return string|null The queue name, or null for the connection's default.
     */
    public static function queueName(): ?string
    {
        $queue = config( 'artisanpack.bookings.webhooks.queue' );

        return is_string( $queue ) && '' !== $queue ? $queue : null;
    }

    /**
     * Posts the payload to the endpoint.
     *
     * Redirects are not followed. A webhook URL is an address somebody typed
     * into an admin screen, and this process can reach hosts the person who
     * typed it cannot — an internal service, a cloud metadata endpoint. Whatever
     * validation an installation puts on that URL, following a 302 would hand
     * the choice of final destination back to the endpoint, and the response
     * body of wherever it lands is written to the delivery ledger where the same
     * admin screen can read it. Refusing to follow keeps the address that was
     * reviewed the address that is called; a redirect is recorded as the
     * non-2xx it is, which is also the honest answer for a consumer that has
     * moved and not said so.
     *
     * The guard already resolved and vetted the host; when it says so, that
     * address is pinned into the connection with `CURLOPT_RESOLVE` so curl uses
     * it rather than resolving the name a second time. The second lookup is the
     * DNS-rebinding gap: a name that answered with a public address to the
     * guard's query can answer with loopback to curl's, and vetting the first
     * while connecting on the second closes nothing. TLS still verifies against
     * the hostname — only the address is pinned. When the guard did not pin (it
     * is off, the host is allow-listed, or the host is an IP literal) the URL is
     * posted as given.
     *
     * @since 1.0.0
     *
     * @param  Webhook  $webhook  The endpoint to deliver to.
     * @param  WebhookDelivery  $delivery  The delivery being attempted.
     * @param  string  $body  The exact bytes to send and to sign.
     * @param  WebhookUrlDecision  $decision  The guard's decision, carrying any
     *                                        vetted address to pin.
     *
     * @return Response|string The response, or the reason there was not one.
     */
    protected function send(
        Webhook $webhook,
        WebhookDelivery $delivery,
        string $body,
        WebhookUrlDecision $decision,
    ): Response|string {
        $timestamp = (string) Carbon::now()->getTimestamp();

        try {
            $request = Http::withHeaders( [
                'User-Agent'              => 'ArtisanPackUI-Bookings/1.0',
                'X-ArtisanPack-Event'     => $delivery->event_type,
                'X-ArtisanPack-Delivery'  => (string) $delivery->getKey(),
                'X-ArtisanPack-Attempt'   => (string) $delivery->attempt_number,
                'X-ArtisanPack-Timestamp' => $timestamp,
                'X-ArtisanPack-Signature' => self::signature( $body, $timestamp, $webhook->secret ),
            ] )
                ->withoutRedirecting()
                ->connectTimeout( self::connectTimeoutSeconds() )
                ->timeout( self::timeoutSeconds() )
                ->withBody( $body, 'application/json' );

            $url = $webhook->url;

            if ( $decision->pinnable && [] !== $decision->addresses ) {
                if ( self::canPinAddresses() ) {
                    $request = $request->withOptions( [
                        'curl' => [ CURLOPT_RESOLVE => self::pinnedResolution( $decision ) ],
                    ] );

                    // Posted under the host the address was pinned to rather than
                    // the stored one, so the client resolves the string the pin
                    // was keyed on — the same host, canonicalised to the ASCII,
                    // dot-free form.
                    $url = $decision->requestUrl ?? $url;
                } else {
                    // The guard vetted an address to pin, but `CURLOPT_RESOLVE`
                    // needs ext-curl with libcurl 7.59+ and the curl transport in
                    // use. Without it the pin silently no-ops and curl resolves
                    // the name a second time — the DNS-rebinding gap the pin
                    // exists to close. The delivery still goes out (the address
                    // was vetted moments ago), but the operator has to know the
                    // rebinding protection is not in force.
                    Log::warning( 'A booking webhook address could not be pinned; ext-curl with libcurl 7.59+ is required. The delivery proceeds without DNS-rebinding protection.', [
                        'delivery_id' => $delivery->getKey(),
                        'webhook_id'  => $webhook->getKey(),
                        'host'        => $decision->host,
                    ] );
                }
            }

            return $request->post( $url );
        } catch ( ConnectionException $unreachable ) {
            return $unreachable->getMessage();
        } catch ( Throwable $failed ) {
            // The client is configured not to throw on a response status, so
            // anything landing here is the request never having been made — a
            // malformed URL, a TLS failure, a transport the host has no support
            // for. All of them are the endpoint's problem to fix and none of
            // them should take the queue worker down with them.
            Log::warning( 'A booking webhook delivery could not be attempted.', [
                'delivery_id' => $delivery->getKey(),
                'webhook_id'  => $webhook->getKey(),
                'exception'   => $failed->getMessage(),
            ] );

            return $failed->getMessage();
        }
    }

    /**
     * Builds the `CURLOPT_RESOLVE` entry that pins a decision's vetted addresses.
     *
     * All of them are pinned, comma-joined into the one entry for the host and
     * port, so curl may fail over between vetted addresses without ever falling
     * back to resolving the name itself. Every address was cleared by the guard,
     * so any of them is a safe target. An IPv6 address is bracketed, which is how
     * curl's resolve list tells the address's colons from the host-port ones.
     *
     * Comma-joined addresses in one entry are a libcurl 7.59 feature, and the
     * option is read only by the curl handler — an installation on older libcurl
     * or without `ext-curl` pins nothing, and the guard's delivery-time re-check
     * is the defence it is left with. See the README's "Outbound webhooks" note.
     *
     * @since 1.0.0
     *
     * @param  WebhookUrlDecision  $decision  The allow carrying the addresses.
     *
     * @return array<int, string> The single resolve entry, for `CURLOPT_RESOLVE`.
     */
    protected static function pinnedResolution( WebhookUrlDecision $decision ): array
    {
        $addresses = array_map(
            static fn ( string $address ): string => str_contains( $address, ':' ) ? '[' . $address . ']' : $address,
            $decision->addresses,
        );

        return [ sprintf( '%s:%d:%s', $decision->host, $decision->port, implode( ',', $addresses ) ) ];
    }

    /**
     * Determines whether a vetted address can actually be pinned.
     *
     * `CURLOPT_RESOLVE` with a comma-joined multi-address entry needs `ext-curl`
     * (which is also what makes the curl transport the one Guzzle reaches for by
     * default) and libcurl 7.59+. Where either is missing the option is silently
     * ignored and curl resolves the name a second time — so the caller has to know
     * the pin did not take rather than believing the DNS-rebinding gap is closed.
     *
     * @since 1.0.0
     *
     * @return bool True when an address pin will be honoured.
     */
    protected static function canPinAddresses(): bool
    {
        if ( ! defined( 'CURLOPT_RESOLVE' ) || ! function_exists( 'curl_version' ) ) {
            return false;
        }

        $version = curl_version();

        // 0x073b00 is libcurl 7.59.0, where a single CURLOPT_RESOLVE entry gained
        // the comma-joined multi-address form pinnedResolution() relies on.
        return is_array( $version ) && (int) ( $version['version_number'] ?? 0 ) >= 0x073B00;
    }

    /**
     * Records an accepted delivery and clears the endpoint's failure streak.
     *
     * @since 1.0.0
     *
     * @param  Webhook  $webhook  The endpoint that accepted it.
     * @param  WebhookDelivery  $delivery  The delivery that was accepted.
     * @param  Response  $response  What the endpoint answered with.
     *
     * @return void
     */
    protected function recordSuccess( Webhook $webhook, WebhookDelivery $delivery, Response $response ): void
    {
        $now = Carbon::now();

        $delivery->forceFill( [
            'status'          => WebhookDeliveryStatus::Success,
            'response_status' => $response->status(),
            'response_body'   => self::truncate( $response->body() ),
            'attempted_at'    => $now,
            'succeeded_at'    => $now,
            'next_attempt_at' => null,
        ] )->save();

        // Written straight to the row rather than through the model's own
        // attributes, so that a worker holding a stale copy of the endpoint does
        // not write a failure count back over a concurrent delivery's reset.
        $webhook->newQueryWithoutScopes()
            ->whereKey( $webhook->getKey() )
            ->update( [
                'consecutive_failure_count' => 0,
                'last_success_at'           => $now,
                'updated_at'                => $now,
            ] );

        $webhook->forceFill( [
            'consecutive_failure_count' => 0,
            'last_success_at'           => $now,
        ] )->syncOriginal();
    }

    /**
     * Records a rejected delivery, schedules its retry, and counts the failure.
     *
     * @since 1.0.0
     *
     * @param  Webhook  $webhook  The endpoint that rejected it.
     * @param  WebhookDelivery  $delivery  The delivery that was rejected.
     * @param  int|null  $status  The response status, when there was a response.
     * @param  string  $body  The response body, or why there was not one.
     *
     * @return void
     */
    protected function recordFailure(
        Webhook $webhook,
        WebhookDelivery $delivery,
        ?int $status,
        string $body,
    ): void {
        $backoff = self::backoffMinutes();

        // The delivery's attempt number is one-based, so the delay that follows
        // attempt N is the Nth entry — and running off the end of the list is
        // what "the retry budget is spent" means.
        $delay = $backoff[ $delivery->attempt_number - 1 ] ?? null;
        $now   = Carbon::now();

        $delivery->forceFill( [
            'status'          => null === $delay ? WebhookDeliveryStatus::Dead : WebhookDeliveryStatus::Failed,
            'response_status' => $status,
            'response_body'   => self::truncate( $body ),
            'attempted_at'    => $now,
            'next_attempt_at' => null === $delay ? null : $now->copy()->addMinutes( $delay ),

            // Moved on only when there is another attempt to number. A dead row
            // keeps the number of the attempt that killed it, which is what an
            // operator counting attempts against the schedule expects to read.
            'attempt_number'  => null === $delay ? $delivery->attempt_number : $delivery->attempt_number + 1,
        ] )->save();

        $this->countFailure( $webhook );
    }

    /**
     * Marks a delivery as beyond retrying without counting it against the endpoint.
     *
     * Used for the failures that are this package's to own rather than the
     * consumer's — an endpoint that has been deleted or disabled underneath a
     * queued delivery, a payload that will not encode. Counting those would
     * disable an endpoint for the crime of having been switched off.
     *
     * @since 1.0.0
     *
     * @param  WebhookDelivery  $delivery  The delivery to kill.
     * @param  int|null  $status  The response status, when there was one.
     * @param  string  $reason  Why it will not be attempted again.
     *
     * @return void
     */
    protected function markDead( WebhookDelivery $delivery, ?int $status, string $reason ): void
    {
        $delivery->forceFill( [
            'status'          => WebhookDeliveryStatus::Dead,
            'response_status' => $status,
            'response_body'   => self::truncate( $reason ),
            'attempted_at'    => Carbon::now(),
            'next_attempt_at' => null,
        ] )->save();
    }

    /**
     * Counts a failure against an endpoint, disabling it at the threshold.
     *
     * The increment is done in SQL and its result read back from the same
     * statement's row, rather than incremented on a loaded model. Two workers
     * failing against the same endpoint at once would otherwise both read 9,
     * both write 10, and leave the count at 10 having failed twice — which is
     * survivable — but they would also both see themselves cross the threshold.
     * {@see Webhook::disable()} settles that half with a conditional update, so
     * only one of them fires the event.
     *
     * @since 1.0.0
     *
     * @param  Webhook  $webhook  The endpoint that failed.
     *
     * @return void
     */
    protected function countFailure( Webhook $webhook ): void
    {
        $webhook->newQueryWithoutScopes()
            ->whereKey( $webhook->getKey() )
            ->increment( 'consecutive_failure_count' );

        $webhook->refresh();

        $threshold = self::failureThreshold();

        if ( $threshold <= 0 || $webhook->consecutive_failure_count < $threshold ) {
            return;
        }

        $webhook->disable( sprintf(
            'The endpoint failed %d consecutive deliveries.',
            $webhook->consecutive_failure_count,
        ) );
    }

    /**
     * Loads the endpoint a delivery was addressed to.
     *
     * Read without the site scope. The delivery runs on a queue worker, which
     * has no request to resolve a site from, and an endpoint that came back null
     * there would be killed as deleted while it sits in the database.
     *
     * @since 1.0.0
     *
     * @param  WebhookDelivery  $delivery  The delivery being attempted.
     *
     * @return Webhook|null The endpoint, or null when it has been deleted.
     */
    protected function endpointFor( WebhookDelivery $delivery ): ?Webhook
    {
        /** @var Webhook|null $webhook */
        $webhook = Webhook::query()
            ->acrossAllSites()
            ->whereKey( $delivery->webhook_id )
            ->first();

        return $webhook;
    }

    /**
     * Gets how many consecutive failures disable an endpoint.
     *
     * @since 1.0.0
     *
     * @return int The threshold, or zero when disabling is switched off.
     */
    protected static function failureThreshold(): int
    {
        return max( 0, (int) config( 'artisanpack.bookings.webhooks.failure_threshold', 10 ) );
    }

    /**
     * Gets how long one delivery attempt may take.
     *
     * @since 1.0.0
     *
     * @return int The request timeout, in seconds.
     */
    protected static function timeoutSeconds(): int
    {
        return max( 1, (int) config( 'artisanpack.bookings.webhooks.timeout_seconds', 10 ) );
    }

    /**
     * Gets how long one delivery attempt may spend connecting.
     *
     * @since 1.0.0
     *
     * @return int The connection timeout, in seconds.
     */
    protected static function connectTimeoutSeconds(): int
    {
        return max( 1, (int) config( 'artisanpack.bookings.webhooks.connect_timeout_seconds', 5 ) );
    }

    /**
     * Cuts a response body down to what the ledger keeps.
     *
     * Scrubbed to valid UTF-8 before it is cut, because an endpoint answers with
     * whatever it likes — a gzip stream, a binary blob, a page in some other
     * encoding — and MySQL rejects an invalid byte sequence on a `utf8mb4`
     * column outright. That rejection would come back as a QueryException from
     * inside the write that records a *failure*, which is the one place in this
     * class that must not throw: the delivery would be left unrecorded, and the
     * job that was supposed to be unfailable would fail. Cutting first and
     * scrubbing afterwards has the same hole in miniature, since the cut can
     * land in the middle of a multi-byte character.
     *
     * @since 1.0.0
     *
     * @param  string  $body  The body as it came back.
     *
     * @return string The stored body.
     */
    protected static function truncate( string $body ): string
    {
        return mb_strimwidth(
            mb_convert_encoding( $body, 'UTF-8', 'UTF-8' ),
            0,
            self::RESPONSE_BODY_LIMIT,
            '…',
        );
    }
}
