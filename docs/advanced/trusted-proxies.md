---
title: Trusted Proxies
---

# Trusted Proxies

The address-keyed [rate-limit buckets](Advanced-Rate-Limiting) are only as truthful as `Request::ip()`. Behind a load balancer, a CDN, or any reverse proxy, an application that has not told Laravel which proxies to trust sees every request as coming from the proxy — so `Request::ip()` returns the proxy's own address (often `127.0.0.1`), every customer in the world shares the one `post` bucket, and the fifth booking of the minute is refused for all of them.

**Configure Laravel's trusted proxies before putting these routes in front of real traffic.**

## Laravel 11, 12, and 13

Done in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware( function ( Middleware $middleware ): void {
    $middleware->trustProxies( at: [
        '192.0.2.10', // the load balancer or CDN address requests actually arrive from
    ] );
} )
```

## Trust only what you operate

Trust only the proxies you operate. `at: '*'` trusts whatever sets `X-Forwarded-For`, which hands every caller the ability to name their own address and slip the per-address buckets — set it only when something you control terminates every request before this application reaches it.

## The package does not do this for you

Bookings ships no trusted-proxy configuration and cannot: only you know your infrastructure. The package documents the requirement and keys its buckets on `Request::ip()`; making that IP truthful is the application's job.
