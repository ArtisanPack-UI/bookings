---
title: Webhook Security (SSRF)
---

# Webhook Security (SSRF)

The package posts wherever `booking_webhooks.url` says, from inside your application, and stores the first 2,000 characters of the reply where an admin screen can read it. That is a request-forgery primitive if the URL is not trusted: an internal service or a cloud metadata endpoint is reachable from your server and not from a browser, and the response comes back out through the delivery ledger. So the outbound URL is guarded.

## The guard

The address is checked by a guard, `webhooks.url_guard`, on by default. It:

- allows only the schemes on `allowed_schemes` (`https` alone unless you add `http`), and
- refuses any URL whose host resolves to a loopback, private, link-local, or unique-local address — **every** address a name answers with, since a name offering one public and one private address is still a way into the private one.

Redirects are refused, so the address that was reviewed stays the address that is called.

## Delivery-time checking & DNS-rebinding

The check runs **at delivery, not only when the endpoint is saved**, because a name approved once can be repointed afterwards; a refused URL kills the delivery the way a disabled endpoint does, with the reason on the row.

The host is resolved once and the vetted address is pinned into the connection, so the HTTP client cannot resolve the name a second time and reach an address the guard never checked — the DNS-rebinding case. The request is made under the host the address was pinned to (lower-cased, no trailing dot, IDN names in punycode) so the client resolves the exact string the pin was keyed on. TLS still verifies against the hostname.

Pinning is enforced by `CURLOPT_RESOLVE`, which needs the curl HTTP handler (`ext-curl`) and libcurl 7.59 or newer for its multi-address form. An installation without curl, or on older libcurl, still gets the delivery-time address check — it just cannot pin the connection to it, so a determined DNS-rebinding attacker regains the narrow resolve-then-connect window there.

## Tuning the guard

```php
// config/artisanpack/bookings.php
'webhooks' => [
    'url_guard' => [
        'enabled'         => true,
        'allowed_schemes' => [ 'https' ],   // add 'http' for local dev
        'allowed_hosts'   => [],            // skip the range check for these
        'blocked_hosts'   => [],            // refuse these whatever they resolve to
    ],
],
```

- `allowed_hosts` — name an internal host you deliver to on purpose; it skips the range check.
- `blocked_hosts` — refuse a host whatever it resolves to.
- `enabled => false` — switch the guard off for an installation where every endpoint is operator-created.

## Accepting URLs below operator trust

If you accept an endpoint URL below operator trust — a tenant self-service screen — apply the `ValidWebhookUrl` rule to the field so a bad address is refused before it is stored:

```php
use ArtisanPackUI\Bookings\Rules\ValidWebhookUrl;

$request->validate( [
    'url' => [ 'required', 'url', new ValidWebhookUrl() ],
] );
```

## Related

- [Outbound Webhooks](Notifications-Webhooks) — subscribing, signing, retries, and disabling
