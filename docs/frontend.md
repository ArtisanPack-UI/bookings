---
title: Frontend Overview
---

# Frontend (React & Vue)

The Livewire widget is one way to render the flow; the package also ships the same flow as prebuilt React and Vue components for apps that own their own frontend. Both frameworks are built on one framework-agnostic `core` — the typed API client and the date and timezone helpers — so they talk to the same public JSON API and behave identically.

The customer-facing `BookingWidget` and the self-serve `ManageBooking` come ready to drop on a page; the `useBookingFlow` and `useManageBooking` hooks (composables, in Vue) wire a custom layout to the same flow.

## In this section

- [React Components](Frontend-React) - `BookingWidget`, `ManageBooking`, hooks
- [Vue Components](Frontend-Vue) - The same components as Vue composables
- [Headless Client](Frontend-Headless-Client) - The framework-agnostic `BookingsClient`

## Two ways to consume it

### Install from npm

The package publishes to the `@artisanpack-ui` scope as `@artisanpack-ui/bookings-js`, with the framework bindings behind subpath exports and React and Vue as optional peer dependencies — install only the one your app uses:

```bash
npm install @artisanpack-ui/bookings-js
```

```tsx
import { BookingWidget } from '@artisanpack-ui/bookings-js/react';

<BookingWidget baseUrl="/api" service="discovery-call" />;
```

```ts
import { BookingWidget } from '@artisanpack-ui/bookings-js/vue';
// register BookingWidget in a component and pass the same props
```

The self-serve page takes the manage token from the customer's confirmation link, and the framework-agnostic client is available from the root export for a headless integration:

```tsx
import { ManageBooking } from '@artisanpack-ui/bookings-js/react';
import { createBookingsClient } from '@artisanpack-ui/bookings-js';

<ManageBooking baseUrl="/api" token={token} />;

const client = createBookingsClient({ baseUrl: '/api' });
```

### Copy the source

Teams that would rather vendor the widgets than take a dependency can copy the files under `resources/js/{core,react,vue}` from the `bookings-js` git tag straight into their app and compile them with their own toolchain. The tag is the supported snapshot for this path — pin to it rather than to a moving branch — and the source is plain TypeScript with no build step of its own to reproduce.

## The subpath exports

The npm package is ESM-only and ships three subpath entry points:

| Import | Source | What it is |
| --- | --- | --- |
| `@artisanpack-ui/bookings-js` | `resources/js/core` | The framework-agnostic client, types, and date/timezone helpers |
| `@artisanpack-ui/bookings-js/react` | `resources/js/react` | React components and hooks |
| `@artisanpack-ui/bookings-js/vue` | `resources/js/vue` | Vue components and composables |

React (`^18 || ^19`) and Vue (`^3.5`) are optional `peerDependencies` — install only the one your app uses.

## Version coupling

The npm package's `major.minor` tracks the composer package's `major.minor` 1:1 — `@artisanpack-ui/bookings-js@1.2.x` targets `artisanpack-ui/bookings ^1.2` — so a change to the public API moves both together. The `patch` may diverge for JS-only fixes that need no PHP change.

## Building from source

```bash
npm install
npm test           # Vitest — core, React, Vue, and a build smoke test
npm run build      # Vite ESM bundles + tsc declarations, into dist/
```

`npm run build` produces the three subpath entrypoints — `dist/core/index.js`, `dist/react/index.js`, `dist/vue/index.js`, each with a matching `.d.ts` — that the `exports` map ships, and `prepublishOnly` runs it before every publish. A Vitest smoke test compiles the whole library through the real Vite config, so a broken entry or an unresolved import fails `npm test` rather than `npm publish`.
