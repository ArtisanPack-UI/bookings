---
title: iCal Feeds
---

# iCal Feeds

Two subscribable calendars, so a provider can watch their diary from Apple Calendar, Google Calendar, or Outlook without anybody connecting an account:

```text
GET bookings/ical/providers/{token}.ics     # a provider's diary
GET bookings/ical/customers/{token}.ics     # the booking a manage token stands for
```

Both sit under `public.route_prefix` without the `api/` the JSON endpoints carry: nothing calls them from a widget, so the address is one somebody has to be able to paste into a subscription box. The `.ics` is part of the path rather than a query string, because clients guess how to treat a subscription URL from its extension.

## Built to be polled

Google refetches a subscribed feed roughly hourly, Apple about every fifteen minutes, forever, once per subscriber — so every request computes an entity tag from one aggregate and answers `304 Not Modified` before a single booking is read:

```text
ETag: "7f3c…"
Cache-Control: private, max-age=300
```

Send it back as `If-None-Match` and the answer is an empty 304. Weak tags and multi-tag lists are handled, so a proxy in the way does not turn every poll into a full fetch. The tag folds in the newest `updated_at` in the window, how many bookings are in it, and how many of those are still published — the counts are what make a cancellation move the tag.

A feed carries the recent past and the booked future rather than the archive. `public.ical.past_days` (30) and `future_days` (365) set the window, and `max_age` (300) says how long a client may hold the answer.

## The provider feed token

A provider feed is addressed by a token issued to that provider and to nobody else. It has to be: the feed carries the customer's name and email, and the only other thing that could address it is the provider's slug — which `GET api/bookings/services/{slug}/providers` publishes.

Nothing mints a token automatically. A provider has no feed until somebody asks for one, and a provider without one 404s:

```bash
php artisan bookings:ical-token ada-lovelace     # by slug, or by id
php artisan bookings:ical-token ada-lovelace --revoke
```

The URL it prints is shown once and cannot be recovered. Only `sha256(token)` is stored, in `service_providers.ical_token_hash` — so a leaked backup or a read-only replica hands over something that cannot be turned back into a working subscription.

One URL, any number of devices: the same address can be pasted into a phone, a laptop, and a desktop client, and they all keep working. What cannot be done is *look it up again*.

## Rotation is not free

Running the command again for a provider who already has a feed replaces the token, and every calendar client subscribed to the old URL stops updating at that moment. It does so silently, because a subscribed feed that starts 404ing does not announce itself — so after a rotation every one of that provider's clients has to be given the new URL. The command warns and asks before it does this; `--force` skips the prompt.

`ap.bookings.icalTokenIssued` fires with the new plain token — the only moment it is readable — so an application with mail wired up can deliver the subscription URL itself:

```php
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\IcalTokenService;

addAction( 'ap.bookings.icalTokenIssued', function ( ServiceProvider $provider, string $token ) {
    Mail::to( $provider->email )->send( new CalendarFeedIssued(
        app( IcalTokenService::class )->feedUrl( $token ),
    ) );
} );
```

Feed lookups go through `Services\IcalTokenService`, which shares its primitives with `ManageTokenService` — 32 CSPRNG bytes as 64 hex characters, `sha256` in the column, `hash_equals()` on the way back. Unknown tokens, revoked feeds, rotated tokens, deactivated providers, and tokens belonging to another site all answer with the same 404 and the same message.

## The customer feed

The customer feed is the manage token's, guarded by the same `bookings.manage-token` middleware as the manage endpoints, and carries the one booking that token stands for. It is limited per address *and* per token, for the reason the manage read is: a feed URL sits in a calendar client's settings for years, which is exactly how a link escapes. The provider feed is limited the same way (`public.rate_limits.ical` and `ical_token`).

Rescheduling moves an event a subscriber already has rather than leaving a duplicate behind: the `UID` is built from `booking_number`, which never changes. Cancelling removes it.

## Related

- [Manage Tokens](Usage-Manage-Tokens)
- [Calendar Sync](Integrations-Calendar-Sync) — two-way connections via Google / Microsoft / Apple
- [Artisan Commands](Advanced-Artisan-Commands)
