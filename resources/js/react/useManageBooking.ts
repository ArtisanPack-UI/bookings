/**
 * React binding for the framework-agnostic manage flow.
 *
 * The manage-side counterpart of {@link useBookingFlow}: a `useSyncExternalStore`
 * skin over {@link createManageFlow} that builds the flow once, loads the
 * booking on mount, and hands back the snapshot and the flow.
 *
 * @packageDocumentation
 */

import { useEffect, useMemo, useSyncExternalStore } from 'react';

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
 * Drives a manage flow from a React component.
 *
 * @param options - The token, the client or base URL, and zone and locale.
 * @returns The current snapshot and the flow driving it.
 */
export function useManageBooking(
	options: UseManageBookingOptions,
): { state: ManageFlowState; flow: ManageFlow & ManageFlowMeta } {
	const { token, client, baseUrl, timezone, locale } = options;

	const flow = useMemo(
		() => {
			const resolved = client ?? createBookingsClient({ baseUrl: baseUrl ?? '' });

			return createManageFlow({ client: resolved, token, timezone, locale });
		},
		[client, baseUrl, token, timezone, locale],
	);

	useEffect(() => {
		void flow.load();
	}, [flow]);

	const state = useSyncExternalStore(flow.subscribe, flow.getState, flow.getState);

	return { state, flow };
}
