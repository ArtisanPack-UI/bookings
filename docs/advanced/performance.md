---
title: Performance
---

# Performance

Two paths carry the load: the availability read behind the widget, and the calendar-sync jobs that fan a booking out to connected calendars. Both have a target, and a benchmark under `benchmarks/` that measures against it. These targets describe the shape the package is built to and are **not GA-gating** — a regression tripwire, not a release gate. The numbers a run reports depend on the hardware, PHP build, and cache and queue drivers it runs on.

| Path | Target |
| --- | --- |
| Availability resolve | p95 **< 200ms warm** for 5 providers × 90 days × 15-minute intervals |
| Calendar sync | sustained throughput with Google mocked, bounded by the queue driver and worker count |

## Availability

Availability is cached per service, provider, and provider-local date, so the warm path — every day a cache hit — is the one the 200ms target is written against. The cold path recomputes each day from the database and is naturally slower; the benchmark reports both so a change to the computation shows up even when the cache hides it.

The warm number only means something against the cache store you run in production: back it with redis (`BOOKING_BENCH_CACHE=redis`) rather than the `array` store the benchmark defaults to. See the [availability cache config](Installation-Configuration).

## Calendar sync

Calendar sync dispatches one `SyncBookingToCalendars` job per connection, and the benchmark drives that real job with Google faked in-process, so the figure it reports is the package's own per-push cost — the orchestrator, the ledger write, and the driver call. Real backpressure is then a function of the queue driver and worker count layered on top of that ceiling; the fake accepts a per-call latency (`BOOKING_BENCH_SYNC_LATENCY_MS`) to model a calendar round-trip.

## Running the benchmarks

```bash
composer bench                 # both benchmarks
composer bench:availability    # availability resolve, warm and cold
composer bench:calendar-sync   # calendar-sync throughput

# Tune a run with environment variables, e.g. a redis-backed warm read:
BOOKING_BENCH_CACHE=redis BOOKING_BENCH_WARM_ITERATIONS=500 composer bench:availability
```

The scripts boot a Testbench application, seed the scenario, and print min, mean, p50, p95, p99, and max. Every knob — `BOOKING_BENCH_PROVIDERS`, `BOOKING_BENCH_DAYS`, `BOOKING_BENCH_BOOKINGS_PER_PROVIDER`, the iteration counts, and the two above — has a default that reproduces the table, so a bare `composer bench` answers the targets directly.

## Keeping the warm path warm

The availability cache is invalidated automatically when anything that affects a provider's slots is written — a schedule, an override, a booking, a blackout date, a busy block, a service, a provider, or a connection. You do not clear it by hand; a write clears exactly the keys it can affect. Point `artisanpack.bookings.lock.store` and your cache at a shared store (redis) when you run more than one application server, so the warm path is warm for every node. See [Requirements](Installation-Requirements).
