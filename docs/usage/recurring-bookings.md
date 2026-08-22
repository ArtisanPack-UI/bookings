---
title: Recurring Bookings
---

# Recurring Bookings

`Services\SeriesService` books a repeating arrangement. It takes the booking attributes plus an RFC 5545 recurrence rule and a **floating** start — a clock face and the zone to read it in, not an instant:

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

## How occurrences are materialised

The rule is the source of truth and the occurrences are materialised from it — ordinary bookings written one at a time through `BookingService`, so each takes the slot lock, validates its intake answers, and fires the usual lifecycle hooks. Storing the start as a clock face rather than an instant is what makes a weekly 15:00 call stay at 15:00 across a daylight-saving change instead of drifting.

An occurrence whose slot has gone is skipped rather than fatal — a rule expanded over months will cross somebody's holiday sooner or later — so compare `SeriesCreated::$occurrenceCount` against `expand()` if you need to tell the customer which weeks did not land. A rule where *nothing* could be booked throws `SlotUnavailableException` and leaves no series behind.

Expansion is capped by `artisanpack.bookings.series.max_occurrences`, which is what stops an unbounded `FREQ=DAILY` from asking for an unbounded number of rows. A series is pinned to one provider: recurring means the same person, so the first occurrence's assignment is written back onto the series and the rest follow it.

## Editing a series

Edits take a scope, which is the choice every calendar application offers:

```php
use ArtisanPackUI\Bookings\Enums\SeriesEditScope;
use ArtisanPackUI\Bookings\Enums\BookingActor;

$recurring = app( SeriesService::class );

// One week moves; the rule is untouched and that occurrence stops following it.
$recurring->edit( $series, SeriesEditScope::This, [ 'start_time' => $newStart ], $occurrence );

// The rule is bounded here, and the new series it returns carries the change forward.
$tail = $recurring->edit( $series, SeriesEditScope::ThisAndFollowing, [ 'rrule' => '…' ], $occurrence );

// The rule is rewritten and everything still to come is re-derived from it.
$recurring->edit( $series, SeriesEditScope::All, [ 'rrule' => '…' ] );

$recurring->cancel( $series, BookingActor::Customer, 'Moving away.' );
```

Occurrences that have already started are never rewritten — they happened — and neither are detached ones, since detaching is the record that somebody edited that week by hand. `cancel()` is the exception: it calls off every future occurrence including the detached.

Both rewriting scopes free the old slots before taking the new ones, which is the only order that lets a rule keep times it already holds. A rule that cannot be *read* is refused before anything is cancelled, so a typo in the RRULE leaves the arrangement standing; a rule that reads fine but books nothing throws `SlotUnavailableException` after the fact.

A `this_and_following` split divides a `COUNT` between the two halves rather than giving it to both: splitting `FREQ=WEEKLY;COUNT=12` at week five leaves the head with four and the tail with eight. Supply your own `rrule` in the changes to override that. Splitting at the very first occurrence cancels the head instead of bounding it.

Editing a cancelled series throws: an admin with the form open when the customer cancels would otherwise resurrect it.

## Events & hooks

- `SeriesCreated` — dispatched once with the `occurrenceCount`
- `SeriesEdited` — carries the `SeriesEditScope` and, on a split, the new `splitSeries`
- `SeriesCancelled` — carries the `cancelledOccurrenceCount`
- `ap.bookings.series.editApplying` — fires once per scoped edit, before any of it lands

Each occurrence a series edit writes and discards goes through `BookingService` like any other booking, so it fires `ap.bookings.created` and `ap.bookings.cancelled` once each. See [Events](Api-Events).

## Cross-site edits

Edits run pinned to the series' own site, not to whichever site happens to be in context. That matters for the two ways the package supports crossing sites — a console command using `SiteContext::forSite()` and a maintenance query using `acrossAllSites()`. See [Multi-Site](Advanced-Multi-Site).
