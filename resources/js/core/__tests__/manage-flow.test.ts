import { describe, expect, it, vi } from 'vitest';

import { BookingsApiError, type BookingsClient } from '../api-client.js';
import { createManageFlow } from '../manage-flow.js';
import type { Booking, BookingManageMeta, ManagedBooking } from '../types.js';

function booking(overrides: Partial<Booking> = {}): Booking {
	return {
		id: 99,
		status: 'confirmed',
		start_time: '2025-09-01T13:00:00Z',
		end_time: '2025-09-01T13:30:00Z',
		customer_name: 'Sam',
		customer_email: 'sam@example.test',
		customer_timezone: 'America/New_York',
		service: { id: 1, slug: 'haircut', name: 'Haircut' },
		provider: null,
		...overrides,
	};
}

function meta(overrides: Partial<BookingManageMeta> = {}): BookingManageMeta {
	return {
		can_cancel: true,
		can_reschedule: true,
		changes_allowed_until: '2025-08-31T13:00:00Z',
		...overrides,
	};
}

function managed(overrides: Partial<ManagedBooking> = {}): ManagedBooking {
	return { data: booking(), meta: meta(), ...overrides };
}

function fakeClient(overrides: Partial<BookingsClient> = {}): BookingsClient {
	return {
		listServices: vi.fn(),
		listProviders: vi.fn(),
		listSlots: vi.fn(),
		createBooking: vi.fn(),
		getManagedBooking: vi.fn(async () => managed()),
		cancelBooking: vi.fn(async () => managed({ data: booking({ status: 'cancelled' }) })),
		rescheduleBooking: vi.fn(async () => managed()),
		...overrides,
	} as unknown as BookingsClient;
}

describe('createManageFlow', () => {
	it('loads the booking onto the view', async () => {
		const flow = createManageFlow({ client: fakeClient(), token: 'tok', timezone: 'America/New_York' });

		await flow.load();

		expect(flow.getState().view).toBe('view');
		expect(flow.getState().booking?.id).toBe(99);
		expect(flow.getState().meta?.can_cancel).toBe(true);
	});

	it('shows an error view when the token cannot be resolved', async () => {
		const client = fakeClient({
			getManagedBooking: vi.fn(async () => {
				throw new BookingsApiError('Not found.', 404);
			}),
		});
		const flow = createManageFlow({ client, token: 'tok' });

		await flow.load();

		expect(flow.getState().view).toBe('error');
		expect(flow.getState().error).toBe('Not found.');
	});

	it('refuses to open the reschedule form when the meta forbids it', async () => {
		const client = fakeClient({
			getManagedBooking: vi.fn(async () => managed({ meta: meta({ can_reschedule: false }) })),
		});
		const flow = createManageFlow({ client, token: 'tok' });

		await flow.load();
		flow.startReschedule();

		expect(flow.getState().view).toBe('view');
	});

	it('reschedules and returns to the view with the new booking', async () => {
		const moved = managed({ data: booking({ start_time: '2025-09-02T13:00:00Z' }) });
		const client = fakeClient({ rescheduleBooking: vi.fn(async () => moved) });
		const flow = createManageFlow({ client, token: 'tok' });

		await flow.load();
		flow.startReschedule();
		expect(flow.getState().view).toBe('reschedule');

		await flow.reschedule('2025-09-02T13:00:00Z');

		expect(client.rescheduleBooking).toHaveBeenCalledWith('tok', {
			startTime: '2025-09-02T13:00:00Z',
		});
		expect(flow.getState().view).toBe('view');
		expect(flow.getState().booking?.start_time).toBe('2025-09-02T13:00:00Z');
	});

	it('surfaces a 422 from a reschedule as field errors', async () => {
		const client = fakeClient({
			rescheduleBooking: vi.fn(async () => {
				throw new BookingsApiError('Invalid.', 422, { start_time: ['Not a valid time.'] });
			}),
		});
		const flow = createManageFlow({ client, token: 'tok' });

		await flow.load();
		flow.startReschedule();
		await flow.reschedule('nope');

		expect(flow.getState().errors.start_time).toEqual(['Not a valid time.']);
		expect(flow.getState().view).toBe('reschedule');
	});

	it('cancels the booking onto the cancelled view', async () => {
		const client = fakeClient();
		const flow = createManageFlow({ client, token: 'tok' });

		await flow.load();
		await flow.cancel('changed my mind');

		expect(client.cancelBooking).toHaveBeenCalledWith('tok', { reason: 'changed my mind' });
		expect(flow.getState().view).toBe('cancelled');
		expect(flow.getState().booking?.status).toBe('cancelled');
	});

	it('reloads the booking when an action is refused as forbidden', async () => {
		const getManagedBooking = vi
			.fn<() => Promise<ManagedBooking>>()
			.mockResolvedValueOnce(managed())
			.mockResolvedValueOnce(managed({ meta: meta({ can_cancel: false, can_reschedule: false }) }));
		const client = fakeClient({
			getManagedBooking,
			cancelBooking: vi.fn(async () => {
				throw new BookingsApiError('Too late.', 403);
			}),
		});
		const flow = createManageFlow({ client, token: 'tok' });

		await flow.load();
		await flow.cancel();

		expect(flow.getState().error).toBe('Too late.');
		expect(getManagedBooking).toHaveBeenCalledTimes(2);
		expect(flow.getState().meta?.can_cancel).toBe(false);
	});
});
