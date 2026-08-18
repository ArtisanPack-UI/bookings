# ArtisanPack UI Bookings

Appointment scheduling and booking management for Laravel — services, providers,
availability, bookings, calendar sync, and a public booking widget.

> **Status: pre-release.** This is the package foundation. The service provider,
> configuration, database schema, and code-style/test toolchain are in place;
> the domain layer, HTTP surface, and frontend land in the `v1.0.0-alpha.*`
> series.

## Requirements

- PHP 8.2+ (Laravel 13 itself requires PHP 8.3+)
- Laravel 11, 12, or 13

The two constraints resolve together rather than conflicting: on PHP 8.2 Composer
installs Laravel 12 or below, and Laravel 13 becomes available once the host
application is on PHP 8.3+.

## Installation

```bash
composer require artisanpack-ui/bookings
```

The service provider is auto-discovered. Publish the configuration when you want
to change the defaults:

```bash
php artisan vendor:publish --tag=bookings-config
```

That writes `config/artisanpack/bookings.php`. Laravel's config loader walks
nested directories under `config/` and prefixes the key with the directory name,
so that file loads under `artisanpack.bookings` — the same key the package reads
from. Individual settings are reached with dot notation:

```php
config( 'artisanpack.bookings.slot_interval' );        // 15
config( 'artisanpack.bookings.admin.gate' );           // 'bookings.manage'
config( 'artisanpack.bookings' );                      // the whole array
```

Migrations are loaded by the package, so `php artisan migrate` creates the
sixteen booking tables with nothing else to do. Publish them only if you need to
edit the schema:

```bash
php artisan vendor:publish --tag=bookings-migrations
```

Publishing copies the files into `database/migrations` and leaves the package
loading its own. That is not a conflict and does not run anything twice: the
migrator keys files by migration name, and the application's path is searched
last, so your copy shadows the package's and is the one that runs. Editing a
published migration works.

What publishing does not do is freeze the set. A later release that adds a
*new* migration has no counterpart in your directory to be shadowed by, so it
loads from the package and runs on the next `migrate` alongside your edited
copies. Publish again after upgrading if you want the whole set under your own
control.

## Configuration

The published file documents every key inline. The ones worth knowing up front:

| Key | Default | What it controls |
| --- | --- | --- |
| `timezone` | `config( 'app.timezone' )` | Zone that unqualified availability is authored in |
| `slot_interval` | `15` | Minutes between candidate slot start times |
| `booking_window` | 60 min – 90 days | How soon and how far ahead a customer may book |
| `cancellation` | allowed, 24h notice | Self-serve cancellation policy |
| `calendar.drivers` | all disabled | Google / Microsoft / Apple sync, opt-in per driver |
| `admin.route_prefix` | `bookings-admin` | Prefix for the staff-facing routes |
| `public.route_prefix` | `bookings` | Prefix for the customer-facing routes |

Environment variables cover the settings most likely to differ per environment:
`BOOKING_DEFAULT_TIMEZONE`, `BOOKING_SLOT_INTERVAL`, `BOOKING_SMS_DRIVER`,
`BOOKING_GOOGLE_ENABLED`, `BOOKING_MICROSOFT_ENABLED`, `BOOKING_APPLE_ENABLED`,
and `BOOKING_PRUNE_DAYS`.

## Rate limiting

Every public route is reachable without credentials, so each one carries a
bucket. The limits are named rather than numeric — `bookings.rate-limit:post`
rather than `throttle:5,1` — and live under
`config( 'artisanpack.bookings.public.rate_limits' )`, so an installation raises
them for its own traffic in one place instead of at each route:

| Bucket | Default (per minute) | Keyed by | Guards |
| --- | --- | --- | --- |
| `post` | 5 | address | `POST api/bookings`, the widget, and the self-serve cancel / reschedule |
| `manage_get` | 20 | address | The manage page read |
| `manage_token` | 60 | manage token | The manage read and the customer feed |
| `ical` | 30 | address | The provider and customer calendar feeds |
| `ical_token` | 30 | feed token | The provider calendar feed |

The reads that carry a link's whole credential are guarded twice — once per
address, once per token — because the two bound different abuses: a machine
grinding through guesses, and a link that has escaped into the world being
fetched from everywhere at once.

### Trusted proxies

The address-keyed buckets are only as truthful as `Request::ip()`. Behind a
load balancer, a CDN, or any reverse proxy, an application that has not told
Laravel which proxies to trust sees every request as coming from the proxy — so
`Request::ip()` returns the proxy's own address (often `127.0.0.1`), every
customer in the world shares the one `post` bucket, and the fifth booking of the
minute is refused for all of them. Configure Laravel's trusted proxies before putting these routes in front
of real traffic.

In Laravel 11, 12, and 13 that is done in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware( function ( Middleware $middleware ): void {
    $middleware->trustProxies( at: [
        '192.0.2.10', // the load balancer or CDN address requests actually arrive from
    ] );
} )
```

Trust only the proxies you operate. `at: '*'` trusts whatever sets
`X-Forwarded-For`, which hands every caller the ability to name their own
address and slip the per-address buckets — set it only when something you
control terminates every request before this application reaches it.

## Multi-site

Site scoping is configured once for the whole ecosystem, in
`artisanpack.core.multi_tenant` — not in this package's config. Set
`ARTISANPACK_MULTI_TENANT_ENABLED=true` (or the `enabled` key) to switch it on,
and list resolvers under `artisanpack.core.multi_tenant.resolvers`. Every owned
table carries a nullable `site_id`, and models using
`Models\Concerns\BelongsToSite` filter on whatever
`ArtisanPackUI\Core\MultiTenancy\SiteContext` reports — so a request cannot be
site 2 for one ArtisanPack package while being site 1 for this one.

Work that has to target or span a specific site pins one explicitly, which is
what a console command looping over sites needs:

```php
use ArtisanPackUI\Core\Facades\ArtisanPackSite;

ArtisanPackSite::forSite( $siteId, fn () => /* every bookings query answers for $siteId */ );
ArtisanPackSite::withoutSite( fn () => /* unscoped, for maintenance work */ );
```

Enabling scoping on an installation that already holds bookings needs `site_id`
backfilled first: rows written while it was off carry a null `site_id`, and the
scope matches on equality, so they leave every site-scoped query the moment a
site resolves. `acrossAllSites()` still sees them.

## Usage

The package entry point resolves through the container, a facade, or a helper —
all three return the same instance:

```php
use ArtisanPackUI\Bookings\Facades\Bookings;

app( 'bookings' );
Bookings::getFacadeRoot();
bookings();
```

The booking, availability, and calendar APIs are added on top of this entry point
in the alpha releases.

### Creating a booking

`Services\BookingService` is the front door to a booking's whole life. Creating
one names a service, a start time, and the customer; naming a provider is
optional, and leaving it out lets the service's assignment strategy pick:

```php
use ArtisanPackUI\Bookings\Services\BookingService;

$booking = app( BookingService::class )->create( [
    'service'           => $service,
    'start_time'        => $start,          // any Carbon or parseable string
    'customer_name'     => 'Sam Rivera',
    'customer_email'    => 'sam@example.test',
    'customer_timezone' => 'America/Chicago',
    'intake_data'       => [ 'goal' => 'Learn to juggle' ],
] );
```

The whole read-availability-and-write sequence runs behind a lock on the
provider's local day, so two customers after the same provider are decided before
either reaches the database. A day rather than a slot, because bookable slots
overlap: at the default fifteen-minute interval a sixty-minute service offers one
every quarter hour, and per-slot locks would let 09:00 and 09:15 race through
separately and double-book the provider for forty-five minutes.

Postgres and MySQL use the server's own advisory
locks, which hold across every process talking to that database. Every other
engine — sqlite, chiefly — has no such primitive, so the cache store's lock
stands in: that is exclusive within one application server and only as wide as
the cache store behind it, so point `artisanpack.bookings.lock.store` at a shared
one if you run more than one.

Either way the lock is a first line of defence rather than the last. If a request
still loses the race, the partial unique index on `bookings` catches it and the
round-robin assigner falls through to the next free provider. `create()` throws
`Exceptions\SlotUnavailableException` only when nobody at all could take the slot.

Intake answers are validated against the service's current form and that version
is snapshotted onto the booking, so the answers stay readable after an
administrator edits the form. Answers the form did not ask for are dropped;
answers it did ask for and did not get raise
`Exceptions\IntakeValidationException`, which carries a `MessageBag`.

The rest of the lifecycle goes through the same service, and must:

```php
$bookings = app( BookingService::class );

$bookings->confirm( $booking, BookingActor::Admin );
$bookings->reschedule( $booking, $newStart, BookingActor::Customer );
$bookings->cancel( $booking, BookingActor::Customer, 'Something came up.' );
$bookings->complete( $booking, BookingActor::Provider );
$bookings->markNoShow( $booking, BookingActor::Admin );
```

Flipping a status directly would skip the action and the event that transition
fires, so anything downstream — a calendar push, a confirmation email, a CRM
record — would either never hear about it or hear about it twice.

### Recurring bookings

`Services\SeriesService` books a repeating arrangement. It takes the booking
attributes above plus an RFC 5545 recurrence rule and a **floating** start — a
clock face and the zone to read it in, not an instant:

```php
use ArtisanPackUI\Bookings\Services\SeriesService;

$series = app( SeriesService::class )->create( [
    'service'          => $service,
    'rrule'            => 'FREQ=WEEKLY;COUNT=12',
    'dtstart_local'    => '2026-06-01 15:00:00',
    'dtstart_timezone' => 'America/Chicago',
    'customer_name'    => 'Sam Rivera',
    'customer_email'   => 'sam@example.test',
] );

$series->occurrences;   // twelve ordinary bookings, linked by series_id
```

The rule is the source of truth and the occurrences are materialised from it —
ordinary bookings written one at a time through `BookingService`, so each takes
the slot lock, validates its intake answers, and fires the usual lifecycle hooks.
Storing the start as a clock face rather than an instant is what makes a weekly
15:00 call stay at 15:00 across a daylight-saving change instead of drifting to
14:00 or 16:00.

An occurrence whose slot has gone is skipped rather than fatal — a rule expanded
over months will cross somebody's holiday sooner or later — so compare
`SeriesCreated::$occurrenceCount` against `expand()` if you need to tell the
customer which weeks did not land. A rule where *nothing* could be booked throws
`SlotUnavailableException` and leaves no series behind. Expansion is capped by
`artisanpack.bookings.series.max_occurrences`, which is what stops an unbounded
`FREQ=DAILY` from asking for an unbounded number of rows. A series is pinned to
one provider: recurring means the same person, so the first occurrence's
assignment is written back onto the series and the rest follow it.

Edits take a scope, which is the choice every calendar application offers:

```php
use ArtisanPackUI\Bookings\Enums\SeriesEditScope;

$recurring = app( SeriesService::class );

// One week moves; the rule is untouched and that occurrence stops following it.
$recurring->edit( $series, SeriesEditScope::This, [ 'start_time' => $newStart ], $occurrence );

// The rule is bounded here, and the new series it returns carries the change forward.
$tail = $recurring->edit( $series, SeriesEditScope::ThisAndFollowing, [ 'rrule' => '…' ], $occurrence );

// The rule is rewritten and everything still to come is re-derived from it.
$recurring->edit( $series, SeriesEditScope::All, [ 'rrule' => '…' ] );

$recurring->cancel( $series, BookingActor::Customer, 'Moving away.' );
```

Occurrences that have already started are never rewritten — they happened — and
neither are detached ones, since detaching is the record that somebody edited that
week by hand. `cancel()` is the exception: it calls off every future occurrence
including the detached, because moving one week to a different afternoon does not
make it less part of the arrangement being cancelled.

Both rewriting scopes free the old slots before taking the new ones, which is the
only order that lets a rule keep times it already holds. A rule that cannot be
*read* is refused before anything is cancelled, so a typo in the RRULE leaves the
arrangement standing; a rule that reads fine but books nothing throws
`SlotUnavailableException` after the fact, because the alternative is returning
an empty arrangement the caller would take for a working one. Individual weeks
that cannot be booked are still skipped rather than fatal — it is only losing
*all* of them that is treated as failure.

A `this_and_following` split divides a `COUNT` between the two halves rather than
giving it to both: splitting `FREQ=WEEKLY;COUNT=12` at week five leaves the head
with four and the tail with eight, not twelve. Supply your own `rrule` in the
changes to override that — a caller writing a new rule is redefining the
arrangement, not continuing it. Splitting at the very first occurrence cancels
the head instead of bounding it, since there is nothing before the split to keep.

Editing a cancelled series throws: an admin with the form open when the customer
cancels would otherwise resurrect it, and the provider would get appointments for
an arrangement every screen reports as off.

Edits run pinned to the series' own site, not to whichever site happens to be in
context. That matters for the two ways the package supports crossing sites — a
console command using `SiteContext::forSite()` and a maintenance query using
`acrossAllSites()` — where the ambient site otherwise disagrees with the series
being edited.

### Public booking widget

Drop the widget on any page:

```blade
<livewire:artisanpack-booking-widget />
```

It walks the customer through service → provider → date and time → details, and
confirms the booking in place. Pin it to one service when the page is already
about that service, and give it a zone to render times in before the browser has
reported its own:

```blade
<livewire:artisanpack-booking-widget service="discovery-call" />
<livewire:artisanpack-booking-widget timezone="Europe/Berlin" />
```

A pinned service is locked: the widget will not book anything else, whatever the
page's query string or a modified client asks for.

The component is registered only when `livewire/livewire` is installed. It is a
suggestion rather than a requirement — the JSON API and the iCal feeds are the
whole surface a headless installation needs — so `composer require
livewire/livewire` if you want the widget.

**The flow works without JavaScript.** Every step is a real `<form>`: choosing a
service, a provider, a month, a day, or a time is a `GET` back to the same page
carrying the choice in the query string, and confirming is a `POST` to
`bookings/widget`, which creates the booking and redirects back with the
confirmation flashed. Where Livewire has loaded it intercepts all of that and
nothing navigates. What JavaScript adds is the one thing the server cannot know:
the visitor's timezone, read from
`Intl.DateTimeFormat().resolvedOptions().timeZone`. Without it the times are
shown in the service's own zone, and the widget says so on screen rather than
leaving it to be guessed.

That `POST` route sits in the `web` middleware group — it needs the session and
the CSRF token the JSON API deliberately does without — and redirects to the
session's previous URL rather than to anything in the payload.

Because the state lives in the query string, a link is shareable and
deep-linkable:

```text
https://example.test/book?bookingService=discovery-call&bookingDate=2026-06-01
```

The intake step renders the service's current `intake_schema` — the same field
list, in the same order, with the same idea of "required" that
`Services\IntakeFieldValidator` will judge the answers against, so the form
cannot ask for something the check does not want or omit something it does.

The markup is plain HTML with daisyUI class names and no dependency on
`artisanpack-ui/livewire-ui-components`. Publish it to change anything:

```bash
php artisan vendor:publish --tag=bookings-views
```

Both halves of the widget spend the same `public.rate_limits.post` bucket as
`POST api/bookings`, so a visitor gets one allowance rather than one per route.

### Manage tokens

A customer with no account manages their booking through a link, and the token in
that link is their whole credential. `Services\ManageTokenService` is the one
place one is minted, hashed, and checked:

```php
use ArtisanPackUI\Bookings\Services\ManageTokenService;

$tokens = app( ManageTokenService::class );

// Minted automatically when a booking is created — take it once, for the email.
$token = $booking->pullPlainManageToken();

$booking = $tokens->findBooking( $requestToken );   // null when the token is unknown
$tokens->verifyFor( $booking, $requestToken );      // hash_equals, never ==

$fresh = $tokens->issueFor( $booking );             // the old link stops working here
```

The token is 32 bytes of CSPRNG output rendered as 64 hex characters, and nothing
about it is derived from the booking — a token that encoded the reference or the
customer's email would let somebody who knows one booking enumerate the rest.
`bookings.manage_token_hash` stores `sha256(token)` and nothing else, so a leaked
row hands over a hash that cannot be turned back into a working link. There is
deliberately no way to recover a plain token from a saved booking: it is returned
once, to whoever minted it. A customer who loses the link gets a new one issued.

Three endpoints are mounted behind that token, and the `bookings.manage-token`
middleware in front of them is the whole authentication layer:

```text
GET  api/bookings/manage/{token}
POST api/bookings/manage/{token}/cancel        { "reason": "optional" }
POST api/bookings/manage/{token}/reschedule    { "start_time": "2026-06-01T19:00:00+00:00" }
```

An unknown token, a malformed one, and a token belonging to another site all
answer with the same 404 and the same message — anything more specific tells a
guesser which guesses were closer. Reads are limited per address *and* per token
(`public.rate_limits.manage_get` and `manage_token`); writes share the `post`
bucket.

Both writes go through `BookingService`, so `ap.bookings.cancelled` and
`ap.bookings.rescheduled` fire with `actor: customer`, and the notifications,
webhooks, and calendar sync hanging off them behave as they do everywhere else.
`cancellation.allowed` and `cancellation.min_advance_minutes` govern what the link
may still do; the read reports that as `meta`, which is what a widget should draw
its buttons from rather than working the policy out again:

```json
{
    "data": { "id": 41, "status": "confirmed", "start_time": "2026-06-01T15:00:00+00:00" },
    "meta": { "can_cancel": true, "can_reschedule": true, "changes_allowed_until": "2026-05-31T15:00:00+00:00" }
}
```

A reschedule is checked against availability as it stands now rather than against
the slot list the page was drawn from, and answers 409 when somebody else took the
slot first.

When a token has leaked — a forwarded confirmation, a mail archive, a referrer
header — rotate every one of them:

```bash
php artisan bookings:reissue-detached-manage-tokens
```

It is deliberately blunt: every token in every site is replaced, so every manage
link the package has ever sent stops working, and a customer has no way back in
until somebody sends them a new one. `ap.bookings.manageTokenReissued` fires per
booking with the new plain token — the only moment it can be read — so an
application with mail wired up can re-send as the rotation runs. Pass `--force`
to skip the confirmation prompt and `--chunk` to tune the query size.

### Self-serve management page

Those endpoints are the machine-facing half of the manage link, and a customer
should not have to be one. Mount the page on a route carrying the
`bookings.manage-token` middleware and drop the component on it:

```php
use Illuminate\Support\Facades\Route;

Route::get( '/bookings/manage/{token}', fn () => view( 'bookings.manage' ) )
    ->middleware( [
        'bookings.rate-limit:manage_get',
        'bookings.rate-limit:manage_token',
        'bookings.manage-token',
    ] )
    ->name( 'bookings.manage' );
```

Give it the same two limiters `GET api/bookings/manage/{token}` carries, in that
order — they bound different abuses, one per address and one per token, and the
resolver is declared last so a guess is counted before it costs a lookup. A page
mounted behind the resolver alone is a weaker door onto the same booking than the
endpoint it replaces.

```blade
<livewire:artisanpack-manage-booking />
```

It shows the appointment, cancels it behind a confirmation step with an optional
reason, and moves it to another slot on the same service and provider. The token
is read from the route — pass it explicitly with `:token="$token"` where the
route names it something else — and is `#[Locked]`, so a modified client cannot
point the page at a booking whose token it has guessed.

The booking is re-resolved from that token on every request rather than held
across them, so a page left open on a phone reflects a cancellation made from
anywhere else instead of acting on a copy from before it. Which buttons are drawn
comes from the same policy the endpoints enforce — `cancellation.allowed`,
`cancellation.min_advance_minutes`, and whether the service is still active — so
the page never offers something the write behind it would refuse. A withdrawn
service stops the reschedule and leaves the cancel, which is the part a customer
whose service has been retired most needs.

Both writes go through `BookingService` with `actor: customer`, exactly as the
endpoints do, and the slots on offer are resolved through `SlotResolver` — so
`ap.bookings.availableSlots` and `ap.bookings.slotBookable` decide what a customer
may pick here too, and a slot a subscriber removed cannot be booked by sending its
instant anyway. Both actions spend the same `public.rate_limits.post` bucket as
`POST api/bookings`.

One thing to know before mounting it anywhere unusual: Livewire serialises a
component's public properties into the page, so **the plain manage token is in the
rendered markup and in every update payload, whichever way it was passed in**. On
the route above that is the same secret in two places on one page. Passing it as
`:token="$token"` — from a POST body, a session value, anywhere but the URL —
keeps it out of the *address*, and does not keep it out of the *response*: there
is no mounting style that does. Weigh that where something records the DOM, since
session replay and error reporters capture markup that referrer policies and
URL-stripping rules never touch.

Like the widget, the markup is plain HTML with daisyUI class names and publishes
with `php artisan vendor:publish --tag=bookings-views`. Unlike the widget, it
needs Livewire: its writes have no plain-form route to post to, and the JSON
endpoints above are what a bespoke page should be built on instead.

### iCal feeds

Two subscribable calendars, so a provider can watch their diary from Apple
Calendar, Google Calendar, or Outlook without anybody connecting an account:

```text
GET bookings/ical/providers/{token}.ics     # a provider's diary
GET bookings/ical/customers/{token}.ics     # the booking a manage token stands for
```

Both sit under `public.route_prefix` without the `api/` the JSON endpoints carry:
nothing calls them from a widget, so the address is one somebody has to be able to
paste into a subscription box. The `.ics` is part of the path rather than a query
string, because clients guess how to treat a subscription URL from its extension.

They are built to be polled. Google refetches a subscribed feed roughly hourly,
Apple about every fifteen minutes, forever, once per subscriber — so every request
computes an entity tag from one aggregate and answers `304 Not Modified` before a
single booking is read:

```text
ETag: "7f3c…"
Cache-Control: private, max-age=300
```

Send it back as `If-None-Match` and the answer is an empty 304. Weak tags and
multi-tag lists are handled, so a proxy in the way does not turn every poll into a
full fetch. The tag folds in the newest `updated_at` in the window, how many
bookings are in it, and how many of those are still published — the counts are
what make a cancellation move the tag, since a booking going away lowers the
newest timestamp rather than raising it.

A feed carries the recent past and the booked future rather than the archive.
`public.ical.past_days` (30) and `future_days` (365) set the window, and
`max_age` (300) says how long a client may hold the answer.

#### The provider feed token

A provider feed is addressed by a token issued to that provider and to nobody
else. It has to be: the feed carries the customer's name and email, and the only
other thing that could address it is the provider's slug — which
`GET api/bookings/services/{slug}/providers` publishes, making the URL something
anybody who can read the booking widget could construct.

Nothing mints a token automatically. A provider has no feed until somebody asks
for one, and a provider without one 404s:

```bash
php artisan bookings:ical-token ada-lovelace     # by slug, or by id
php artisan bookings:ical-token ada-lovelace --revoke
```

The URL it prints is shown once and cannot be recovered. Only `sha256(token)` is
stored, in `service_providers.ical_token_hash`, the way `bookings.manage_token_hash`
holds a manage token — so a leaked backup or a read-only replica hands over
something that cannot be turned back into a working subscription.

One URL, any number of devices: the same address can be pasted into a phone, a
laptop, and a desktop client, and they all keep working. What cannot be done is
*look it up again* — so the URL is worth keeping somewhere the provider can reach
it, not just pasting once.

Rotate when the URL has been lost or you think it has been seen by somebody it
should not have been. **Rotation is not free**: running the command again for a
provider who already has a feed replaces the token, and every calendar client
subscribed to the old URL stops updating at that moment. It does so silently,
because a subscribed feed that starts 404ing does not announce itself — so after
a rotation every one of that provider's clients has to be given the new URL. The
command warns and asks before it does this; `--force` skips the prompt.

`ap.bookings.icalTokenIssued` fires with the new plain token — the only moment it
is readable — so an application with mail wired up can deliver the subscription
URL itself:

```php
addAction( 'ap.bookings.icalTokenIssued', function ( ServiceProvider $provider, string $token ) {
    Mail::to( $provider->email )->send( new CalendarFeedIssued(
        app( IcalTokenService::class )->feedUrl( $token ),
    ) );
} );
```

Feed lookups go through `Services\IcalTokenService`, which shares its primitives
with `ManageTokenService` — 32 CSPRNG bytes as 64 hex characters, `sha256` in the
column, `hash_equals()` on the way back — so the two credential schemes cannot
drift apart. Unknown tokens, revoked feeds, rotated tokens, deactivated providers,
and tokens belonging to another site all answer with the same 404 and the same
message.

The customer feed is the manage token's, guarded by the same
`bookings.manage-token` middleware as the manage endpoints, and carries the one
booking that token stands for — a token discloses no more through its calendar
than through the link it came in on. It is limited per address *and* per token,
for the reason the manage read is: a feed URL sits in a calendar client's settings
for years, which is exactly how a link escapes. The provider feed is limited the
same way, per address and per token (`public.rate_limits.ical` and `ical_token`).

Rescheduling moves an event a subscriber already has rather than leaving a
duplicate behind: the `UID` is built from `booking_number`, which never changes.
Cancelling removes it, which is what a provider wants their week to look like.

### Outbound webhooks

Subscribe an endpoint and the booking lifecycle fans out to it on its own — no
wiring, no listener of your own:

```php
use ArtisanPackUI\Bookings\Models\Webhook;

Webhook::create( [
    'name'   => 'Zapier',
    'url'    => 'https://hooks.zapier.test/bookings',
    'secret' => Str::random( 40 ),
    'events' => [ 'booking.confirmed', 'booking.cancelled' ],
] );
```

Six events are raised, one per lifecycle transition:

| Event                 | Raised when                                             |
|-----------------------|---------------------------------------------------------|
| `booking.created`     | A booking is made, whether or not it is yet confirmed    |
| `booking.confirmed`   | It becomes an appointment                                |
| `booking.rescheduled` | It moves; carries `data.previous_period`                 |
| `booking.reassigned`  | It moves to another provider; carries `data.previous_provider_id` |
| `booking.cancelled`   | It is called off; carries `data.reason`                  |
| `booking.completed`   | It is marked as having happened                          |
| `booking.no_show`     | The customer did not arrive                              |

A booking that is auto-confirmed raises `booking.created` and
`booking.confirmed` in that order, which is the pair a consumer should expect.

Each delivery is queued as its own job and recorded in
`booking_webhook_deliveries`, so what was sent, what came back, and whether it
will be tried again are questions the database answers. The body is an envelope
around the booking:

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

Times are UTC, with the customer's own zone named beside them rather than
applied to them — a consumer wanting to show a clock face has what it needs, and
one wanting an instant is not made to guess which it was given.

Raise your own events through the same machinery when you have something to say
that the lifecycle does not cover:

```php
use ArtisanPackUI\Bookings\Services\WebhookDispatcher;

app( WebhookDispatcher::class )->dispatch( 'booking.confirmed', $payload, $siteId );
```

Pass the site when you know it. Left out, the endpoint list is scoped to
whatever site is in context — which in console is none of them, and therefore
all of them.

Each request carries the event, the delivery id, the attempt number, a
timestamp, and a signature:

```
X-ArtisanPack-Event: booking.confirmed
X-ArtisanPack-Delivery: 4711
X-ArtisanPack-Attempt: 1
X-ArtisanPack-Timestamp: 1767024000
X-ArtisanPack-Signature: sha256=<hex>
```

The signature is `HMAC-SHA256( "{timestamp}.{body}", secret )` over the exact
bytes of the request body. Verify it in constant time, and reject a timestamp
older than your own tolerance — that is what stops a captured request from being
replayed, and it is why the timestamp is inside the signed string rather than
merely beside it:

```php
$signed = $request->header( 'X-ArtisanPack-Timestamp' ) . '.' . $request->getContent();

if ( ! hash_equals( 'sha256=' . hash_hmac( 'sha256', $signed, $secret ), $request->header( 'X-ArtisanPack-Signature' ) ) ) {
    abort( 401 );
}
```

A delivery is accepted on any 2xx. Anything else — a 500, a refused connection, a
timeout — is a failure, and the delivery is retried on
`webhooks.delivery_backoff_minutes`, which defaults to 1, 5, 30, 120, and 720
minutes. That is the list of delays *between* attempts, so an endpoint gets six
attempts over about fourteen hours before the delivery is marked `dead` and left
alone.

Failures are counted on the endpoint rather than on the delivery, so an endpoint
returning 500 to everything is disabled after `webhooks.failure_threshold`
consecutive failures however those were spread across events — and a single
success resets the count, because the threshold is for an endpoint that is gone
rather than one that flapped. Disabling fires `WebhookDisabled`, which is where
an application tells the consumer their integration has stopped; nothing else
will.

Deliveries are pushed onto the default queue unless `webhooks.queue` names one.
Give them their own queue on an installation where a slow consumer must not
delay everything else that is queued.

**The endpoint URL is guarded against SSRF.** The package posts wherever
`booking_webhooks.url` says, from inside your application, and stores the first
2,000 characters of the reply where an admin screen can read it. That is a
request-forgery primitive if the URL is not trusted: an internal service or a
cloud metadata endpoint is reachable from your server and not from a browser,
and the response comes back out through the delivery ledger. Redirects are
refused, so the address that was reviewed stays the address that is called.

The address itself is checked by a guard, `webhooks.url_guard`, on by default. It
allows only the schemes on `allowed_schemes` (`https` alone unless you add
`http`) and refuses any URL whose host resolves to a loopback, private,
link-local, or unique-local address — every address a name answers with, since a
name offering one public and one private address is still a way into the private
one. The check runs at delivery, not only when the endpoint is saved, because a
name approved once can be repointed afterwards; a refused URL kills the delivery
the way a disabled endpoint does, with the reason on the row. The host is
resolved once and the vetted address is pinned into the connection, so the HTTP
client cannot resolve the name a second time and reach an address the guard
never checked — the DNS-rebinding case. The request is made under the host the
address was pinned to (lower-cased, no trailing dot, and IDN names in their
punycode form) so the client resolves the exact string the pin was keyed on. TLS
still verifies against the hostname.

Pinning is enforced by `CURLOPT_RESOLVE`, which needs the curl HTTP handler
(`ext-curl`) and libcurl 7.59 or newer for its multi-address form. An
installation without curl, or on older libcurl, still gets the delivery-time
address check — it just cannot pin the connection to it, so a determined
DNS-rebinding attacker regains the narrow resolve-then-connect window there.

An installation that delivers to an internal host on purpose names it in
`allowed_hosts`, where it skips the range check; `blocked_hosts` refuses a host
whatever it resolves to; and `enabled => false` switches the guard off for an
installation where every endpoint is operator-created. If you accept an endpoint
URL below operator trust — a tenant self-service screen — apply the
`ValidWebhookUrl` rule to the field so a bad address is refused before it is
stored:

```php
use ArtisanPackUI\Bookings\Rules\ValidWebhookUrl;

$request->validate( [
    'url' => [ 'required', 'url', new ValidWebhookUrl() ],
] );
```

### Text messages

An `sms` notification channel ships, and sends nothing until you give it a
gateway. Two switches, deliberately separate:

```php
// config/artisanpack/bookings.php
'notifications' => [
    'channels'   => [ 'mail', 'database', 'webhook', 'sms' ],
    'sms_driver' => App\Sms\TwilioSmsDriver::class,
],
```

`sms_driver` decides *how* a text is sent and `channels` decides *whether* one
is. The default driver — `null` — logs the message at info level and sends
nothing, so an installation that lists the channel before it has a gateway can
see exactly what would have gone out, and to which number, without paying for it.

`sms` is not in the shipped channel list, and installing a driver does not add
it. Texts cost money per message and arrive on a real phone, so sending one is
never something the package decides on your behalf.

Writing a driver is one method. It is handed a number and a string, and knows
nothing about bookings:

```php
use ArtisanPackUI\Bookings\Contracts\SmsDriver;

class TwilioSmsDriver implements SmsDriver
{
    public function send( string $phone, string $message ): void
    {
        // Throw if the gateway refuses it. The send is recorded against
        // booking_notification_log as failed, the other channels carry on,
        // and an operator has something to read.
    }
}
```

Name the class in `sms_driver`, or bind `SmsDriver` in the container if it needs
constructing your way. A name that resolves to nothing throws rather than falling
back to the null driver — an installation that thinks it configured Twilio and is
quietly writing log lines has every customer unreachable and nothing obviously
wrong.

To text on some events only — or only customers who asked to be texted — leave
`sms` out of the configured list and add it from the channels filter:

```php
addFilter(
    'ap.bookings.notification.channels',
    function ( array $channels, string $event, Booking $booking ): array {
        if ( 'cancellation' === $event ) {
            $channels[] = 'sms';
        }

        return $channels;
    },
);
```

The message body is the email's opening line and appointment details, without the
greeting. Replace it by returning your own notification with a `toSms(): string`
method from `ap.bookings.notification.sending`. The channel declines a booking
with no phone number, and one whose personal data has been erased.

**The null driver writes the number and the message to your log.** That is what
it is for, and it is also customer contact details and an appointment time
sitting in a file that erasing a booking does not reach — the erasure routine
sweeps `bookings` and `booking_notification_log`, not `storage/logs`. Fine in
development, and a disclosure you have to be able to make if you leave `sms`
enabled without a gateway in production. Bind a driver that discards the body, or
take `sms` back out of the channel list, if you cannot.

**A phone number on a booking is attacker-supplied.** If your widget is public,
anyone can submit a number they do not own and make your application text it, at
your expense — the abuse is called SMS pumping, and a booking form that sends on
request is the shape of it. Before you bind a real gateway to a public widget:
rate-limit bookings per address and per source, hold texts until a booking is
confirmed rather than requested, and reject numbers outside the regions you
serve. The package cannot do any of that for you, because only you know which
numbers are legitimate.

Twilio and Vonage drivers ship in v1.1.

### Scheduled commands

The package puts its own recurring work on your application's schedule. You do
not register anything — run Laravel's scheduler and it happens:

```bash
php artisan schedule:work    # or the usual cron entry for schedule:run
```

| Command | Cadence | What it does |
|---------|---------|--------------|
| `bookings:send-reminders` | Every 15 minutes | Sends the reminders that have come due. |
| `bookings:complete-past` | Hourly | Marks confirmed bookings whose end time has passed as completed. |
| `bookings:calendar-refresh` | Daily | Re-reads busy blocks for two-way connections. |
| `bookings:calendar-watch-renew` | Hourly | Renews Google/Microsoft push registrations before they lapse. |
| `bookings:calendar-apple-poll` | Every 15 minutes | Polls Apple calendars, which cannot push. |
| `bookings:prune-notification-log` | Daily, 03:10 | Removes notification log rows past their retention window. |
| `bookings:prune-webhook-deliveries` | Daily, 03:20 | Removes settled delivery attempts past their retention window. |
| `bookings:prune-calendar-events` | Daily, 03:30 | Removes calendar mappings for bookings long over. |

`bookings:reissue-detached-manage-tokens` and `bookings:ical-token` are never
scheduled. The first invalidates every manage link the package has ever sent; the
second invalidates one provider's calendar subscriptions and prints a URL that
exists nowhere else. Both are things you do in response to something, in front of
the output, rather than things a clock should decide.

Every one is registered `withoutOverlapping()`. None of them needs it for
correctness — a reminder is claimed in the notification log before it is sent,
completion re-reads the status it transitions, and deleting rows another prune
already deleted is a no-op — but a doubled run is a lot of database round trips
to do nothing.

The reminder sweep is dropped when `notifications.reminder.enabled` is false, and
the three calendar sweeps are registered only when a matching driver is switched
on under `calendar.drivers`. Both are gates on the schedule, not on the commands:
you can always run any of them by hand.

**Everything destructive takes `--dry-run`**, which reports what a run would do
and changes nothing. On `bookings:complete-past` that includes firing no hooks
and dispatching no events, so a subscriber cannot email a customer about a
completion that has not happened:

```bash
php artisan bookings:complete-past --dry-run
php artisan bookings:prune-notification-log --dry-run
```

Only `confirmed` bookings are completed. A `requested` one is an appointment
nobody approved, and marking it delivered would be the package asserting that
something happened which may never have been accepted — those are left for staff
to dispose of, as a completion or a no-show.

The three prunes read their windows from `retention`:

```php
'retention' => [
    'notification_log_days'    => 90,
    'webhook_delivery_days'    => null,   // falls back to webhooks.delivery_retention_days
    'calendar_events_ttl_days' => 30,
],
```

A window of zero or less is read as "not configured" and prunes nothing, rather
than as "keep nothing" — a blank environment variable is a likelier way to reach
zero than a retention policy is. That holds for `webhook_delivery_days` too: only
leaving it unset defers to `webhooks.delivery_retention_days`, so zeroing it
switches the prune off rather than falling back to somebody else's thirty days.
Keep `notification_log_days` comfortably longer
than your longest reminder in `hours_before`: the log row is what stops a
reminder being sent twice, so pruning one inside its own reminder window
un-claims a send that already happened. A `pending` webhook delivery is never
pruned however old it is, because deleting it makes the delivery stop existing
rather than fail. And calendar mappings are pruned by the booking's end time
rather than by the row's own age — a mapping for an appointment a year out is
older than yesterday's, and is exactly what a reschedule still needs.

The calendar sweeps find what is due and then need a `CalendarSyncDriver` to act
on it. Those ship in `artisanpack-ui/google`, `artisanpack-ui/microsoft`, and
`artisanpack-ui/apple`; until one is installed the sweeps report what they found
and warn that nothing was synced.

### Admin surface

The staff-facing screens are mounted as routes under `admin.route_prefix`
(`bookings-admin/…` by default), one per screen — the bookings list and
calendar, services and their intake schemas, providers and their schedules,
blackout dates, recurring series, calendar connections, webhooks, the
notification log, and settings. They are named `artisanpack.bookings.admin.*`, so
link to them with `route()` rather than a hard-coded path:

```php
route( 'artisanpack.bookings.admin.bookings' );   // the list
route( 'artisanpack.bookings.admin.settings' );   // general config
```

Every screen sits behind the `bookings.admin` gate, which authorizes against the
ability named by `admin.gate` (`bookings.manage`). The package defines no default
ability on purpose: `Gate::authorize()` against an undefined ability denies, so
mounting the admin without wiring the gate is a locked door, not an open one.
Define it against whatever "staff" means to your application:

```php
Gate::define( 'bookings.manage', fn ( User $user ) => $user->isStaff() );
```

Each screen renders inside a layout chosen for you: `cms::admin.layouts.app` when
`artisanpack-ui/cms-framework` is installed, and the package's own
`bookings::admin.layouts.app` when it is not. Publish the standalone layout with
`php artisan vendor:publish --tag=bookings-views` and edit the copy under
`resources/views/vendor/bookings/admin/layouts/app.blade.php` to wrap the screens
in your own chrome.

With cms-framework installed, the screens also register themselves in its admin
navigation through the `ap.cmsFramework.admin.menu` filter — a single **Bookings**
section with every screen beneath it, each gated by the same `bookings.manage`
ability. Turn that off with `admin.auto_register_cms_nav` when you would rather
place the screens in the shell's menu yourself.

## Extending

### Contracts

Six seams are interfaces under `ArtisanPackUI\Bookings\Contracts`. Bind your own
implementation and the package uses it instead of the default:

| Contract | Replaces |
| --- | --- |
| `SlotResolver` | how availability rules become bookable slots |
| `RoundRobinStrategy` | which provider is assigned to a slot |
| `CalendarSyncDriver` | how one external calendar system is talked to |
| `NotificationChannel` | how one lifecycle message is delivered |
| `SmsDriver` | which gateway a text message is handed to |
| `MeetingTypeRegistry` | which shapes a service can be booked in |

`MeetingTypeRegistry` is the odd one out: bind it only to replace the registry
itself. To add a meeting type — the common case — implement
`ArtisanPackUI\Bookings\Contracts\MeetingType` (or construct a
`RegisteredMeetingType`) and contribute it through the filter described under
[Hooks](#hooks). No binding required.

Site resolution is deliberately not on this list. It is
`ArtisanPackUI\Core\Contracts\SiteResolver`, bound once for the whole ecosystem —
see [Multi-site](#multi-site).

### Events

Every lifecycle change dispatches a typed event under
`ArtisanPackUI\Bookings\Events`. Payloads are serializable, so a listener may be
queued:

`BookingRequested`, `BookingConfirmed`, `BookingRescheduled`, `BookingCancelled`,
`BookingCompleted`, `BookingNoShow`, `SeriesCreated`, `SeriesCancelled`,
`SeriesEdited`, `CalendarSynced`, `CalendarSyncFailed`,
`CalendarConnectionDisabled`, and `WebhookDisabled`.

`BookingCancelled` carries a `BookingActor` because "the customer cancelled" and
"we cancelled on the customer" are the same status change and completely
different events downstream. `SeriesEdited` carries a `SeriesEditScope` for the
same reason.

These names are public API. They are what `artisanpack-ui/crm` will subscribe to,
and they will not be renamed without a deprecation cycle.

### Hooks

Actions and filters are registered through `artisanpack-ui/hooks`. Names take an
`ap.` prefix, `.`-separated segments, and camelCase within each segment — so both
`ap.bookings.registeredMeetingTypes` and grouped names like
`ap.bookings.calendarSync.providers` are well formed. Never snake_case.

Actions fire; filters transform a value and must return one.

| Hook | Type | Payload |
| --- | --- | --- |
| `ap.bookings.creating` | action | `(array $attributes, ?Authenticatable $customer)` |
| `ap.bookings.created` | action | `(Booking $booking)` |
| `ap.bookings.confirmed` | action | `(Booking $booking)` |
| `ap.bookings.rescheduling` | action | `(Booking $booking, CarbonImmutable $newStart)` |
| `ap.bookings.rescheduled` | action | `(Booking $booking, CarbonImmutable $oldStart)` |
| `ap.bookings.cancelling` | action | `(Booking $booking, string $reason)` |
| `ap.bookings.cancelled` | action | `(Booking $booking)` |
| `ap.bookings.completed` | action | `(Booking $booking)` |
| `ap.bookings.noShow` | action | `(Booking $booking)` |
| `ap.bookings.reassigned` | action | `(Booking $booking, ?int $previousProviderId)` |
| `ap.bookings.series.editApplying` | action | `(BookingSeries $series, string $scope, array $changes)` |
| `ap.bookings.manageTokenReissued` | action | `(Booking $booking, string $plainToken)` |
| `ap.bookings.manageTokensReissued` | action | `(int $count)` |
| `ap.bookings.icalTokenIssued` | action | `(ServiceProvider $provider, string $plainToken)` |
| `ap.bookings.icalTokenRevoked` | action | `(ServiceProvider $provider)` |
| `ap.bookings.calendarSync.providers` | filter | `(array $drivers)` |
| `ap.bookings.calendarSync.pushing` | action | `(Booking $booking, string $providerSlug)` |
| `ap.bookings.calendarSync.pushed` | action | `(Booking $booking, string $providerSlug, string $externalEventId)` |
| `ap.bookings.calendarSync.pullReceived` | action | `(array $payload, string $providerSlug)` |
| `ap.bookings.calendarSync.eventPayload` | filter | `(array $payload, Booking $booking, string $providerSlug)` |
| `ap.bookings.calendarSync.connectionDisabled` | action | `(CalendarConnection $connection, string $reason)` |
| `ap.bookings.availableProviders` | filter | `(array $providers, Service $service, CarbonImmutable $start)` |
| `ap.bookings.roundRobin.selectProvider` | filter | `(?ServiceProvider $selected, array $candidates, Booking $draft)` |
| `ap.bookings.intakeSchema` | filter | `(array $schema, Service $service, int $version)` |
| `ap.bookings.availabilityQuery` | filter | `(Builder $query, array $criteria)` |
| `ap.bookings.availableSlots` | filter | `(array $slots, ServiceProvider $provider, CarbonPeriod $window)` |
| `ap.bookings.slotBookable` | filter | `(bool $bookable, Slot $slot, ?Authenticatable $customer)` |
| `ap.bookings.slotDuration` | filter | `(int $minutes, Service $service, ServiceProvider $provider)` |
| `ap.bookings.registeredMeetingTypes` | filter | `(array $types)` |
| `ap.bookings.notification.sending` | filter | `(BookingNotification $notification, Booking $booking)` |
| `ap.bookings.notification.channels` | filter | `(array $channels, string $event, Booking $booking)` |
| `ap.bookings.notification.subject` | filter | `(string $subject, BookingNotification $notification, Booking $booking)` |
| `ap.bookings.reminderScheduling` | filter | `(array $hoursBefore, Booking $booking)` |

Four of these have rules worth stating outright.

`ap.bookings.creating` fires inside the slot lock, and can fire more than once
for a single `create()` call — once per provider tried, when a lost race falls
through to the next candidate. `ap.bookings.created` fires exactly once. Neither
can cancel a booking: subscribe to the `BookingRequested` event and cancel it
there, which is a real cancellation with a freed slot rather than an abort inside
a held lock.

`ap.bookings.roundRobin.selectProvider` returning `null` means "no opinion" and
leaves the default rota's answer standing. Returning somebody who is not in
`$candidates` throws — they were not free for the slot. It does not fire at all
when the customer named their provider by hand.

`ap.bookings.series.editApplying` fires once per scoped series edit, before any
of it lands, so a subscriber reading the series back still sees the rule it is
about to replace. `$scope` is the string `this`, `this_and_following`, or `all` —
the same value the `SeriesEdited` event carries. The occurrences a series edit
writes and discards go through `BookingService` like any other booking, so they
fire `ap.bookings.created` and `ap.bookings.cancelled` once each, per occurrence.

`ap.bookings.manageTokenReissued` carries a live secret — the plain manage token,
at the only moment it is readable. It is there so an emergency rotation can be
followed by new links reaching customers; do not log it, and do not put it
anywhere the hash was kept out of.

`ap.bookings.icalTokenIssued` carries a live secret for the same reason: the plain
calendar feed token, at the only moment it is readable, which is the whole
credential behind a provider's diary. It fires on a rotation as well as on a first
issue, and by the time it does the provider's previous token is already dead — so
a subscriber that delivers the new subscription URL is not being helpful, it is
the only thing standing between the provider and a feed that has silently stopped
updating.

`ap.bookings.calendarSync.pullReceived` carries the external calendar's raw change
feed — personal data that is not this package's. The payload holds event titles
and descriptions, organiser and attendee email addresses, and the shape of the
provider's private diary, handed over before normalisation so a subscriber can see
what the calendar actually sent. Do not log it, and treat anything drawn from it as
third-party data subject to the same handling as any other personal information.

`ap.bookings.intakeSchema` runs against the version a booking was captured with
rather than the service's current form, and its output is never written back. A
subscriber is describing how a form should be read, not editing the record of
what was asked.

The four notification filters all run *before* the log row is claimed, which is
what keeps them inside the idempotency guarantee rather than outside it.
`ap.bookings.notification.sending` returns the notification to send, a
replacement for it, or `null` to suppress the send entirely; returning anything
else throws, because a subscriber meaning to veto says so with `null` and
anything else is a mistake worth surfacing. It runs once per channel, so
suppressing the customer's email still leaves the admin's database copy.
`ap.bookings.reminderScheduling` filters the cadence in whole hours before the
start, and duplicate windows are collapsed — a subscriber appending `24` to a
config that already has it changes nothing rather than fighting the unique index
on every cron run. A window *longer* than anything in config also needs
`notifications.reminder.max_lookahead_hours` raised to match, since the sweep has
to decide how far ahead to look before it has a booking to hand the filter.

Meeting types are contributed through a filter rather than being hard-coded:

```php
use ArtisanPackUI\Bookings\MeetingTypes\RegisteredMeetingType;

addFilter( 'ap.bookings.registeredMeetingTypes', function ( array $types ): array {
    $types[] = new RegisteredMeetingType(
        'webinar',
        'Webinar',
        'Broadcast to many attendees at once.',
        allowsMultipleAttendees: true,
    );

    return $types;
} );
```

Pass the label and description untranslated — they are used as translation keys
and run through `__()` when read, so they follow the current locale rather than
freezing at whichever one was active when the registry was first resolved.

The filter runs on every read, so registering from a service provider that boots
after this one still works. Entries are keyed by the type's own `key()`, so
appending and assigning behave identically. The four built-ins — `one_to_one`,
`group`, `recurring`, and `round_robin` — are ordinary entries: register a type
under an existing key to replace it.

#### The registry

`Support\HookRegistry` is the machine-readable version of the table above, and
the whole list: it also names the hooks whose surfaces — calendar sync,
notifications — are not built yet, so a subscriber can be written against a name
before the code that fires it exists.

```php
use ArtisanPackUI\Bookings\Support\HookRegistry;

HookRegistry::all();      // every hook, with its type and the issue that fires it
HookRegistry::shipped();  // the ones firing today — the table above
HookRegistry::pending();  // declared, not yet fired
```

Nothing inside the package reads it; hook names stay as literals at their call
sites. What it is for is the test that holds the two lists together — every
shipped name is fired somewhere in `src/`, and every `ap.bookings.*` literal in
`src/` is declared here — so a surface cannot ship without its hook, and a hook
cannot ship undocumented.

## Optional integrations

The package runs standalone in any Laravel application. When these are installed
it uses them, and degrades cleanly when they are not:

- `livewire/livewire` — the public booking widget and the admin screens
- `artisanpack-ui/cms-framework` — admin navigation, permissions, settings
- `artisanpack-ui/livewire-ui-components` — admin screen rendering (the public widget is plain HTML and does not use it)
- `artisanpack-ui/forms` — `booking_slot` field type, booking-from-submission
- `artisanpack-ui/media-library` — service and provider images
- `artisanpack-ui/google`, `artisanpack-ui/microsoft`, `artisanpack-ui/apple` — calendar sync drivers
- `artisanpack-ui/accessibility` — accessible admin and widget theming

The `database` notification channel writes through Laravel's own notification
storage, so the notifiable's table has to be the shape Laravel expects — a UUID
key and a JSON `data` column. `artisanpack-ui/cms-framework` ships its own
`notifications` table with an incompatible schema, so an application running both
has to point its notifiable at storage of its own. A failed write is recorded
against the notification log rather than thrown, so the customer's email goes out
either way; the admin row is what goes missing.

Where one of these is subscribed to rather than merely used, the binding goes
through `Support\HookSubscriptions`, which is the single place that answers "is
that package installed?" — by probing for the class the integration itself needs,
so a callback naming absent classes is never entered:

```php
use ArtisanPackUI\Bookings\Support\HookSubscriptions;

HookSubscriptions::whenInstalled( 'forms', function (): void {
    addFilter( 'ap.forms.fieldTypes', /* ... */ );
} );
```

Upstream hooks keep their upstream names — this package does not rename another
package's hooks.

## Development

```bash
composer install
composer test      # Pest
composer lint      # php-cs-fixer --dry-run + pint --test + phpcs
composer fix       # pint, then php-cs-fixer
```

### Testing

Tests run on Pest 3 and Orchestra Testbench. `Tests\TestCase` registers the
core, hooks, and bookings providers and points the application at an in-memory
SQLite database, so `composer test` needs nothing installed beyond Composer
dependencies. Model factories resolve to
`ArtisanPackUI\Bookings\Database\Factories\<Model>Factory`.

A few tests cannot run on SQLite. Booking creation has to be race-safe — two
customers must not both take the last slot — and the guard is a named advisory
lock: MySQL's `GET_LOCK`, Postgres' `pg_advisory_xact_lock`. Those tests use
`Tests\Concerns\TestsWithMysql` or `TestsWithPostgres` and carry a matching
group:

```bash
composer test:sqlite     # everything that runs in memory
composer test:mysql      # the mysql group, against a real MySQL server
composer test:postgres   # the postgres group, against a real Postgres server

DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=bookings_test \
DB_USERNAME=root DB_PASSWORD=secret composer test:mysql
```

When the server is unreachable those tests skip, so a plain `composer test` is
green without one running. **CI must not accept that skip** — a skipped lock
test reads as "race-safety verified" while verifying nothing — so the
`test-mysql` and `test-postgres` jobs in `.github/workflows/ci.yml` set
`BOOKINGS_REQUIRE_EXTERNAL_DB=1`, which turns the skip into a failure.

Both formatters come from `artisanpack-ui/code-style-pint`: `pint.json` is
generated from its `ArtisanPackUIPreset`, and `.php-cs-fixer.dist.php` adds the
WordPress-style spacing its custom fixers provide — `if ( $condition )`,
`myFunction( $arg )`, `[ 'key' => 'value' ]`. Pint alone cannot produce that
spacing, so `composer fix` runs Pint first and lets PHP-CS-Fixer settle the
spacing afterwards. `pint.json` disables the handful of Laravel-preset rules
that fight the house standard (`phpdoc_no_package`, `no_superfluous_phpdoc_tags`,
`declare_parentheses`, `trim_array_spaces`, `no_spaces_around_offset`), so the
two tools agree on a single fixpoint rather than reformatting each other.

## Contributing

As an open source project, this package is open to contributions from anyone. Please [read through the contributing
guidelines](CONTRIBUTING.md) to learn more about how you can contribute to this project.
