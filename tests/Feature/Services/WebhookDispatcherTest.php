<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Contracts\ResolvesHostAddresses;
use ArtisanPackUI\Bookings\Enums\WebhookDeliveryStatus;
use ArtisanPackUI\Bookings\Events\WebhookDisabled;
use ArtisanPackUI\Bookings\Jobs\DispatchWebhookDelivery;
use ArtisanPackUI\Bookings\Models\Webhook;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use ArtisanPackUI\Bookings\Services\WebhookDispatcher;
use ArtisanPackUI\Bookings\Services\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\TestsWithSqlite;
use Tests\Support\FakeHostResolver;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * Resolves the service under test.
 */
function webhookDispatcher(): WebhookDispatcher
{
    return app( WebhookDispatcher::class );
}

/**
 * Builds a delivery aimed at an endpoint, queued and unattempted.
 */
function queuedWebhookDelivery( Webhook $webhook, array $attributes = [] ): WebhookDelivery
{
    return WebhookDelivery::factory()->for( $webhook )->pending()->create( $attributes );
}

describe( 'fanning an event out', function (): void {
    beforeEach( function (): void {
        Queue::fake();
    } );

    it( 'queues one delivery per subscribed endpoint', function (): void {
        $subscribed = Webhook::factory()->subscribedTo( [ 'booking.confirmed' ] )->create();
        $also       = Webhook::factory()->subscribedTo( [ 'booking.confirmed', 'booking.cancelled' ] )->create();
        Webhook::factory()->subscribedTo( [ 'booking.cancelled' ] )->create();

        $queued = webhookDispatcher()->dispatch( 'booking.confirmed', [ 'id' => 7 ] );

        expect( $queued )->toHaveCount( 2 )
            ->and( array_map( static fn ( WebhookDelivery $d ): int => $d->webhook_id, $queued ) )
            ->toEqualCanonicalizing( [ $subscribed->id, $also->id ] );

        Queue::assertPushed( DispatchWebhookDelivery::class, 2 );
    } );

    it( 'writes the event and payload onto the delivery row', function (): void {
        $webhook = Webhook::factory()->subscribedTo( [ 'booking.confirmed' ] )->create();

        webhookDispatcher()->dispatch( 'booking.confirmed', [ 'id' => 7, 'nested' => [ 'a' => 1 ] ] );

        $delivery = WebhookDelivery::query()->sole();

        expect( $delivery->event_type )->toBe( 'booking.confirmed' )
            ->and( $delivery->payload )->toBe( [ 'id' => 7, 'nested' => [ 'a' => 1 ] ] )
            ->and( $delivery->status )->toBe( WebhookDeliveryStatus::Pending )
            ->and( $delivery->attempt_number )->toBe( 1 )
            ->and( $delivery->next_attempt_at )->toBeNull();
    } );

    it( 'skips endpoints that are disabled or switched off', function (): void {
        Webhook::factory()->subscribedTo( [ 'booking.confirmed' ] )->disabled()->create();
        Webhook::factory()->subscribedTo( [ 'booking.confirmed' ] )->create( [ 'is_active' => false ] );

        expect( webhookDispatcher()->dispatch( 'booking.confirmed', [] ) )->toBe( [] );

        Queue::assertNothingPushed();
    } );
} );

describe( 'signing', function (): void {
    it( 'signs the exact bytes it sends, with the timestamp inside the signature', function (): void {
        Http::fake( [ '*' => Http::response( 'ok', 200 ) ] );

        $webhook  = Webhook::factory()->create( [ 'secret' => 'shhh', 'url' => 'https://example.test/hook' ] );
        $delivery = queuedWebhookDelivery( $webhook, [
            'event_type' => 'booking.confirmed',
            'payload'    => [ 'path' => 'a/b', 'name' => 'Ünïcode' ],
        ] );

        webhookDispatcher()->deliver( $delivery );

        Http::assertSent( function ( Request $request ) use ( $delivery ): bool {
            $timestamp = $request->header( 'X-ArtisanPack-Timestamp' )[0];
            $signature = $request->header( 'X-ArtisanPack-Signature' )[0];

            expect( $request->url() )->toBe( 'https://example.test/hook' )
                ->and( $request->method() )->toBe( 'POST' )
                ->and( $request->header( 'X-ArtisanPack-Event' )[0] )->toBe( 'booking.confirmed' )
                ->and( $request->header( 'X-ArtisanPack-Delivery' )[0] )->toBe( (string) $delivery->id )
                ->and( $request->header( 'X-ArtisanPack-Attempt' )[0] )->toBe( '1' )
                // Unescaped, because the signature is over these bytes and a
                // second encoder that escapes the slash cannot reproduce it.
                ->and( $request->body() )->toBe( '{"path":"a/b","name":"Ünïcode"}' );

            return WebhookDispatcher::signatureMatches( $signature, $request->body(), $timestamp, 'shhh' );
        } );
    } );

    it( 'produces a different signature for a different secret', function (): void {
        $signed = WebhookDispatcher::signature( '{"a":1}', '1700000000', 'one' );

        expect( $signed )->toStartWith( 'sha256=' )
            ->and( $signed )->not->toBe( WebhookDispatcher::signature( '{"a":1}', '1700000000', 'two' ) )
            ->and( WebhookDispatcher::signatureMatches( $signed, '{"a":1}', '1700000000', 'one' ) )->toBeTrue();
    } );

    it( 'rejects a signature replayed under a different timestamp', function (): void {
        // The timestamp is inside the signed string rather than beside it, so a
        // captured request cannot be replayed with a fresh one.
        $signed = WebhookDispatcher::signature( '{"a":1}', '1700000000', 'one' );

        expect( WebhookDispatcher::signatureMatches( $signed, '{"a":1}', '1700009999', 'one' ) )->toBeFalse();
    } );
} );

describe( 'the endpoint URL as trusted input', function (): void {
    it( 'refuses to follow a redirect', function (): void {
        // Asserted against the source rather than the behaviour, because the
        // HTTP fake never redirects and so cannot tell a client that follows
        // from one that does not. What is being guarded is that the address an
        // operator reviewed stays the address that is called: this process can
        // reach hosts a browser cannot, and the reply is written into a ledger
        // an admin screen reads back.
        $source = new ReflectionMethod( WebhookDispatcher::class, 'send' );
        $body   = implode( '', array_slice(
            file( $source->getFileName() ),
            $source->getStartLine() - 1,
            $source->getEndLine() - $source->getStartLine() + 1,
        ) );

        expect( $body )->toContain( 'withoutRedirecting()' );
    } );

    it( 'records a redirect as the failure it is', function (): void {
        Http::fake( [ '*' => Http::response( '', 302, [ 'Location' => 'http://169.254.169.254/' ] ) ] );

        $delivery = queuedWebhookDelivery( Webhook::factory()->create() );

        expect( webhookDispatcher()->deliver( $delivery ) )->toBeFalse()
            ->and( $delivery->refresh()->response_status )->toBe( 302 );
    } );

    it( 'kills a delivery to a URL the guard refuses, without sending', function (): void {
        // The endpoint was stored with a name that resolved to a public address;
        // by delivery time it points at loopback. The guard is re-run here, so
        // the request is never made and the reason is left on the row.
        app()->instance(
            ResolvesHostAddresses::class,
            new FakeHostResolver( [ 'rebound.example.com' => [ '127.0.0.1' ] ] ),
        );
        app()->forgetInstance( WebhookUrlGuard::class );
        app()->forgetInstance( WebhookDispatcher::class );

        Http::fake( [ '*' => Http::response( 'ok', 200 ) ] );

        $webhook  = Webhook::factory()->create( [ 'url' => 'https://rebound.example.com/hook' ] );
        $delivery = queuedWebhookDelivery( $webhook );

        expect( webhookDispatcher()->deliver( $delivery ) )->toBeFalse();

        $delivery->refresh();

        expect( $delivery->status )->toBe( WebhookDeliveryStatus::Dead )
            ->and( $delivery->response_body )->toContain( 'address that is not allowed' );

        // Not counted against the endpoint: a refused URL is not the consumer's
        // fault, and no retry would change the answer.
        expect( $webhook->refresh()->consecutive_failure_count )->toBe( 0 );

        Http::assertNothingSent();
    } );

    it( 'delivers to a resolved hostname, pinning the vetted address', function (): void {
        // The guard resolves the name to a public address and the delivery pins
        // it. The HTTP fake short-circuits before curl, so this proves the
        // pinning path posts to the hostname without erroring rather than
        // asserting the curl option itself.
        app()->instance(
            ResolvesHostAddresses::class,
            new FakeHostResolver( [ 'hooks.example.com' => [ '93.184.216.34' ] ] ),
        );
        app()->forgetInstance( WebhookUrlGuard::class );
        app()->forgetInstance( WebhookDispatcher::class );

        Http::fake( [ '*' => Http::response( 'ok', 200 ) ] );

        $webhook  = Webhook::factory()->create( [ 'url' => 'https://hooks.example.com/hook' ] );
        $delivery = queuedWebhookDelivery( $webhook );

        expect( webhookDispatcher()->deliver( $delivery ) )->toBeTrue();

        Http::assertSent( static fn ( Request $request ): bool => 'https://hooks.example.com/hook' === $request->url() );
    } );

    it( 'delivers normally when the guard is switched off', function (): void {
        config()->set( 'artisanpack.bookings.webhooks.url_guard.enabled', false );

        app()->instance(
            ResolvesHostAddresses::class,
            new FakeHostResolver( [ 'internal.example.com' => [ '10.0.0.5' ] ] ),
        );
        app()->forgetInstance( WebhookUrlGuard::class );
        app()->forgetInstance( WebhookDispatcher::class );

        Http::fake( [ '*' => Http::response( 'ok', 200 ) ] );

        $webhook  = Webhook::factory()->create( [ 'url' => 'https://internal.example.com/hook' ] );
        $delivery = queuedWebhookDelivery( $webhook );

        expect( webhookDispatcher()->deliver( $delivery ) )->toBeTrue();

        Http::assertSent( static fn ( Request $request ): bool => 'https://internal.example.com/hook' === $request->url() );
    } );
} );

describe( 'a successful delivery', function (): void {
    it( 'records the response and clears the failure streak', function (): void {
        Http::fake( [ '*' => Http::response( '{"ok":true}', 202 ) ] );

        $webhook  = Webhook::factory()->failing( 4 )->create();
        $delivery = queuedWebhookDelivery( $webhook );

        expect( webhookDispatcher()->deliver( $delivery ) )->toBeTrue();

        $delivery->refresh();
        $webhook->refresh();

        expect( $delivery->status )->toBe( WebhookDeliveryStatus::Success )
            ->and( $delivery->response_status )->toBe( 202 )
            ->and( $delivery->response_body )->toBe( '{"ok":true}' )
            ->and( $delivery->succeeded_at )->not->toBeNull()
            ->and( $delivery->attempted_at )->not->toBeNull()
            ->and( $delivery->next_attempt_at )->toBeNull()
            ->and( $webhook->consecutive_failure_count )->toBe( 0 )
            ->and( $webhook->last_success_at )->not->toBeNull();
    } );

    it( 'does not send again for a delivery that already succeeded', function (): void {
        Http::fake( [ '*' => Http::response( 'ok', 200 ) ] );

        $delivery = WebhookDelivery::factory()->success()->create();

        expect( webhookDispatcher()->deliver( $delivery ) )->toBeTrue();

        Http::assertNothingSent();
    } );

    it( 'does not send again for a delivery that is dead', function (): void {
        Http::fake( [ '*' => Http::response( 'ok', 200 ) ] );

        expect( webhookDispatcher()->deliver( WebhookDelivery::factory()->dead()->create() ) )->toBeFalse();

        Http::assertNothingSent();
    } );
} );

describe( 'a failed delivery', function (): void {
    it( 'walks the configured backoff schedule and then dies', function (): void {
        Http::fake( [ '*' => Http::response( 'Internal Server Error', 500 ) ] );
        config()->set( 'artisanpack.bookings.webhooks.delivery_backoff_minutes', [ 1, 5, 30, 120, 720 ] );
        config()->set( 'artisanpack.bookings.webhooks.failure_threshold', 0 );

        $delivery = queuedWebhookDelivery( Webhook::factory()->create() );

        foreach ( [ 1, 5, 30, 120, 720 ] as $index => $minutes ) {
            $now = Carbon::now();

            expect( webhookDispatcher()->deliver( $delivery ) )->toBeFalse();

            $delivery->refresh();

            expect( $delivery->status )->toBe( WebhookDeliveryStatus::Failed )
                ->and( $delivery->response_status )->toBe( 500 )
                ->and( $delivery->attempt_number )->toBe( $index + 2 )
                ->and( $delivery->next_attempt_at->getTimestamp() )
                ->toEqualWithDelta( $now->copy()->addMinutes( $minutes )->getTimestamp(), 2 );
        }

        // The sixth attempt has no delay left to take, so it is the last one.
        expect( webhookDispatcher()->deliver( $delivery ) )->toBeFalse();

        $delivery->refresh();

        expect( $delivery->status )->toBe( WebhookDeliveryStatus::Dead )
            ->and( $delivery->attempt_number )->toBe( 6 )
            ->and( $delivery->next_attempt_at )->toBeNull()
            ->and( $delivery->isRetryable() )->toBeFalse();
    } );

    it( 'dies on the first attempt when no backoff is configured', function (): void {
        Http::fake( [ '*' => Http::response( 'nope', 503 ) ] );
        config()->set( 'artisanpack.bookings.webhooks.delivery_backoff_minutes', [] );

        $delivery = queuedWebhookDelivery( Webhook::factory()->create() );

        webhookDispatcher()->deliver( $delivery );

        expect( $delivery->refresh()->status )->toBe( WebhookDeliveryStatus::Dead );
    } );

    it( 'ignores a zero or negative delay rather than retrying immediately', function (): void {
        Http::fake( [ '*' => Http::response( 'nope', 503 ) ] );
        config()->set( 'artisanpack.bookings.webhooks.delivery_backoff_minutes', [ 0, -5, 10 ] );

        expect( WebhookDispatcher::backoffMinutes() )->toBe( [ 10 ] );

        $delivery = queuedWebhookDelivery( Webhook::factory()->create() );

        webhookDispatcher()->deliver( $delivery );

        expect( $delivery->refresh()->next_attempt_at->getTimestamp() )
            ->toEqualWithDelta( Carbon::now()->addMinutes( 10 )->getTimestamp(), 2 );
    } );

    it( 'records a refused connection as a failure with no status', function (): void {
        Http::fake( static function (): void {
            throw new Illuminate\Http\Client\ConnectionException( 'Connection refused' );
        } );

        $delivery = queuedWebhookDelivery( Webhook::factory()->create() );

        expect( webhookDispatcher()->deliver( $delivery ) )->toBeFalse();

        $delivery->refresh();

        expect( $delivery->status )->toBe( WebhookDeliveryStatus::Failed )
            ->and( $delivery->response_status )->toBeNull()
            ->and( $delivery->response_body )->toContain( 'Connection refused' );
    } );

    it( 'stores a binary response body as valid UTF-8', function (): void {
        // An endpoint answers with whatever it likes, and MySQL rejects an
        // invalid byte sequence on a utf8mb4 column outright — from inside the
        // write that records a failure, which is the one place here that must
        // not throw.
        Http::fake( [ '*' => Http::response( "\xC3\x28\xFF\xFEbroken", 500 ) ] );

        $delivery = queuedWebhookDelivery( Webhook::factory()->create() );

        webhookDispatcher()->deliver( $delivery );

        $stored = (string) $delivery->refresh()->response_body;

        expect( mb_check_encoding( $stored, 'UTF-8' ) )->toBeTrue()
            ->and( $stored )->toContain( 'broken' );
    } );

    it( 'truncates a response body far too large for the ledger', function (): void {
        Http::fake( [ '*' => Http::response( str_repeat( 'x', 60000 ), 500 ) ] );

        $delivery = queuedWebhookDelivery( Webhook::factory()->create() );

        webhookDispatcher()->deliver( $delivery );

        expect( mb_strlen( (string) $delivery->refresh()->response_body ) )->toBeLessThanOrEqual( 2000 );
    } );
} );

describe( 'auto-disable', function (): void {
    beforeEach( function (): void {
        Http::fake( [ '*' => Http::response( 'Internal Server Error', 500 ) ] );
    } );

    it( 'counts each failure against the endpoint', function (): void {
        $webhook = Webhook::factory()->create();

        webhookDispatcher()->deliver( queuedWebhookDelivery( $webhook ) );
        webhookDispatcher()->deliver( queuedWebhookDelivery( $webhook ) );

        expect( $webhook->refresh()->consecutive_failure_count )->toBe( 2 )
            ->and( $webhook->isDisabled() )->toBeFalse();
    } );

    it( 'disables the endpoint and fires the event at the threshold', function (): void {
        Event::fake( [ WebhookDisabled::class ] );
        config()->set( 'artisanpack.bookings.webhooks.failure_threshold', 3 );

        $webhook = Webhook::factory()->failing( 2 )->create();

        webhookDispatcher()->deliver( queuedWebhookDelivery( $webhook ) );

        $webhook->refresh();

        expect( $webhook->isDisabled() )->toBeTrue()
            ->and( $webhook->is_active )->toBeFalse()
            ->and( $webhook->consecutive_failure_count )->toBe( 3 );

        Event::assertDispatched(
            WebhookDisabled::class,
            static fn ( WebhookDisabled $event ): bool => $event->webhook->is( $webhook )
                && str_contains( $event->reason, '3 consecutive' ),
        );
    } );

    it( 'does not disable an endpoint that is still under the threshold', function (): void {
        Event::fake( [ WebhookDisabled::class ] );
        config()->set( 'artisanpack.bookings.webhooks.failure_threshold', 10 );

        $webhook = Webhook::factory()->failing( 2 )->create();

        webhookDispatcher()->deliver( queuedWebhookDelivery( $webhook ) );

        expect( $webhook->refresh()->isDisabled() )->toBeFalse();

        Event::assertNotDispatched( WebhookDisabled::class );
    } );

    it( 'kills a delivery aimed at an endpoint disabled while it was queued', function (): void {
        $webhook  = Webhook::factory()->create();
        $delivery = queuedWebhookDelivery( $webhook );

        $webhook->disable( 'An operator switched it off.' );

        expect( webhookDispatcher()->deliver( $delivery ) )->toBeFalse();

        $delivery->refresh();

        expect( $delivery->status )->toBe( WebhookDeliveryStatus::Dead )
            ->and( $delivery->response_body )->toContain( 'no longer active' );

        // Not counted against the endpoint: it was switched off, not broken.
        expect( $webhook->refresh()->consecutive_failure_count )->toBe( 0 );

        Http::assertNothingSent();
    } );

} );
