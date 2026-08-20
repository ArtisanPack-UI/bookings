/**
 * Vue binding for the framework-agnostic booking flow.
 *
 * The composable counterpart of the React `useBookingFlow`: it builds a
 * {@link createBookingFlow} once, mirrors its snapshots into a `shallowRef` the
 * template reacts to, starts it on mount, and unsubscribes on unmount.
 *
 * @packageDocumentation
 */

import { onMounted, onUnmounted, shallowRef, type ShallowRef } from 'vue';

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
 * Drives a booking flow from a Vue component.
 *
 * @param options - The client or base URL, and the pinning, zone, and locale.
 * @returns The reactive snapshot and the flow driving it.
 */
export function useBookingFlow(
	options: UseBookingFlowOptions,
): { state: ShallowRef<BookingFlowState>; flow: BookingFlow } {
	const client = options.client ?? createBookingsClient({ baseUrl: options.baseUrl ?? '' });

	const flow = createBookingFlow({
		client,
		pinnedServiceSlug: options.service ?? null,
		timezone: options.timezone,
		locale: options.locale,
		onBooked: options.onBooked,
	});

	const state = shallowRef<BookingFlowState>(flow.getState());
	const unsubscribe = flow.subscribe(() => {
		state.value = flow.getState();
	});

	onMounted(() => {
		void flow.start();
	});

	onUnmounted(() => {
		unsubscribe();
	});

	return { state, flow };
}
