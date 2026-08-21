---
title: Outbound Webhooks
---

# Outbound Webhooks

Subscribe an endpoint and the booking lifecycle fans out to it on its own — no wiring, no listener of your own:

```php
use ArtisanPackUI\Bookings\Models\Webhook;

Webhook::create( [
    'name'   => 'Zapier',
    'url'    => 'https://hooks.zapier.test/bookings',
    'secret' => Str::random( 40 ),
    'events' => [ 'booking.confirmed', 'booking.cancelled' ],
] );
```

## The events

Seven events are raised, one per lifecycle transition:

| Event | Raised when |
| --- | --- |
| `booking.created` | A booking is made, whether or not it is yet confirmed |
| `booking.confirmed` | It becomes an appointment |
| `booking.rescheduled` | It moves; carries `data.previous_period` |
| `booking.reassigned` | It moves to another provider; carries `data.previous_provider_id` |
| `booking.cancelled` | It is called off; carries `data.reason` |
| `booking.completed` | It is marked as having happened |
| `booking.no_show` | The customer did not arrive |

A booking that is auto-confirmed raises `booking.created` and `booking.confirmed` in that order.

## The envelope

Each delivery is queued as its own job and recorded in `booking_webhook_deliveries`. The body is an envelope around the booking:

```json
{
  "event": "booking.confirmed",
  "occurred_at": "2026-08-10T20:41:56+00:00",
  "data": {
    "booking": {
      "id": 4711,
      "booking_number": "BK-8F2A1C",
      "status": "confirmed",
      "start_time": "2026-08-14T13:00:00+00:00",
      "end_time": "2026-08-14T13:30:00+00:00",
      "customer": { "name": "Dana Scully", "email": "dana@example.test", "timezone": "Pacific/Auckland" },
      "service": { "id": 3, "name": "Consultation", "slug": "consultation" },
      "provider": { "id": 8, "name": "Alex Kim", "slug": "alex-kim" }
    },
    "actor": "customer"
  }
}
```

Times are UTC, with the customer's own zone named beside them rather than applied to them.

## Signing & verification

Each request carries the event, the delivery id, the attempt number, a timestamp, and a signature:

```
X-ArtisanPack-Event: booking.confirmed
X-ArtisanPack-Delivery: 4711
X-ArtisanPack-Attempt: 1
X-ArtisanPack-Timestamp: 1767024000
X-ArtisanPack-Signature: sha256=<hex>
```

The signature is `HMAC-SHA256( "{timestamp}.{body}", secret )` over the exact bytes of the request body. Verify it in constant time, and reject a timestamp older than your own tolerance — that is what stops a captured request from being replayed:

```php
$signed = $request->header( 'X-ArtisanPack-Timestamp' ) . '.' . $request->getContent();

if ( ! hash_equals( 'sha256=' . hash_hmac( 'sha256', $signed, $secret ), $request->header( 'X-ArtisanPack-Signature' ) ) ) {
    abort( 401 );
}
```

## Retries & disabling

A delivery is accepted on any 2xx. Anything else — a 500, a refused connection, a timeout — is a failure, and the delivery is retried on `webhooks.delivery_backoff_minutes`, which defaults to 1, 5, 30, 120, and 720 minutes. That is the list of delays *between* attempts, so an endpoint gets six attempts over about fourteen hours before the delivery is marked `dead`.

Failures are counted on the endpoint rather than on the delivery, so an endpoint returning 500 to everything is disabled after `webhooks.failure_threshold` consecutive failures — and a single success resets the count. Disabling fires the `WebhookDisabled` event, which is where an application tells the consumer their integration has stopped; nothing else will.

Deliveries are pushed onto the default queue unless `webhooks.queue` names one. Give them their own queue on an installation where a slow consumer must not delay everything else that is queued.

## Raising your own events

Raise your own events through the same machinery when you have something to say that the lifecycle does not cover:

```php
use ArtisanPackUI\Bookings\Services\WebhookDispatcher;

app( WebhookDispatcher::class )->dispatch( 'booking.confirmed', $payload, $siteId );
```

Pass the site when you know it. Left out, the endpoint list is scoped to whatever site is in context — which in console is none of them, and therefore all of them.

## Security

**The endpoint URL is guarded against SSRF.** The address is checked against loopback, private, link-local, and unique-local ranges at delivery time, the vetted address is pinned into the connection to close the DNS-rebinding window, and redirects are refused. Apply the `ValidWebhookUrl` rule when you accept an endpoint URL below operator trust. This is covered in full under [Webhook Security](Advanced-Webhook-Security).

## Retention

Settled deliveries are pruned by `bookings:prune-webhook-deliveries` on `webhooks.delivery_retention_days` (or `retention.webhook_delivery_days` when set). A `pending` delivery is never pruned. See [GDPR, Retention & Erasure](Advanced-Gdpr-Data-Retention).
