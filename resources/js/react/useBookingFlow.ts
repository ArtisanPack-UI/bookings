/**
 * React binding for the framework-agnostic booking flow.
 *
 * A thin `useSyncExternalStore` skin over {@link createBookingFlow}: the flow
 * holds the state and the logic, and this hook only re-renders the component
 * when the flow says its snapshot changed. It builds the flow once, starts it
 * on mount, and exposes the current snapshot alongside the flow's actions.
 *
 * @packageDocumentation
 */

import { useEffect, useMemo, useSyncExternalStore } from 'react';

import {
	type BookingFlow,
	type BookingFlowState,
	type BookingsClient,
	createBookingFlow,
	createBookingsClient,
} from '../core/index.js';
import type { Booking } from '../core/index.js';

/**
 * How {@link useBookingFlow} is configured.
 *
 * Either an existing {@link BookingsClient} or the `baseUrl` to build one is
 * required; everything else is optional.
 */
export interface UseBookingFlowOptions {
	/**
	 * A ready-made client. Takes precedence over `baseUrl`.
	 */
	client?: BookingsClient;

	/**
	 * The public API base URL, used to build a client when none is given.
	 */
	baseUrl?: string;

	/**
	 * The slug of the service to pin the flow to.
	 */
	service?: string | null;

	/**
	 * The IANA zone to render times in. Defaults to the browser's.
	 */
	timezone?: string;

	/**
	 * The BCP 47 locale to format times with.
	 */
	locale?: string;

	/**
	 * Called with the booking once it is made.
	 */
	onBooked?: (booking: Booking) => void;
}

/**
 * Drives a booking flow from a React component.
 *
 * @param options - The client or base URL, and the pinning, zone, and locale.
 * @returns The current snapshot and the flow driving it.
 */
export function useBookingFlow(
	options: UseBookingFlowOptions,
): { state: BookingFlowState; flow: BookingFlow } {
	const { client, baseUrl, service, timezone, locale, onBooked } = options;

	const flow = useMemo(
		() => {
			const resolved =
				client ??
				createBookingsClient({ baseUrl: baseUrl ?? '' });

			return createBookingFlow({
				client: resolved,
				pinnedServiceSlug: service ?? null,
				timezone,
				locale,
				onBooked,
			});
			// The flow is deliberately rebuilt only when the connection or the
			// pinning changes; `onBooked` is read through the closure so a new
			// function identity each render does not tear the whole flow down.
			// eslint-disable-next-line react-hooks/exhaustive-deps
		},
		[client, baseUrl, service, timezone, locale],
	);

	useEffect(() => {
		void flow.start();
	}, [flow]);

	const state = useSyncExternalStore(flow.subscribe, flow.getState, flow.getState);

	return { state, flow };
}
