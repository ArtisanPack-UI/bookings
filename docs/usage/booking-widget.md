---
title: Public Booking Widget
---

# Public Booking Widget

Drop the widget on any page:

```blade
<livewire:artisanpack-booking-widget />
```

It walks the customer through service → provider → date and time → details, and confirms the booking in place. Pin it to one service when the page is already about that service, and give it a zone to render times in before the browser has reported its own:

```blade
<livewire:artisanpack-booking-widget service="discovery-call" />
<livewire:artisanpack-booking-widget timezone="Europe/Berlin" />
```

A pinned service is locked: the widget will not book anything else, whatever the page's query string or a modified client asks for.

The component is registered only when `livewire/livewire` is installed. It is a suggestion rather than a requirement — the JSON API and the iCal feeds are the whole surface a headless installation needs — so `composer require livewire/livewire` if you want the widget.

## It works without JavaScript

Every step is a real `<form>`: choosing a service, a provider, a month, a day, or a time is a `GET` back to the same page carrying the choice in the query string, and confirming is a `POST` to `bookings/widget`, which creates the booking and redirects back with the confirmation flashed. Where Livewire has loaded it intercepts all of that and nothing navigates.

What JavaScript adds is the one thing the server cannot know: the visitor's timezone, read from `Intl.DateTimeFormat().resolvedOptions().timeZone`. Without it the times are shown in the service's own zone, and the widget says so on screen rather than leaving it to be guessed.

That `POST` route sits in the `web` middleware group — it needs the session and the CSRF token the JSON API deliberately does without — and redirects to the session's previous URL rather than to anything in the payload.

A host whose booking flow never renders this widget — one routing bookings through its own forms — can stop that route registering with `public.widgetEnabled`:

```php
'public' => [
    'widgetEnabled' => false,
],
```

Off, only the widget's session-backed `POST {public.route_prefix}/widget` target stops mounting. The JSON API, the iCal feeds, and the manage endpoints on the same public surface carry on unchanged.

## Deep-linkable state

Because the state lives in the query string, a link is shareable and deep-linkable:

```text
https://example.test/book?bookingService=discovery-call&bookingDate=2026-06-01
```

The URL-bound query params are `bookingService`, `bookingProvider`, `bookingMonth`, `bookingDate`, and `bookingSlot`.

## Intake step

The intake step renders the service's current `intake_schema` — the same field list, in the same order, with the same idea of "required" that `Services\IntakeFieldValidator` will judge the answers against, so the form cannot ask for something the check does not want or omit something it does.

## Markup & rate limiting

The markup is plain HTML with daisyUI class names and no dependency on `artisanpack-ui/livewire-ui-components`. Publish it to change anything:

```bash
php artisan vendor:publish --tag=bookings-views
```

Both halves of the widget spend the same `public.rate_limits.post` bucket as `POST api/bookings`, so a visitor gets one allowance rather than one per route. See [Rate Limiting](Advanced-Rate-Limiting) — and configure [Trusted Proxies](Advanced-Trusted-Proxies) before this goes in front of real traffic.

## React & Vue

The same flow ships as prebuilt React and Vue components for apps that own their frontend. See [Frontend Overview](Frontend).
