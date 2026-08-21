---
title: Manage Tokens
---

# Manage Tokens

A customer with no account manages their booking through a link, and the token in that link is their whole credential. `Services\ManageTokenService` is the one place one is minted, hashed, and checked:

```php
use ArtisanPackUI\Bookings\Services\ManageTokenService;

$tokens = app( ManageTokenService::class );

// Minted automatically when a booking is created — take it once, for the email.
$token = $booking->pullPlainManageToken();

$booking = $tokens->findBooking( $requestToken );   // null when the token is unknown
$tokens->verifyFor( $booking, $requestToken );      // hash_equals, never ==

$fresh = $tokens->issueFor( $booking );             // the old link stops working here
```

## How the token is built

The token is 32 bytes of CSPRNG output rendered as 64 hex characters, and nothing about it is derived from the booking — a token that encoded the reference or the customer's email would let somebody who knows one booking enumerate the rest. `bookings.manage_token_hash` stores `sha256(token)` and nothing else, so a leaked row hands over a hash that cannot be turned back into a working link.

There is deliberately no way to recover a plain token from a saved booking: it is returned once, to whoever minted it. A customer who loses the link gets a new one issued.

## The manage endpoints

Three endpoints are mounted behind that token, and the `bookings.manage-token` middleware in front of them is the whole authentication layer:

```text
GET  api/bookings/manage/{token}
POST api/bookings/manage/{token}/cancel        { "reason": "optional" }
POST api/bookings/manage/{token}/reschedule    { "start_time": "2026-06-01T19:00:00+00:00" }
```

An unknown token, a malformed one, and a token belonging to another site all answer with the same 404 and the same message — anything more specific tells a guesser which guesses were closer. Reads are limited per address *and* per token (`public.rate_limits.manage_get` and `manage_token`); writes share the `post` bucket.

Both writes go through `BookingService`, so `ap.bookings.cancelled` and `ap.bookings.rescheduled` fire with `actor: customer`, and the notifications, webhooks, and calendar sync hanging off them behave as they do everywhere else. `cancellation.allowed` and `cancellation.min_advance_minutes` govern what the link may still do; the read reports that as `meta`:

```json
{
    "data": { "id": 41, "status": "confirmed", "start_time": "2026-06-01T15:00:00+00:00" },
    "meta": { "can_cancel": true, "can_reschedule": true, "changes_allowed_until": "2026-05-31T15:00:00+00:00" }
}
```

A reschedule is checked against availability as it stands now rather than against the slot list the page was drawn from, and answers 409 when somebody else took the slot first.

## Rotating a leaked token

When a token has leaked — a forwarded confirmation, a mail archive, a referrer header — rotate every one of them:

```bash
php artisan bookings:reissue-detached-manage-tokens
```

It is deliberately blunt: every token in every site is replaced, so every manage link the package has ever sent stops working, and a customer has no way back in until somebody sends them a new one. `ap.bookings.manageTokenReissued` fires per booking with the new plain token — the only moment it can be read — so an application with mail wired up can re-send as the rotation runs. Pass `--force` to skip the confirmation prompt and `--chunk` to tune the query size.

## Related

- [Self-Serve Management Page](Usage-Self-Serve-Page) — the customer-facing component
- [iCal Feeds](Usage-Ical-Feeds) — the customer feed uses the same token
- [Artisan Commands](Advanced-Artisan-Commands)
