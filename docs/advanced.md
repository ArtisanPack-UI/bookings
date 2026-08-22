---
title: Advanced Overview
---

# Advanced

Operational and security topics for running Bookings in production — multi-tenancy, the rate-limit and proxy setup that keeps the public routes honest, the SSRF guard on outbound webhooks, the GDPR retention and erasure tooling, the full command reference, and the performance targets.

## In this section

- [Multi-Site](Advanced-Multi-Site) - Site scoping across the ecosystem
- [Rate Limiting](Advanced-Rate-Limiting) - The named public rate-limit buckets
- [Trusted Proxies](Advanced-Trusted-Proxies) - **Required** before public routes face real traffic
- [Webhook Security (SSRF)](Advanced-Webhook-Security) - The outbound URL guard
- [GDPR, Retention & Erasure](Advanced-Gdpr-Data-Retention) - Retention pruning and right-to-erasure
- [Artisan Commands](Advanced-Artisan-Commands) - Every `bookings:*` command
- [Performance](Advanced-Performance) - Targets and benchmarks

## Before you go live

Two of these are not optional reading for a public installation:

1. [Trusted Proxies](Advanced-Trusted-Proxies) — without it, every customer shares one rate-limit bucket behind a load balancer.
2. [Webhook Security](Advanced-Webhook-Security) — the SSRF guard and what to do when you accept endpoint URLs below operator trust.
