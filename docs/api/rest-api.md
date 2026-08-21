---
title: REST API
---

# REST API

The public JSON API is the headless surface — a widget, a mobile app, or a bespoke frontend books against it, and it deliberately does without a session or CSRF token. Endpoints sit under `api/` + `public.route_prefix` (default `api/bookings`) and are named `artisanpack.bookings.api.*`.

## Public read endpoints

| Method | Path | Name | Returns |
| --- | --- | --- | --- |
| GET | `services` | `…api.services.index` | Active services |
| GET | `services/{slug}/providers` | `…api.services.providers` | Providers offering a service |
| GET | `services/{slug}/slots` | `…api.services.slots` | Bookable slots for a service |

## Create a booking

| Method | Path | Name | Rate limit |
| --- | --- | --- | --- |
| POST | `/` (i.e. `api/bookings`) | `…api.bookings.store` | `bookings.rate-limit:post` |

```json
POST /api/bookings
{
    "service": "discovery-call",
    "start_time": "2026-06-01T15:00:00+00:00",
    "customer_name": "Sam Rivera",
    "customer_email": "sam@example.test",
    "customer_timezone": "America/Chicago",
    "intake_data": { "goal": "Learn to juggle" }
}
```

## Manage a booking by token

Mounted under `manage/{token}` and guarded by the `bookings.manage-token` middleware:

| Method | Path | Name | Rate limit |
| --- | --- | --- | --- |
| GET | `manage/{token}` | `…api.manage.show` | `manage_get` + `manage_token` |
| POST | `manage/{token}/cancel` | `…api.manage.cancel` | `post` |
| POST | `manage/{token}/reschedule` | `…api.manage.reschedule` | `post` |

The read returns the booking plus a `meta` block describing what the link may still do:

```json
{
    "data": { "id": 41, "status": "confirmed", "start_time": "2026-06-01T15:00:00+00:00" },
    "meta": { "can_cancel": true, "can_reschedule": true, "changes_allowed_until": "2026-05-31T15:00:00+00:00" }
}
```

A reschedule answers `409` when the slot was taken since the page was drawn. An unknown, malformed, or wrong-site token all answer with the same `404` and message. See [Manage Tokens](Usage-Manage-Tokens).

## iCal feeds

Under `public.route_prefix` without the `api/` prefix (default `bookings/ical`), named `artisanpack.bookings.ical.*`:

| Method | Path | Name |
| --- | --- | --- |
| GET | `ical/providers/{token}.ics` | `…ical.provider` |
| GET | `ical/customers/{token}.ics` | `…ical.customer` |

Both support conditional requests (`ETag` / `If-None-Match` → `304`). See [iCal Feeds](Usage-Ical-Feeds).

## The no-JS widget target

The Livewire widget's plain-form fallback posts to a `web`-group route (default `bookings/widget`), named `artisanpack.bookings.widget.store`, rate-limited on the `post` bucket. This is not part of the JSON API — it needs the session and CSRF the API does without. See [Public Booking Widget](Usage-Booking-Widget).

## Rate limiting & trusted proxies

Every public route carries a named rate-limit bucket, most keyed by client IP. Behind a proxy you **must** configure Laravel's trusted proxies or every customer shares one bucket. See [Rate Limiting](Advanced-Rate-Limiting) and [Trusted Proxies](Advanced-Trusted-Proxies).

## The typed client

`@artisanpack-ui/bookings-js` ships a typed `BookingsClient` over this surface — see [Headless Client](Frontend-Headless-Client).
