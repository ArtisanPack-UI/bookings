---
title: React Components
---

# React Components

Install the package and React (an optional peer dependency):

```bash
npm install @artisanpack-ui/bookings-js react react-dom
```

Everything imports from the `/react` subpath:

```tsx
import { BookingWidget, ManageBooking } from '@artisanpack-ui/bookings-js/react';
```

## `BookingWidget`

The full customer booking flow — service → provider → date/time → details — as one component:

```tsx
import { BookingWidget } from '@artisanpack-ui/bookings-js/react';

function BookPage() {
    return (
        <BookingWidget
            baseUrl="/api"
            service="discovery-call"
            timezone="America/Chicago"
            onBooked={ ( booking ) => console.log( 'Booked', booking.booking_number ) }
        />
    );
}
```

`BookingWidgetProps` is an alias of `UseBookingFlowOptions`:

| Prop | Type | Meaning |
| --- | --- | --- |
| `baseUrl` | `string` | Base URL of the public JSON API (e.g. `/api`) |
| `client` | `BookingsClient` | A pre-built client, instead of `baseUrl` |
| `service` | `string \| null` | Pin the widget to one service slug |
| `timezone` | `string` | Zone to render times in |
| `locale` | `string` | Locale for date/time formatting |
| `onBooked` | `( booking: Booking ) => void` | Called when a booking is confirmed |

## `ManageBooking`

The self-serve manage/reschedule/cancel flow, keyed by a customer's manage token:

```tsx
import { ManageBooking } from '@artisanpack-ui/bookings-js/react';

<ManageBooking baseUrl="/api" token={ token } />;
```

## Step components

For a custom layout, the individual steps are exported too — `AvailabilityCalendar`, `ProviderPicker`, and `IntakeForm`, each with a matching `…Props` type.

## Hooks

`useBookingFlow` and `useManageBooking` drive the same flows behind a headless hook, so you can build an entirely custom UI on the package's state machine:

```tsx
import { useBookingFlow } from '@artisanpack-ui/bookings-js/react';

function CustomWidget() {
    const flow = useBookingFlow( { baseUrl: '/api', service: 'discovery-call' } );
    // flow exposes the current step, the available slots, and the actions to advance
}
```

Both hooks take the same options object as `BookingWidget` (`UseBookingFlowOptions` / `UseManageBookingOptions`).

## The `booking_slot` forms field

For a React host built on `artisanpack-ui/forms` `^1.5`, the package also ships a `booking_slot` field for the form builder — the React parallel of the server-side integration. Call `registerBookingSlotField` once at bootstrap with forms' field-registry seam and the bookings API base URL:

```ts
import * as forms from '@artisanpack-ui/forms';
import { registerBookingSlotField } from '@artisanpack-ui/bookings-js/react';

registerBookingSlotField( forms, { baseUrl: '/api/bookings' } );
```

It registers the field's palette group, settings panel, card preview, and public slot picker, reading and writing the same shapes the server field does — see [Forms](Integrations-Forms) for the full field and how a submission becomes a booking.

## The client

Every component ultimately talks to `BookingsClient`. Build one yourself and pass it as `client` to share it across components — see [Headless Client](Frontend-Headless-Client).
