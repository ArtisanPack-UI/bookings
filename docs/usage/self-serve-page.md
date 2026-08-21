---
title: Self-Serve Management Page
---

# Self-Serve Management Page

The [manage endpoints](Usage-Manage-Tokens) are the machine-facing half of the manage link, and a customer should not have to be one. Mount the page on a route carrying the `bookings.manage-token` middleware and drop the component on it:

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

Give it the same two limiters `GET api/bookings/manage/{token}` carries, in that order — they bound different abuses, one per address and one per token, and the resolver is declared last so a guess is counted before it costs a lookup. A page mounted behind the resolver alone is a weaker door onto the same booking than the endpoint it replaces.

```blade
<livewire:artisanpack-manage-booking />
```

## What it does

It shows the appointment, cancels it behind a confirmation step with an optional reason, and moves it to another slot on the same service and provider. The token is read from the route — pass it explicitly with `:token="$token"` where the route names it something else — and is `#[Locked]`, so a modified client cannot point the page at a booking whose token it has guessed.

The booking is re-resolved from that token on every request rather than held across them, so a page left open on a phone reflects a cancellation made from anywhere else. Which buttons are drawn comes from the same policy the endpoints enforce — `cancellation.allowed`, `cancellation.min_advance_minutes`, and whether the service is still active — so the page never offers something the write behind it would refuse. A withdrawn service stops the reschedule and leaves the cancel.

Both writes go through `BookingService` with `actor: customer`, exactly as the endpoints do, and the slots on offer are resolved through `SlotResolver` — so `ap.bookings.availableSlots` and `ap.bookings.slotBookable` decide what a customer may pick here too. Both actions spend the same `public.rate_limits.post` bucket as `POST api/bookings`.

## Security note: the token is in the rendered markup

Livewire serialises a component's public properties into the page, so **the plain manage token is in the rendered markup and in every update payload, whichever way it was passed in**. On the route above that is the same secret in two places on one page. Passing it as `:token="$token"` — from a POST body, a session value, anywhere but the URL — keeps it out of the *address*, and does not keep it out of the *response*: there is no mounting style that does.

Weigh that where something records the DOM, since session replay and error reporters capture markup that referrer policies and URL-stripping rules never touch.

## Markup & requirements

Like the widget, the markup is plain HTML with daisyUI class names and publishes with `php artisan vendor:publish --tag=bookings-views`. Unlike the widget, it needs Livewire: its writes have no plain-form route to post to, and the JSON endpoints are what a bespoke page should be built on instead — see the [REST API](Api-Rest-Api) and the React/Vue [`ManageBooking`](Frontend) component.
