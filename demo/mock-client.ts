/**
 * An in-memory {@link BookingsClient} for the demo.
 *
 * Stands in for the package's public API so the widgets can be clicked through
 * in a browser with no Laravel backend running. It invents a couple of services
 * and providers, generates a handful of slots for whichever month is asked for,
 * and answers the manage endpoints for any token.
 *
 * Not shipped — this lives under `demo/` and never enters `dist/`.
 */

import type {
	Booking,
	BookingsClient,
	CreateBookingPayload,
	ManagedBooking,
	Provider,
	Service,
	Slot,
	SlotQuery,
} from '../dist/core/index.js';

const services: Service[] = [
	{
		id: 1,
		slug: 'haircut',
		name: 'Haircut',
		description: 'A quick trim and tidy-up.',
		duration: 30,
		buffer_before: 0,
		buffer_after: 0,
		price: '25.00',
		is_free: false,
		color: '#4f46e5',
		image_url: null,
		timezone: 'America/New_York',
		assignment_strategy: 'round_robin',
		intake_schema: {
			fields: [
				{ name: 'style', type: 'text', label: 'Preferred style', required: false },
				{
					name: 'length',
					type: 'select',
					label: 'How much off?',
					required: true,
					options: ['A little', 'A medium amount', 'A lot'],
				},
				{
					name: 'extras',
					type: 'checkboxes',
					label: 'Add-ons',
					required: false,
					options: ['Wash', 'Beard trim', 'Styling'],
				},
			],
		},
		intake_schema_version: 1,
	},
	{
		id: 2,
		slug: 'consultation',
		name: 'Consultation',
		description: 'A 15-minute chat about what you need.',
		duration: 15,
		buffer_before: 0,
		buffer_after: 0,
		price: null,
		is_free: true,
		color: '#059669',
		image_url: null,
		timezone: 'America/New_York',
		assignment_strategy: 'any',
		intake_schema: null,
		intake_schema_version: 1,
	},
];

const providers: Record<string, Provider[]> = {
	haircut: [
		{ id: 10, slug: 'alex', name: 'Alex', bio: 'Ten years with the scissors.', timezone: null, image_url: null },
		{ id: 11, slug: 'blair', name: 'Blair', bio: 'Loves a fade.', timezone: null, image_url: null },
	],
	consultation: [
		{ id: 12, slug: 'casey', name: 'Casey', bio: null, timezone: null, image_url: null },
	],
};

/**
 * Builds a few slots for the first two Mondays of the asked-for month.
 */
function slotsForMonth(month: string, providerId: number | null): Slot[] {
	const [yearPart, monthPart] = month.split('-');
	const year = Number(yearPart);
	const zeroBasedMonth = Number(monthPart) - 1;

	if (!Number.isInteger(year) || !Number.isInteger(zeroBasedMonth)) {
		return [];
	}

	const slots: Slot[] = [];

	for (const day of [8, 9, 22]) {
		for (const hour of [13, 14, 15]) {
			const start = new Date(Date.UTC(year, zeroBasedMonth, day, hour, 0, 0));
			const end = new Date(start.getTime() + 30 * 60000);

			slots.push({
				start: start.toISOString(),
				end: end.toISOString(),
				provider_id: providerId,
			});
		}
	}

	return slots;
}

function delay<T>(value: T): Promise<T> {
	return new Promise((resolve) => setTimeout(() => resolve(value), 250));
}

/**
 * Adds a number of minutes to an ISO instant, returning a new ISO instant.
 */
function addMinutes(iso: string, minutes: number): string {
	return new Date(new Date(iso).getTime() + minutes * 60000).toISOString();
}

/**
 * A booking start a week out, at 13:00 UTC, so the manage fixtures always sit
 * in the future rather than going stale against a hard-coded date.
 */
function futureStart(): string {
	const date = new Date(Date.now() + 7 * 24 * 60 * 60000);
	date.setUTCHours(13, 0, 0, 0);

	return date.toISOString();
}

/**
 * The booking the manage endpoints report, at the given status.
 */
function managedFixture(status: Booking['status'], start: string): ManagedBooking {
	const cancelled = status === 'cancelled';

	return {
		data: {
			id: 99,
			status,
			start_time: start,
			end_time: addMinutes(start, 30),
			customer_name: 'Sam Taylor',
			customer_email: 'sam@example.test',
			customer_timezone: 'America/New_York',
			service: { id: 1, slug: 'haircut', name: 'Haircut' },
			provider: { id: 10, slug: 'alex', name: 'Alex' },
		},
		meta: cancelled
			? { can_cancel: false, can_reschedule: false, changes_allowed_until: null }
			: {
					can_cancel: true,
					can_reschedule: true,
					changes_allowed_until: addMinutes(start, -24 * 60),
				},
	};
}

/**
 * Creates the demo's mock client.
 */
export function createMockClient(): BookingsClient {
	// One shared start for the managed booking, so load, cancel, and reschedule
	// all agree on the same appointment.
	const managedStart = futureStart();

	return {
		listServices(): Promise<Service[]> {
			return delay(services);
		},

		listProviders(serviceSlug: string): Promise<Provider[]> {
			return delay(providers[serviceSlug] ?? []);
		},

		listSlots(_serviceSlug: string, query: SlotQuery): Promise<Slot[]> {
			return delay(slotsForMonth(query.date, query.providerId ?? null));
		},

		createBooking(payload: CreateBookingPayload): Promise<Booking> {
			const service = services.find((candidate) => candidate.slug === payload.serviceSlug);
			const provider =
				payload.providerId != null
					? (providers[payload.serviceSlug] ?? []).find((candidate) => candidate.id === payload.providerId)
					: undefined;

			const booking: Booking = {
				id: 99,
				status: 'confirmed',
				start_time: payload.startTime,
				end_time: addMinutes(payload.startTime, service?.duration ?? 30),
				customer_name: payload.customerName,
				customer_email: payload.customerEmail,
				customer_timezone: payload.customerTimezone ?? null,
				service: {
					id: service?.id ?? 0,
					slug: payload.serviceSlug,
					name: service?.name ?? payload.serviceSlug,
				},
				provider:
					provider !== undefined
						? { id: provider.id, slug: provider.slug, name: provider.name }
						: null,
			};

			return delay(booking);
		},

		getManagedBooking(_token: string): Promise<ManagedBooking> {
			return delay(managedFixture('confirmed', managedStart));
		},

		cancelBooking(_token: string): Promise<ManagedBooking> {
			return delay(managedFixture('cancelled', managedStart));
		},

		rescheduleBooking(_token: string, payload): Promise<ManagedBooking> {
			return delay(managedFixture('confirmed', payload.startTime));
		},
	};
}
