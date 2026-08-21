---
title: Headless Client
---

# Headless Client

The framework-agnostic core is the root export of the npm package. It carries the typed API client, the date and timezone helpers, and the booking- and manage-flow state machines the React and Vue widgets are built on — so you can drive a fully custom UI, or a non-UI integration, against the same public JSON API.

```bash
npm install @artisanpack-ui/bookings-js
```

```ts
import { createBookingsClient } from '@artisanpack-ui/bookings-js';

const client = createBookingsClient( { baseUrl: '/api' } );
```

## What the core exports

The root export (`resources/js/core/index.ts`) re-exports:

| Export | What it is |
| --- | --- |
| `createBookingsClient` / `BookingsClient` | The typed client over the public JSON API |
| `types` | TypeScript types for services, providers, slots, and bookings |
| `timezone` | Timezone detection and conversion helpers |
| `date-utils` | Date formatting and range helpers |
| `booking-flow` | The booking state machine `useBookingFlow` wraps |
| `manage-flow` | The manage state machine `useManageBooking` wraps |

## Using the client

The client mirrors the [REST API](Api-Rest-Api): list services, list a service's providers, resolve slots, create a booking, and drive the manage endpoints by token.

```ts
const client = createBookingsClient( { baseUrl: '/api' } );

const services  = await client.listServices();
const providers = await client.listProviders( 'discovery-call' );
const slots     = await client.listSlots( 'discovery-call', { from, until, timezone } );

const booking = await client.createBooking( {
    serviceSlug: 'discovery-call',
    startTime: slot.start_time,
    customerName: 'Sam Rivera',
    customerEmail: 'sam@example.test',
} );

// Manage by token
const managed = await client.getManagedBooking( token );
await client.cancelBooking( token, { reason: 'Something came up.' } );
await client.rescheduleBooking( token, { startTime } );
```

`createBookingsClient( { baseUrl, fetch? } )` returns a client with these methods: `listServices()`, `listProviders( serviceSlug )`, `listSlots( serviceSlug, query )`, `createBooking( payload )`, `getManagedBooking( token )`, `cancelBooking( token, payload )`, and `rescheduleBooking( token, payload )`. The `CreateBookingPayload` shape is `serviceSlug`, `startTime`, `customerName`, `customerEmail`, plus optional `providerId`, `customerPhone`, `customerTimezone`, `notes`, and `intakeData`. Consult the shipped `.d.ts` for the exact signatures, which are the authoritative contract.

## When to reach for it

- A bespoke booking UI in a framework the package does not ship bindings for.
- A server-side or CLI integration that books without a browser.
- Sharing one configured client across many React/Vue components — build it once and pass it as the `client` prop.

For the HTTP contract behind the client, see the [REST API](Api-Rest-Api).
