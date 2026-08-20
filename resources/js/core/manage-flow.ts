/**
 * The framework-agnostic self-serve manage flow.
 *
 * The page behind the link in a confirmation email: what was booked, a way out
 * of it, and a way to move it. Like {@link createBookingFlow}, this is a plain
 * state machine over the typed {@link BookingsClient} — the React and Vue
 * `ManageBooking` components only draw it.
 *
 * The manage token is the whole credential and the whole state: it is passed in
 * once and threaded through every call. What the customer may still do —
 * cancel, reschedule — is read from the `meta` the endpoints return, never
 * reimplemented here, so this flow can never offer an action the backend will
 * refuse.
 *
 * @packageDocumentation
 */

import { BookingsApiError, type BookingsClient, type ValidationErrors } from './api-client.js';
import { captureTimezone } from './timezone.js';
import type { Booking, BookingManageMeta } from './types.js';

/**
 * The view the manage flow is showing.
 */
export type ManageView = 'loading' | 'view' | 'reschedule' | 'cancelled' | 'error';

/**
 * An immutable snapshot of the manage flow.
 */
export interface ManageFlowState {
	/**
	 * The view being shown.
	 */
	view: ManageView;

	/**
	 * The IANA zone times are rendered in.
	 */
	timezone: string;

	/**
	 * Whether a request is in flight.
	 */
	loading: boolean;

	/**
	 * The booking, once it has loaded.
	 */
	booking: Booking | null;

	/**
	 * What may still be done to the booking.
	 */
	meta: BookingManageMeta | null;

	/**
	 * Field-keyed validation messages from the last refused action.
	 */
	errors: ValidationErrors;

	/**
	 * A general error not tied to a field.
	 */
	error: string | null;
}

/**
 * How a {@link ManageFlow} is built.
 */
export interface ManageFlowOptions {
	/**
	 * The client the flow drives.
	 */
	client: BookingsClient;

	/**
	 * The opaque manage token from the customer's confirmation link.
	 */
	token: string;

	/**
	 * The IANA zone to render times in. Defaults to the browser's.
	 */
	timezone?: string;

	/**
	 * The BCP 47 locale to format times with. Defaults to the runtime's.
	 */
	locale?: string;
}

/**
 * The manage flow's action surface.
 */
export interface ManageFlow {
	getState(): ManageFlowState;
	subscribe(listener: () => void): () => void;
	load(): Promise<void>;
	startReschedule(): void;
	cancelReschedule(): void;
	reschedule(startTime: string): Promise<void>;
	cancel(reason?: string): Promise<void>;
}

/**
 * The locale a manage flow formats times with, exposed for its widgets.
 */
export interface ManageFlowMeta {
	locale: string | undefined;
}

/**
 * Creates a manage flow bound to one client and token.
 *
 * The flow starts on `loading`; call {@link ManageFlow.load} to fetch the
 * booking. Everything after is driven by the customer through the actions.
 *
 * @param options - The client, token, and zone and locale choices.
 * @returns The flow.
 */
export function createManageFlow(options: ManageFlowOptions): ManageFlow & ManageFlowMeta {
	const { client, token } = options;
	const timezone =
		typeof options.timezone === 'string' && options.timezone.trim() !== ''
			? options.timezone
			: captureTimezone();

	const listeners = new Set<() => void>();

	let state: ManageFlowState = {
		view: 'loading',
		timezone,
		loading: false,
		booking: null,
		meta: null,
		errors: {},
		error: null,
	};

	function notify(): void {
		for (const listener of listeners) {
			listener();
		}
	}

	function setState(patch: Partial<ManageFlowState>): void {
		state = { ...state, ...patch };
		notify();
	}

	function messageFor(error: unknown): string {
		if (error instanceof BookingsApiError || error instanceof Error) {
			return error.message;
		}

		return 'Something went wrong. Please try again.';
	}

	/**
	 * Turns a refused action into either field errors or a general message.
	 *
	 * A 422 is the reschedule form's own problem to show inline; a 403, 409, or
	 * 503 is about the booking as a whole — it changed under the customer — so
	 * it reloads to show them the truth alongside the message.
	 */
	async function handleActionError(error: unknown): Promise<void> {
		if (error instanceof BookingsApiError) {
			if (error.isValidation && error.errors !== undefined) {
				setState({ loading: false, errors: error.errors });

				return;
			}

			const message = error.message;

			setState({ loading: false, error: message, errors: {} });

			if (error.isForbidden || error.isConflict || error.isBusy) {
				// The booking changed under the customer — reload it, then restate
				// the message, since `load` clears it on its way in.
				await flow.load();
				setState({ error: message });
			}

			return;
		}

		setState({ loading: false, error: messageFor(error), errors: {} });
	}

	const flow: ManageFlow & ManageFlowMeta = {
		locale: options.locale,

		getState(): ManageFlowState {
			return state;
		},

		subscribe(listener: () => void): () => void {
			listeners.add(listener);

			return () => {
				listeners.delete(listener);
			};
		},

		async load(): Promise<void> {
			setState({ loading: true, error: null, errors: {} });

			try {
				const managed = await client.getManagedBooking(token);

				setState({
					loading: false,
					view: 'view',
					booking: managed.data,
					meta: managed.meta,
				});
			} catch (error) {
				setState({
					loading: false,
					view: 'error',
					error: messageFor(error),
				});
			}
		},

		startReschedule(): void {
			if (state.meta?.can_reschedule !== true) {
				return;
			}

			setState({ view: 'reschedule', errors: {}, error: null });
		},

		cancelReschedule(): void {
			setState({ view: 'view', errors: {}, error: null });
		},

		async reschedule(startTime: string): Promise<void> {
			setState({ loading: true, errors: {}, error: null });

			try {
				const managed = await client.rescheduleBooking(token, { startTime });

				setState({
					loading: false,
					view: 'view',
					booking: managed.data,
					meta: managed.meta,
				});
			} catch (error) {
				await handleActionError(error);
			}
		},

		async cancel(reason?: string): Promise<void> {
			setState({ loading: true, errors: {}, error: null });

			try {
				const managed = await client.cancelBooking(token, { reason: reason ?? null });

				setState({
					loading: false,
					view: 'cancelled',
					booking: managed.data,
					meta: managed.meta,
				});
			} catch (error) {
				await handleActionError(error);
			}
		},
	};

	return flow;
}
