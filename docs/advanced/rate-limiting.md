---
title: Rate Limiting
---

# Rate Limiting

Every public route is reachable without credentials, so each one carries a bucket. The limits are named rather than numeric — `bookings.rate-limit:post` rather than `throttle:5,1` — and live under `config( 'artisanpack.bookings.public.rate_limits' )`, so an installation raises them for its own traffic in one place instead of at each route.

## The buckets

| Bucket | Default (per minute) | Keyed by | Guards |
| --- | --- | --- | --- |
| `post` | 5 | address | `POST api/bookings`, the widget, and the self-serve cancel / reschedule |
| `read` | 60 | address | `GET services`, `GET services/{slug}/providers`, `GET services/{slug}/slots` |
| `manage_get` | 20 | address | The manage page read |
| `manage_token` | 60 | manage token | The manage read, the self-serve cancel / reschedule, and the customer feed |
| `ical` | 30 | address | The provider and customer calendar feeds |
| `ical_token` | 30 | feed token | The provider calendar feed |

The public service, provider, and slot reads carry the `read` bucket because slot resolution is the most expensive read in the package — without it a script could walk a provider's whole calendar unbounded.

The routes that carry a link's whole credential are guarded twice — once per address, once per token — because the two bound different abuses: a machine grinding through guesses, and a link that has escaped into the world being hit from everywhere at once. The self-serve cancel and reschedule writes carry both the `post` (per address) and `manage_token` (per link) buckets, matching the manage read's stack.

## Raising a limit

```php
// config/artisanpack/bookings.php
'public' => [
    'rate_limits' => [
        'post'         => 20,
        'read'         => 120,
        'manage_get'   => 60,
        'manage_token' => 120,
        'ical'         => 60,
        'ical_token'   => 60,
    ],
],
```

## Applying a bucket to your own route

The `bookings.rate-limit` middleware takes a bucket name, so the self-serve manage page you mount uses the same limiters the endpoint it fronts does:

```php
Route::get( '/bookings/manage/{token}', /* … */ )
    ->middleware( [
        'bookings.rate-limit:manage_get',
        'bookings.rate-limit:manage_token',
        'bookings.manage-token',
    ] );
```

Declare the token limiter last so a guess is counted before it costs a lookup. See [Self-Serve Management Page](Usage-Self-Serve-Page).

## Behind a proxy

Every address-keyed bucket is only as truthful as `Request::ip()`. Behind a load balancer or CDN, that address is the proxy's unless you configure Laravel's trusted proxies — and then every customer shares one bucket. **This is not optional for a public installation.** See [Trusted Proxies](Advanced-Trusted-Proxies).
