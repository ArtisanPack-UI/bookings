---
title: Vue Components
---

# Vue Components

Install the package and Vue (an optional peer dependency):

```bash
npm install @artisanpack-ui/bookings-js vue
```

Everything imports from the `/vue` subpath:

```ts
import { BookingWidget, ManageBooking } from '@artisanpack-ui/bookings-js/vue';
```

The Vue bindings expose the same components as [React](Frontend-React), backed by the same framework-agnostic core flow, so props and behaviour match one-for-one — the hooks are composables here.

## `BookingWidget`

```vue
<script setup lang="ts">
import { BookingWidget } from '@artisanpack-ui/bookings-js/vue';
</script>

<template>
    <BookingWidget
        base-url="/api"
        service="discovery-call"
        timezone="America/Chicago"
        @booked="onBooked"
    />
</template>
```

The prop shape is `UseBookingFlowOptions`:

| Prop | Type | Meaning |
| --- | --- | --- |
| `baseUrl` | `string` | Base URL of the public JSON API |
| `client` | `BookingsClient` | A pre-built client, instead of `baseUrl` |
| `service` | `string \| null` | Pin the widget to one service slug |
| `timezone` | `string` | Zone to render times in |
| `locale` | `string` | Locale for date/time formatting |
| `onBooked` | `( booking: Booking ) => void` | Called when a booking is confirmed |

## `ManageBooking`

```vue
<ManageBooking base-url="/api" :token="token" />
```

## Step components & composables

The step components — `AvailabilityCalendar`, `ProviderPicker`, `IntakeForm` — and the composables `useBookingFlow` and `useManageBooking` are exported for custom layouts:

```ts
import { useBookingFlow } from '@artisanpack-ui/bookings-js/vue';

const flow = useBookingFlow( { baseUrl: '/api', service: 'discovery-call' } );
```

Both composables take the same options object as `BookingWidget` (`UseBookingFlowOptions` / `UseManageBookingOptions`).

## The client

To share one client across components, build a `BookingsClient` and pass it as `client` — see [Headless Client](Frontend-Headless-Client).
