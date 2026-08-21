---
title: FAQ
---

# Frequently Asked Questions

## Do I need Livewire?

Only for the public booking widget and the admin screens. A headless installation using the [REST API](Api-Rest-Api) and the [iCal feeds](Usage-Ical-Feeds) needs nothing beyond the core dependencies. Install it with `composer require livewire/livewire` when you want the widget.

## Does the widget work without JavaScript?

Yes. Every step is a real `<form>` — choosing a service, provider, month, day, or time is a `GET` carrying the choice in the query string, and confirming is a `POST`. JavaScript only adds the visitor's timezone. See [Public Booking Widget](Usage-Booking-Widget).

## Can two customers book the same slot?

No. Booking creation runs behind an advisory lock on the provider's local day, and a partial unique index catches anything that slips the lock — the round-robin assigner then falls through to the next free provider. On SQLite the lock falls back to the cache store; point `artisanpack.bookings.lock.store` at a shared store if you run more than one app server. See [Creating a Booking](Usage-Creating-Bookings).

## Which calendars can I sync with?

iCal feeds (subscribable, no account) ship in the box. Two-way Google sync ships here, gated on `artisanpack-ui/google`. Microsoft/Outlook and Apple sync come from the companion `artisanpack-ui/microsoft` and `artisanpack-ui/apple` packages. See [Calendar Sync](Integrations-Calendar-Sync).

## How do customers manage a booking without an account?

Through a manage token — a hashed, single-credential link that lands in their confirmation email. Set `ARTISANPACK_BOOKINGS_MANAGE_URL` so the link is generated, and mount the [self-serve page](Usage-Self-Serve-Page) or build against the [manage endpoints](Api-Rest-Api).

## Can I send SMS reminders?

Yes, but it is opt-in and needs a gateway driver — the package never sends a paid text on your behalf. Add `sms` to `notifications.channels` and set `notifications.sms_driver`. Read the SMS-pumping and logging warnings first. See [Text Messages](Notifications-Sms).

## How do I extend or change behaviour?

Filters change behaviour; actions observe it. There are also six [contracts](Api-Contracts) you can rebind. See [Hooks & Filters](Api-Hooks).

## Is the package multi-site aware?

Yes. Every owned table carries a nullable `site_id` and scopes to the current site through `artisanpack-ui/core`. Configure it in `artisanpack.core.multi_tenant`, not this package's config. See [Multi-Site](Advanced-Multi-Site).

## How is personal data handled?

Retention prunes soft-delete old bookings (keeping the record); a request-driven erase command scrubs a person's data on demand. There is no export command. See [GDPR, Retention & Erasure](Advanced-Gdpr-Data-Retention).

## Is there a React / Vue version?

Yes — `@artisanpack-ui/bookings-js` ships the same flow as React and Vue components on one framework-agnostic client. See [Frontend Overview](Frontend).

## What Laravel and PHP versions are supported?

PHP 8.2+ and Laravel 11, 12, or 13 (Laravel 13 needs PHP 8.3+). See [Requirements](Installation-Requirements).
