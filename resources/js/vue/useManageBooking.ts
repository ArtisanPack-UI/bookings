/**
 * Vue binding for the framework-agnostic manage flow.
 *
 * The manage-side counterpart of {@link useBookingFlow}: a `shallowRef` mirror
 * of a {@link createManageFlow}, loaded on mount and unsubscribed on unmount.
 *
 * @packageDocumentation
 */

import { onMounted, onUnmounted, shallowRef, type ShallowRef } from 'vue';

import {
	type BookingsClient,
	type ManageFlow,
	type ManageFlowMeta,
	type ManageFlowState,
	createBookingsClient,
	createManageFlow,
} from '../core/index.js';

/**
 * How {@link useManageBooking} is configured.
 */
export interface UseManageBookingOptions {
	/**
	 * The opaque manage token from the customer's confirmation link.
	 */
	token: string;

	/**
	 * A ready-made client. Takes precedence over `baseUrl`.
	 */
	client?: BookingsClient;

	/**
	 * The public API base URL, used to build a client when none is given.
	 */
	baseUrl?: string;

	/**
	 * The IANA zone to render times in. Defaults to the browser's.
	 */
	timezone?: string;

	/**
	 * The BCP 47 locale to format times with.
	 */
	locale?: string;
}

/**
 * Drives a manage flow from a Vue component.
 *
 * @param options - The token, the client or base URL, and zone and locale.
 * @returns The reactive snapshot and the flow driving it.
 */
export function useManageBooking(
	options: UseManageBookingOptions,
): { state: ShallowRef<ManageFlowState>; flow: ManageFlow & ManageFlowMeta } {
	const client = options.client ?? createBookingsClient({ baseUrl: options.baseUrl ?? '' });

	const flow = createManageFlow({
		client,
		token: options.token,
		timezone: options.timezone,
		locale: options.locale,
	});

	const state = shallowRef<ManageFlowState>(flow.getState());
	const unsubscribe = flow.subscribe(() => {
		state.value = flow.getState();
	});

	onMounted(() => {
		void flow.load();
	});

	onUnmounted(() => {
		unsubscribe();
	});

	return { state, flow };
}
