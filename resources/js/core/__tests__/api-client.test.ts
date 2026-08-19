import { describe, expect, it, vi } from 'vitest';

import {
	BookingsApiError,
	createBookingsClient,
	type FetchLike,
} from '../api-client.js';

interface Recorded {
	url: string;
	init?: RequestInit;
}

function stubFetch(
	status: number,
	body: unknown,
): { fetch: FetchLike; calls: Recorded[] } {
	const calls: Recorded[] = [];

	const fetch: FetchLike = vi.fn(async (url, init) => {
		calls.push({ url, init });

		return new Response(body === undefined ? '' : JSON.stringify(body), {
			status,
			headers: { 'Content-Type': 'application/json' },
		});
	});

	return { fetch, calls };
}

describe('createBookingsClient', () => {
	it('trims a trailing slash from the base URL', async () => {
		const { fetch, calls } = stubFetch(200, { data: [] });
		const client = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings/',
			fetch,
		});

		await client.listServices();

		expect(calls[0]?.url).toBe('https://example.test/api/bookings/services');
	});

	it('unwraps the data envelope on list endpoints', async () => {
		const { fetch } = stubFetch(200, { data: [{ id: 1, slug: 'haircut' }] });
		const client = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch,
		});

		const services = await client.listServices();

		expect(services).toEqual([{ id: 1, slug: 'haircut' }]);
	});

	it('adds provider_id to the slots query only when given', async () => {
		const { fetch, calls } = stubFetch(200, { data: [] });
		const client = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch,
		});

		await client.listSlots('haircut', { date: '2025-09' });
		await client.listSlots('haircut', { date: '2025-09', providerId: 7 });

		expect(calls[0]?.url).toBe(
			'https://example.test/api/bookings/services/haircut/slots?date=2025-09',
		);
		expect(calls[1]?.url).toBe(
			'https://example.test/api/bookings/services/haircut/slots?date=2025-09&provider_id=7',
		);
	});

	it('maps camelCase payload keys to snake_case and omits undefined', async () => {
		const { fetch, calls } = stubFetch(201, { data: { id: 99 } });
		const client = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch,
		});

		const booking = await client.createBooking({
			serviceSlug: 'haircut',
			startTime: '2025-09-01T13:00:00Z',
			customerName: 'Ada',
			customerEmail: 'ada@example.test',
		});

		expect(booking).toEqual({ id: 99 });
		expect(calls[0]?.url).toBe('https://example.test/api/bookings/');

		const sent = JSON.parse(String(calls[0]?.init?.body));
		expect(sent).toEqual({
			service_slug: 'haircut',
			start_time: '2025-09-01T13:00:00Z',
			customer_name: 'Ada',
			customer_email: 'ada@example.test',
		});
		expect(sent).not.toHaveProperty('provider_id');
	});

	it('encodes the manage token into the path', async () => {
		const { fetch, calls } = stubFetch(200, {
			data: { id: 1 },
			meta: { can_cancel: true },
		});
		const client = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch,
		});

		await client.getManagedBooking('a/b?c');

		expect(calls[0]?.url).toBe(
			'https://example.test/api/bookings/manage/a%2Fb%3Fc',
		);
	});

	it('returns the full data+meta envelope for managed bookings', async () => {
		const envelope = { data: { id: 1 }, meta: { can_cancel: false } };
		const { fetch } = stubFetch(200, envelope);
		const client = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch,
		});

		expect(await client.getManagedBooking('token')).toEqual(envelope);
	});

	it('cancels via the /cancel route with a reason body and returns the envelope', async () => {
		const envelope = { data: { id: 1 }, meta: { can_cancel: false } };
		const { fetch, calls } = stubFetch(200, envelope);
		const client = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch,
		});

		const result = await client.cancelBooking('tok', { reason: 'Changed plans' });

		expect(result).toEqual(envelope);
		expect(calls[0]?.url).toBe(
			'https://example.test/api/bookings/manage/tok/cancel',
		);
		expect(calls[0]?.init?.method).toBe('POST');
		expect(JSON.parse(String(calls[0]?.init?.body))).toEqual({
			reason: 'Changed plans',
		});
	});

	it('cancels with an empty body when no reason is given', async () => {
		const { fetch, calls } = stubFetch(200, { data: { id: 1 }, meta: {} });
		const client = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch,
		});

		await client.cancelBooking('tok');

		expect(JSON.parse(String(calls[0]?.init?.body))).toEqual({});
	});

	it('reschedules via the /reschedule route with a start_time body and returns the envelope', async () => {
		const envelope = { data: { id: 1 }, meta: { can_reschedule: true } };
		const { fetch, calls } = stubFetch(200, envelope);
		const client = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch,
		});

		const result = await client.rescheduleBooking('tok', {
			startTime: '2025-09-01T13:00:00Z',
		});

		expect(result).toEqual(envelope);
		expect(calls[0]?.url).toBe(
			'https://example.test/api/bookings/manage/tok/reschedule',
		);
		expect(calls[0]?.init?.method).toBe('POST');
		expect(JSON.parse(String(calls[0]?.init?.body))).toEqual({
			start_time: '2025-09-01T13:00:00Z',
		});
	});

	it('throws a validation error on 422 with the field messages', async () => {
		const { fetch } = stubFetch(422, {
			message: 'The given data was invalid.',
			errors: { customer_email: ['The customer email must be valid.'] },
		});
		const client = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch,
		});

		await expect(
			client.createBooking({
				serviceSlug: 'haircut',
				startTime: '2025-09-01T13:00:00Z',
				customerName: 'Ada',
				customerEmail: 'nope',
			}),
		).rejects.toMatchObject({
			status: 422,
			isValidation: true,
			errors: { customer_email: ['The customer email must be valid.'] },
		});
	});

	it('flags a 409 conflict distinctly from a 503 busy', async () => {
		const conflict = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch: stubFetch(409, { message: 'gone' }).fetch,
		});
		const busy = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch: stubFetch(503, { message: 'busy' }).fetch,
		});

		const conflictError = await conflict
			.rescheduleBooking('t', { startTime: '2025-09-01T13:00:00Z' })
			.catch((error: unknown) => error);
		const busyError = await busy
			.rescheduleBooking('t', { startTime: '2025-09-01T13:00:00Z' })
			.catch((error: unknown) => error);

		expect(conflictError).toBeInstanceOf(BookingsApiError);
		expect((conflictError as BookingsApiError).isConflict).toBe(true);
		expect((busyError as BookingsApiError).isBusy).toBe(true);
	});

	it('still throws a BookingsApiError when the error body is not JSON', async () => {
		const fetch: FetchLike = vi.fn(
			async () =>
				new Response('<html><body>Server Error</body></html>', {
					status: 500,
					statusText: 'Internal Server Error',
					headers: { 'Content-Type': 'text/html' },
				}),
		);
		const client = createBookingsClient({
			baseUrl: 'https://example.test/api/bookings',
			fetch,
		});

		const error = await client
			.listServices()
			.catch((thrown: unknown) => thrown);

		expect(error).toBeInstanceOf(BookingsApiError);
		expect((error as BookingsApiError).status).toBe(500);
		expect((error as BookingsApiError).message).toBe('Internal Server Error');
	});
});
