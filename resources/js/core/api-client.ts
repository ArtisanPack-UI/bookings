/**
 * Typed client for the bookings public API.
 *
 * A thin, framework-agnostic wrapper over `fetch`: it knows the routes from
 * `routes/public.php`, the envelopes the resources wrap their data in, and the
 * three failure codes the write endpoints distinguish (409, 422, 503). It holds
 * no state and no framework — a React hook or a Vue composable builds one and
 * drives it.
 *
 * @packageDocumentation
 */

import type {
	Booking,
	CancelBookingPayload,
	CreateBookingPayload,
	ManagedBooking,
	Provider,
	RescheduleBookingPayload,
	Service,
	Slot,
	SlotQuery,
} from './types.js';

/**
 * Field-keyed validation messages, as Laravel returns them on a 422.
 */
export type ValidationErrors = Record<string, string[]>;

/**
 * The fetch implementation the client calls.
 *
 * Defaults to the global `fetch`; injectable so the client can run under a
 * runtime without one, or against a stub in tests.
 */
export type FetchLike = (
	input: string,
	init?: RequestInit,
) => Promise<Response>;

/**
 * How a {@link BookingsClient} is configured.
 */
export interface BookingsClientOptions {
	/**
	 * The public API's base URL, e.g. `https://example.test/api/bookings`.
	 * A trailing slash is tolerated.
	 */
	baseUrl: string;

	/**
	 * The fetch implementation to use. Defaults to the global `fetch`.
	 */
	fetch?: FetchLike;

	/**
	 * Extra headers sent on every request, e.g. a locale or a custom token.
	 */
	headers?: Record<string, string>;
}

/**
 * An error raised when the API answers with a non-2xx status.
 *
 * The status is exposed directly, and the convenience flags name the three
 * outcomes the booking write endpoints deliberately keep apart: a 422 means
 * fix the submission, a 409 means the slot is gone, a 503 means retry as-is.
 */
export class BookingsApiError extends Error {
	/**
	 * The HTTP status the API answered with.
	 */
	public readonly status: number;

	/**
	 * The field-keyed validation messages, present on a 422.
	 */
	public readonly errors?: ValidationErrors;

	/**
	 * The parsed response body, when there was one.
	 */
	public readonly body?: unknown;

	public constructor(
		message: string,
		status: number,
		errors?: ValidationErrors,
		body?: unknown,
	) {
		super(message);
		this.name = 'BookingsApiError';
		this.status = status;
		this.errors = errors;
		this.body = body;
	}

	/**
	 * Whether the submission was rejected on its merits (HTTP 422).
	 */
	public get isValidation(): boolean {
		return this.status === 422;
	}

	/**
	 * Whether the slot was taken by someone else (HTTP 409).
	 */
	public get isConflict(): boolean {
		return this.status === 409;
	}

	/**
	 * Whether the slot was busy and the request should be retried (HTTP 503).
	 */
	public get isBusy(): boolean {
		return this.status === 503;
	}

	/**
	 * Whether the action is not offered at all (HTTP 403).
	 */
	public get isForbidden(): boolean {
		return this.status === 403;
	}
}

/**
 * The client's method surface.
 */
export interface BookingsClient {
	listServices(): Promise<Service[]>;
	listProviders(serviceSlug: string): Promise<Provider[]>;
	listSlots(serviceSlug: string, query: SlotQuery): Promise<Slot[]>;
	createBooking(payload: CreateBookingPayload): Promise<Booking>;
	getManagedBooking(token: string): Promise<ManagedBooking>;
	cancelBooking(
		token: string,
		payload?: CancelBookingPayload,
	): Promise<ManagedBooking>;
	rescheduleBooking(
		token: string,
		payload: RescheduleBookingPayload,
	): Promise<ManagedBooking>;
}

/**
 * Builds a message from a parsed error body, falling back to the status text.
 */
function errorMessage(body: unknown, response: Response): string {
	if (
		body !== null &&
		typeof body === 'object' &&
		'message' in body &&
		typeof (body as { message: unknown }).message === 'string'
	) {
		return (body as { message: string }).message;
	}

	return response.statusText || `Request failed with status ${response.status}`;
}

/**
 * Pulls the validation errors out of a parsed error body, if present.
 */
function validationErrors(body: unknown): ValidationErrors | undefined {
	if (
		body !== null &&
		typeof body === 'object' &&
		'errors' in body &&
		typeof (body as { errors: unknown }).errors === 'object' &&
		(body as { errors: unknown }).errors !== null
	) {
		return (body as { errors: ValidationErrors }).errors;
	}

	return undefined;
}

/**
 * Parses a response body as JSON, tolerating an empty or non-JSON body.
 *
 * A non-2xx response is not guaranteed to be JSON — a 500 can be an HTML error
 * page and a gateway timeout can be plain text. Parsing must not throw its own
 * `SyntaxError` there, or it would escape the {@link BookingsApiError} a caller
 * is watching for; an unparseable body is simply treated as absent.
 */
function parseJson(text: string): unknown {
	if (text === '') {
		return null;
	}

	try {
		return JSON.parse(text);
	} catch {
		return null;
	}
}

/**
 * Drops keys whose value is `undefined`, so an unset optional is omitted from
 * the request body rather than sent as `null`.
 */
function compact(body: Record<string, unknown>): Record<string, unknown> {
	return Object.fromEntries(
		Object.entries(body).filter(([, value]) => value !== undefined),
	);
}

/**
 * Creates a client bound to one public API base URL.
 *
 * @param options - The base URL and optional fetch and headers.
 * @returns A client whose methods each resolve to typed API data or throw a
 *          {@link BookingsApiError}.
 */
export function createBookingsClient(
	options: BookingsClientOptions,
): BookingsClient {
	const base = options.baseUrl.replace(/\/+$/, '');
	const fetchImpl = options.fetch ?? globalThis.fetch;

	if (typeof fetchImpl !== 'function') {
		throw new TypeError(
			'No fetch implementation available; pass one via options.fetch.',
		);
	}

	async function request<T>(
		path: string,
		init?: RequestInit,
	): Promise<T> {
		const response = await fetchImpl(`${base}${path}`, {
			...init,
			headers: {
				Accept: 'application/json',
				'X-Requested-With': 'XMLHttpRequest',
				...(init?.body ? { 'Content-Type': 'application/json' } : {}),
				...options.headers,
				...init?.headers,
			},
		});

		const text = await response.text();
		const body: unknown = parseJson(text);

		if (!response.ok) {
			throw new BookingsApiError(
				errorMessage(body, response),
				response.status,
				validationErrors(body),
				body,
			);
		}

		return body as T;
	}

	function post<T>(path: string, payload: Record<string, unknown>): Promise<T> {
		return request<T>(path, {
			method: 'POST',
			body: JSON.stringify(compact(payload)),
		});
	}

	return {
		async listServices(): Promise<Service[]> {
			const body = await request<{ data: Service[] }>('/services');

			return body.data;
		},

		async listProviders(serviceSlug: string): Promise<Provider[]> {
			const body = await request<{ data: Provider[] }>(
				`/services/${encodeURIComponent(serviceSlug)}/providers`,
			);

			return body.data;
		},

		async listSlots(serviceSlug: string, query: SlotQuery): Promise<Slot[]> {
			const params = new URLSearchParams({ date: query.date });

			if (query.providerId !== undefined && query.providerId !== null) {
				params.set('provider_id', String(query.providerId));
			}

			const body = await request<{ data: Slot[] }>(
				`/services/${encodeURIComponent(serviceSlug)}/slots?${params.toString()}`,
			);

			return body.data;
		},

		async createBooking(payload: CreateBookingPayload): Promise<Booking> {
			const body = await post<{ data: Booking }>('/', {
				service_slug: payload.serviceSlug,
				start_time: payload.startTime,
				customer_name: payload.customerName,
				customer_email: payload.customerEmail,
				provider_id: payload.providerId,
				customer_phone: payload.customerPhone,
				customer_timezone: payload.customerTimezone,
				notes: payload.notes,
				intake_data: payload.intakeData,
			});

			return body.data;
		},

		getManagedBooking(token: string): Promise<ManagedBooking> {
			return request<ManagedBooking>(
				`/manage/${encodeURIComponent(token)}`,
			);
		},

		cancelBooking(
			token: string,
			payload: CancelBookingPayload = {},
		): Promise<ManagedBooking> {
			return post<ManagedBooking>(
				`/manage/${encodeURIComponent(token)}/cancel`,
				{ reason: payload.reason },
			);
		},

		rescheduleBooking(
			token: string,
			payload: RescheduleBookingPayload,
		): Promise<ManagedBooking> {
			return post<ManagedBooking>(
				`/manage/${encodeURIComponent(token)}/reschedule`,
				{ start_time: payload.startTime },
			);
		},
	};
}
