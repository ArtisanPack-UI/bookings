import { beforeEach, describe, expect, it, vi } from 'vitest';

import { BookingsApiError, type BookingsClient } from '../api-client.js';
import { createBookingFlow } from '../booking-flow.js';
import type {
	Booking,
	CreateBookingPayload,
	Provider,
	Service,
	Slot,
	SlotQuery,
} from '../types.js';

function service(overrides: Partial<Service> = {}): Service {
	return {
		id: 1,
		slug: 'haircut',
		name: 'Haircut',
		description: null,
		duration: 30,
		buffer_before: 0,
		buffer_after: 0,
		price: null,
		is_free: true,
		color: null,
		image_url: null,
		timezone: 'America/New_York',
		assignment_strategy: 'any',
		intake_schema: null,
		intake_schema_version: 1,
		...overrides,
	};
}

function provider(overrides: Partial<Provider> = {}): Provider {
	return {
		id: 10,
		slug: 'alex',
		name: 'Alex',
		bio: null,
		timezone: null,
		image_url: null,
		...overrides,
	};
}

function slot(start: string, end: string, providerId: number | null = null): Slot {
	return { start, end, provider_id: providerId };
}

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

interface FakeConfig {
	services?: Service[];
	providers?: Record<string, Provider[]>;
	slots?: Slot[];
	createBooking?: (payload: CreateBookingPayload) => Promise<Booking>;
}

function fakeClient(config: FakeConfig = {}): BookingsClient {
	return {
		listServices: vi.fn(async () => config.services ?? [service()]),
		listProviders: vi.fn(async (slug: string) => config.providers?.[slug] ?? []),
		listSlots: vi.fn(async (_slug: string, _query: SlotQuery) => config.slots ?? []),
		createBooking:
			config.createBooking !== undefined
				? vi.fn(config.createBooking)
				: vi.fn(async () => booking()),
		getManagedBooking: vi.fn(),
		cancelBooking: vi.fn(),
		rescheduleBooking: vi.fn(),
	} as unknown as BookingsClient;
}

describe('createBookingFlow', () => {
	beforeEach(() => {
		vi.restoreAllMocks();
	});

	it('opens on the service list when several services exist', async () => {
		const flow = createBookingFlow({
			client: fakeClient({ services: [service(), service({ id: 2, slug: 'colour', name: 'Colour' })] }),
			timezone: 'America/New_York',
		});

		await flow.start();

		expect(flow.getState().step).toBe('service');
		expect(flow.getState().services).toHaveLength(2);
	});

	it('skips the service list and the provider step for a single provider-less service', async () => {
		const flow = createBookingFlow({
			client: fakeClient({
				services: [service()],
				slots: [slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z')],
			}),
			timezone: 'America/New_York',
		});

		await flow.start();

		const state = flow.getState();
		expect(state.selectedService?.slug).toBe('haircut');
		expect(state.offersProviderChoice).toBe(false);
		expect(state.step).toBe('slot');
		expect(state.slotDays).toHaveLength(1);
		expect(state.selectedDay).toBe('2025-09-01');
	});

	it('offers the provider step only when more than one provider is bookable', async () => {
		const flow = createBookingFlow({
			client: fakeClient({
				services: [service({ assignment_strategy: 'round_robin' })],
				providers: { haircut: [provider(), provider({ id: 11, slug: 'blair', name: 'Blair' })] },
			}),
			timezone: 'America/New_York',
		});

		await flow.start();

		expect(flow.getState().step).toBe('provider');
		expect(flow.getState().offersProviderChoice).toBe(true);

		await flow.selectProvider(10);

		expect(flow.getState().step).toBe('slot');
		expect(flow.getState().providerChosen).toBe(true);
		expect(flow.getState().providerId).toBe(10);
	});

	it('does not leave a pinned service', async () => {
		const flow = createBookingFlow({
			client: fakeClient({ services: [service(), service({ id: 2, slug: 'colour' })] }),
			pinnedServiceSlug: 'haircut',
			timezone: 'America/New_York',
		});

		await flow.start();
		expect(flow.getState().selectedService?.slug).toBe('haircut');

		await flow.selectService('colour');
		expect(flow.getState().selectedService?.slug).toBe('haircut');
	});

	it('errors when a pinned service is not on offer', async () => {
		const flow = createBookingFlow({
			client: fakeClient({ services: [service({ slug: 'colour' })] }),
			pinnedServiceSlug: 'haircut',
		});

		await flow.start();

		expect(flow.getState().error).not.toBeNull();
		expect(flow.getState().selectedService).toBeNull();
	});

	it('reloads slots when the month is shifted', async () => {
		const client = fakeClient({
			services: [service()],
			slots: [slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z')],
		});
		const flow = createBookingFlow({ client, timezone: 'America/New_York' });

		await flow.start();
		const startMonth = flow.getState().month;

		await flow.shiftMonth(1);

		const [year, month] = startMonth.split('-').map(Number) as [number, number];
		const total = year * 12 + (month - 1) + 1;
		const nextMonth = `${Math.floor(total / 12)}-${String((total % 12) + 1).padStart(2, '0')}`;

		expect(flow.getState().month).toBe(nextMonth);
		expect(client.listSlots).toHaveBeenLastCalledWith('haircut', {
			date: nextMonth,
			providerId: null,
		});
	});

	it('reaches the details step once a slot is chosen and books it', async () => {
		const created = booking();
		const client = fakeClient({
			services: [service()],
			slots: [slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z')],
			createBooking: async () => created,
		});
		const onBooked = vi.fn();
		const flow = createBookingFlow({ client, timezone: 'America/New_York', onBooked });

		await flow.start();
		flow.selectSlot(slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z'));
		expect(flow.getState().step).toBe('details');

		flow.setDetail('customerName', 'Sam');
		flow.setDetail('customerEmail', 'sam@example.test');
		await flow.submit();

		expect(client.createBooking).toHaveBeenCalledWith(
			expect.objectContaining({
				serviceSlug: 'haircut',
				startTime: '2025-09-01T13:00:00Z',
				customerName: 'Sam',
				customerEmail: 'sam@example.test',
				customerTimezone: 'America/New_York',
			}),
		);
		expect(flow.getState().step).toBe('done');
		expect(flow.getState().confirmation).toBe(created);
		expect(onBooked).toHaveBeenCalledWith(created);
	});

	it('maps a 422 onto the flow field names', async () => {
		const client = fakeClient({
			services: [service()],
			slots: [slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z')],
			createBooking: async () => {
				throw new BookingsApiError('Invalid.', 422, {
					customer_email: ['The email must be valid.'],
					'intake_data.age': ['Required.'],
				});
			},
		});
		const flow = createBookingFlow({ client, timezone: 'America/New_York' });

		await flow.start();
		flow.selectSlot(slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z'));
		await flow.submit();

		const errors = flow.getState().errors;
		expect(errors.customerEmail).toEqual(['The email must be valid.']);
		expect(errors['intake.age']).toEqual(['Required.']);
		expect(flow.getState().step).toBe('details');
	});

	it('drops a taken slot and returns to the list on a 409', async () => {
		const client = fakeClient({
			services: [service()],
			slots: [slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z')],
			createBooking: async () => {
				throw new BookingsApiError('That slot is gone.', 409);
			},
		});
		const flow = createBookingFlow({ client, timezone: 'America/New_York' });

		await flow.start();
		flow.selectSlot(slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z'));
		await flow.submit();

		expect(flow.getState().selectedSlot).toBeNull();
		expect(flow.getState().step).toBe('slot');
		expect(flow.getState().error).toBe('That slot is gone.');
	});

	it('resets to a fresh form on bookAnother', async () => {
		const flow = createBookingFlow({
			client: fakeClient({
				services: [service()],
				slots: [slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z')],
			}),
			timezone: 'America/New_York',
		});

		await flow.start();
		flow.selectSlot(slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z'));
		flow.setDetail('customerName', 'Sam');
		await flow.submit();
		expect(flow.getState().step).toBe('done');

		flow.bookAnother();

		expect(flow.getState().confirmation).toBeNull();
		expect(flow.getState().details.customerName).toBe('');
		expect(flow.getState().step).toBe('slot');
	});

	it('notifies subscribers on every change and stops after unsubscribe', async () => {
		const flow = createBookingFlow({ client: fakeClient({ services: [service()] }) });
		const listener = vi.fn();

		const unsubscribe = flow.subscribe(listener);
		await flow.start();
		expect(listener.mock.calls.length).toBeGreaterThan(0);

		const seen = listener.mock.calls.length;
		unsubscribe();
		flow.selectDay('2025-09-02');
		expect(listener.mock.calls.length).toBe(seen);
	});
});
