<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Contracts\ResolvesHostAddresses;
use ArtisanPackUI\Bookings\Services\WebhookUrlGuard;
use Tests\Concerns\TestsWithSqlite;
use Tests\Support\FakeHostResolver;

uses( TestsWithSqlite::class );

/**
 * Resolves the guard, binding a resolver that answers from a fixed map.
 *
 * @param  array<string, array<int, string>>  $map  Host-to-addresses overrides.
 */
function webhookUrlGuard( array $map = [], array $default = [ '93.184.216.34' ] ): WebhookUrlGuard
{
    app()->instance( ResolvesHostAddresses::class, new FakeHostResolver( $map, $default ) );
    app()->forgetInstance( WebhookUrlGuard::class );

    return app( WebhookUrlGuard::class );
}

describe( 'the scheme allowlist', function (): void {
    it( 'allows an https URL that resolves to a public address', function (): void {
        expect( webhookUrlGuard()->inspect( 'https://hooks.example.com/bookings' ) )->toBeNull();
    } );

    it( 'refuses http by default', function (): void {
        expect( webhookUrlGuard()->inspect( 'http://hooks.example.com/bookings' ) )
            ->toContain( 'scheme is not allowed' );
    } );

    it( 'allows http once it is added to the allowlist', function (): void {
        config()->set( 'artisanpack.bookings.webhooks.url_guard.allowed_schemes', [ 'https', 'http' ] );

        expect( webhookUrlGuard()->inspect( 'http://hooks.example.com/bookings' ) )->toBeNull();
    } );

    it( 'refuses a URL with no scheme or host', function (): void {
        expect( webhookUrlGuard()->inspect( 'not a url' ) )->toContain( 'not a valid absolute URL' )
            ->and( webhookUrlGuard()->inspect( 'https:///nohost' ) )->toContain( 'not a valid absolute URL' );
    } );
} );

describe( 'the resolved-address check', function (): void {
    it( 'refuses a loopback IP literal', function (): void {
        expect( webhookUrlGuard()->inspect( 'https://127.0.0.1/hook' ) )
            ->toContain( 'address that is not allowed' );
    } );

    it( 'refuses the cloud metadata endpoint', function (): void {
        expect( webhookUrlGuard()->inspect( 'https://169.254.169.254/latest/meta-data/' ) )
            ->toContain( 'address that is not allowed' );
    } );

    it( 'refuses a URL whose host resolves into a private range', function (): void {
        // The literal is public; the name it wears is not. This is the shape of
        // the rebinding case — a benign-looking host answering with a private
        // address — and the guard follows the resolution rather than the label.
        $guard = webhookUrlGuard( [ 'sneaky.example.com' => [ '10.1.2.3' ] ] );

        expect( $guard->inspect( 'https://sneaky.example.com/hook' ) )
            ->toContain( 'address that is not allowed' );
    } );

    it( 'refuses a name that answers with one public and one private address', function (): void {
        $guard = webhookUrlGuard( [ 'mixed.example.com' => [ '93.184.216.34', '192.168.1.10' ] ] );

        expect( $guard->inspect( 'https://mixed.example.com/hook' ) )
            ->toContain( 'address that is not allowed' );
    } );

    it( 'refuses an IPv6 loopback literal', function (): void {
        expect( webhookUrlGuard()->inspect( 'https://[::1]/hook' ) )
            ->toContain( 'address that is not allowed' );
    } );

    it( 'refuses an IPv6 unique-local literal', function (): void {
        expect( webhookUrlGuard()->inspect( 'https://[fd00::1]/hook' ) )
            ->toContain( 'address that is not allowed' );
    } );

    it( 'refuses an IPv4-mapped IPv6 loopback', function (): void {
        expect( webhookUrlGuard()->inspect( 'https://[::ffff:127.0.0.1]/hook' ) )
            ->toContain( 'address that is not allowed' );
    } );

    it( 'refuses a deprecated IPv4-compatible embedding', function (): void {
        // ::7f00:1 is the IPv4-compatible form of 127.0.0.1. A modern stack does
        // not route it to loopback, but the guard refuses it rather than trust
        // that — it falls in ::/96.
        expect( webhookUrlGuard()->inspect( 'https://[::7f00:1]/hook' ) )
            ->toContain( 'address that is not allowed' );
    } );

    it( 'refuses a host that does not resolve', function (): void {
        $guard = webhookUrlGuard( default: [] );

        expect( $guard->inspect( 'https://nowhere.example.com/hook' ) )
            ->toContain( 'could not be resolved' );
    } );

    it( 'allows a public IPv6 literal', function (): void {
        expect( webhookUrlGuard()->inspect( 'https://[2606:2800:220:1:248:1893:25c8:1946]/hook' ) )
            ->toBeNull();
    } );
} );

describe( 'operator overrides', function (): void {
    it( 'allows a blocked address for a host on the allow list', function (): void {
        config()->set( 'artisanpack.bookings.webhooks.url_guard.allowed_schemes', [ 'https', 'http' ] );
        config()->set( 'artisanpack.bookings.webhooks.url_guard.allowed_hosts', [ 'internal-crm.local' ] );

        $guard = webhookUrlGuard( [ 'internal-crm.local' => [ '10.0.0.5' ] ] );

        expect( $guard->inspect( 'http://internal-crm.local/hooks' ) )->toBeNull();
    } );

    it( 'refuses a host on the block list whatever it resolves to', function (): void {
        config()->set( 'artisanpack.bookings.webhooks.url_guard.blocked_hosts', [ 'evil.example.com' ] );

        $guard = webhookUrlGuard( [ 'evil.example.com' => [ '93.184.216.34' ] ] );

        expect( $guard->inspect( 'https://evil.example.com/hook' ) )->toContain( 'host is blocked' );
    } );

    it( 'matches host lists case-insensitively', function (): void {
        config()->set( 'artisanpack.bookings.webhooks.url_guard.blocked_hosts', [ 'Evil.Example.com' ] );

        $guard = webhookUrlGuard();

        expect( $guard->inspect( 'https://evil.example.com/hook' ) )->toContain( 'host is blocked' );
    } );

    it( 'matches a block-list host through a trailing dot', function (): void {
        // example.com. and example.com are the same host to a resolver, so the
        // dotted form must not be a way around the block.
        config()->set( 'artisanpack.bookings.webhooks.url_guard.blocked_hosts', [ 'evil.example.com' ] );

        $guard = webhookUrlGuard();

        expect( $guard->inspect( 'https://evil.example.com./hook' ) )->toContain( 'host is blocked' );
    } );

    it( 'matches an allow-list host through a trailing dot', function (): void {
        config()->set( 'artisanpack.bookings.webhooks.url_guard.allowed_hosts', [ 'internal.example.com' ] );

        $guard = webhookUrlGuard( [ 'internal.example.com' => [ '10.0.0.5' ] ] );

        expect( $guard->inspect( 'https://internal.example.com./hook' ) )->toBeNull();
    } );

    it( 'allows anything when the guard is switched off', function (): void {
        config()->set( 'artisanpack.bookings.webhooks.url_guard.enabled', false );

        expect( webhookUrlGuard()->inspect( 'http://127.0.0.1/hook' ) )->toBeNull();
    } );
} );

describe( 'the allows() shorthand', function (): void {
    it( 'is the boolean of inspect()', function (): void {
        $guard = webhookUrlGuard();

        expect( $guard->allows( 'https://hooks.example.com/hook' ) )->toBeTrue()
            ->and( $guard->allows( 'https://127.0.0.1/hook' ) )->toBeFalse();
    } );
} );

describe( 'decide() and address pinning', function (): void {
    it( 'pins the vetted addresses of a resolved host', function (): void {
        $guard    = webhookUrlGuard( [ 'hooks.example.com' => [ '93.184.216.34', '8.8.8.8' ] ] );
        $decision = $guard->decide( 'https://hooks.example.com:8443/hook' );

        expect( $decision->allowed )->toBeTrue()
            ->and( $decision->pinnable )->toBeTrue()
            ->and( $decision->host )->toBe( 'hooks.example.com' )
            ->and( $decision->port )->toBe( 8443 )
            ->and( $decision->addresses )->toBe( [ '93.184.216.34', '8.8.8.8' ] )
            ->and( $decision->requestUrl )->toBe( 'https://hooks.example.com:8443/hook' );
    } );

    it( 'canonicalises a trailing-dot host so the pin matches the request', function (): void {
        // The dot is stripped from the pinned host and from the URL the client is
        // handed, so the client resolves the exact string the pin was keyed on
        // rather than a dotted variant the resolve entry would miss.
        $guard    = webhookUrlGuard( [ 'hooks.example.com' => [ '8.8.8.8' ] ] );
        $decision = $guard->decide( 'https://hooks.example.com./hook?x=1' );

        expect( $decision->pinnable )->toBeTrue()
            ->and( $decision->host )->toBe( 'hooks.example.com' )
            ->and( $decision->requestUrl )->toBe( 'https://hooks.example.com/hook?x=1' );
    } );

    it( 'pins and requests an IDN host in its punycode form', function (): void {
        $guard    = webhookUrlGuard( [ 'xn--bcher-kva.example' => [ '8.8.8.8' ] ] );
        $decision = $guard->decide( 'https://bücher.example/hook' );

        expect( $decision->pinnable )->toBeTrue()
            ->and( $decision->host )->toBe( 'xn--bcher-kva.example' )
            ->and( $decision->addresses )->toBe( [ '8.8.8.8' ] )
            ->and( $decision->requestUrl )->toBe( 'https://xn--bcher-kva.example/hook' );
    } )->skip( ! function_exists( 'idn_to_ascii' ), 'ext-intl is not installed.' );

    it( 'preserves userinfo and port when rewriting the host', function (): void {
        $guard    = webhookUrlGuard( [ 'hooks.example.com' => [ '8.8.8.8' ] ] );
        $decision = $guard->decide( 'https://user:pass@hooks.example.com.:9000/a/b?q=1' );

        expect( $decision->requestUrl )->toBe( 'https://user:pass@hooks.example.com:9000/a/b?q=1' );
    } );

    it( 'defaults the pinned port from the scheme', function (): void {
        $guard = webhookUrlGuard( [ 'hooks.example.com' => [ '8.8.8.8' ] ] );

        expect( $guard->decide( 'https://hooks.example.com/hook' )->port )->toBe( 443 );

        config()->set( 'artisanpack.bookings.webhooks.url_guard.allowed_schemes', [ 'https', 'http' ] );
        $guard = webhookUrlGuard( [ 'hooks.example.com' => [ '8.8.8.8' ] ] );

        expect( $guard->decide( 'http://hooks.example.com/hook' )->port )->toBe( 80 );
    } );

    it( 'does not pin an IP-literal host', function (): void {
        $decision = webhookUrlGuard()->decide( 'https://8.8.8.8/hook' );

        expect( $decision->allowed )->toBeTrue()
            ->and( $decision->pinnable )->toBeFalse()
            ->and( $decision->addresses )->toBe( [] );
    } );

    it( 'does not pin an allow-listed host', function (): void {
        config()->set( 'artisanpack.bookings.webhooks.url_guard.allowed_hosts', [ 'internal.example.com' ] );

        $decision = webhookUrlGuard( [ 'internal.example.com' => [ '10.0.0.5' ] ] )
            ->decide( 'https://internal.example.com/hook' );

        expect( $decision->allowed )->toBeTrue()
            ->and( $decision->pinnable )->toBeFalse();
    } );

    it( 'does not pin when the guard is switched off', function (): void {
        config()->set( 'artisanpack.bookings.webhooks.url_guard.enabled', false );

        $decision = webhookUrlGuard()->decide( 'http://127.0.0.1/hook' );

        expect( $decision->allowed )->toBeTrue()
            ->and( $decision->pinnable )->toBeFalse();
    } );

    it( 'carries the refusal reason on a refused decision', function (): void {
        $decision = webhookUrlGuard()->decide( 'https://127.0.0.1/hook' );

        expect( $decision->allowed )->toBeFalse()
            ->and( $decision->reason )->toContain( 'address that is not allowed' )
            ->and( $decision->pinnable )->toBeFalse();
    } );
} );
