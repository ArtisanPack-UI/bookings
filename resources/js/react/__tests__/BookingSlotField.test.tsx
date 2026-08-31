// @vitest-environment jsdom

import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';

import type { BookingsClient, Service } from '../../core/index.js';
import {
	BookingSlotCardPreview,
	configuredServiceSlugs,
	createBookingSlotField,
	createBookingSlotSettings,
	readBookingSlotConfig,
	registerBookingSlotField,
} from '../forms/index.js';
import type {
	CustomFieldSettingsProps,
	FieldPaletteGroup,
	FormFieldLike,
	FormsFieldSeam,
} from '../forms/index.js';

/** The display zone and locale that fix slot labels regardless of the host clock. */
const DISPLAY = { timezone: 'America/New_York', locale: 'en-US' } as const;

/** A service in the shape the public API returns. */
function service(over: Partial<Service>): Service {
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
		...over,
	};
}

/** A client whose service, provider, and slot lists are fixed. */
function client(over: Partial<Record<keyof BookingsClient, unknown>> = {}): BookingsClient {
	return {
		listServices: vi.fn(async () => [service({})]),
		listProviders: vi.fn(async () => []),
		listSlots: vi.fn(async () => [
			{ start: '2025-09-01T13:00:00Z', end: '2025-09-01T13:30:00Z', provider_id: null },
		]),
		createBooking: vi.fn(),
		getManagedBooking: vi.fn(),
		cancelBooking: vi.fn(),
		rescheduleBooking: vi.fn(),
		...over,
	} as unknown as BookingsClient;
}

/** A booking_slot field, defaulting the members the components read. */
function field(over: Partial<FormFieldLike>): FormFieldLike {
	return {
		id: 1,
		name: 'appointment',
		type: 'booking_slot',
		label: 'Choose a time',
		help_text: null,
		is_required: false,
		field_config: null,
		...over,
	};
}

afterEach(() => {
	cleanup();
});

describe('configuredServiceSlugs', () => {
	it('trims, drops blanks, and de-duplicates', () => {
		expect(configuredServiceSlugs({ service_slugs: [' a ', 'a', '', 'b'] })).toEqual(['a', 'b']);
	});

	it('is empty for a non-array or absent config', () => {
		expect(configuredServiceSlugs({})).toEqual([]);
		expect(configuredServiceSlugs(readBookingSlotConfig(null))).toEqual([]);
	});
});

describe('registerBookingSlotField', () => {
	it('registers all four surfaces under booking_slot with a Bookings palette group', () => {
		const seam = {
			registerFieldComponent: vi.fn(),
			registerFieldSettings: vi.fn(),
			registerFieldCardPreview: vi.fn(),
			registerFieldPaletteGroup: vi.fn(),
		} satisfies FormsFieldSeam;

		registerBookingSlotField(seam, { baseUrl: '/api/bookings' });

		expect(seam.registerFieldComponent).toHaveBeenCalledWith('booking_slot', expect.any(Function));
		expect(seam.registerFieldSettings).toHaveBeenCalledWith('booking_slot', expect.any(Function));
		expect(seam.registerFieldCardPreview).toHaveBeenCalledWith('booking_slot', BookingSlotCardPreview);

		const group = seam.registerFieldPaletteGroup.mock.calls[0]?.[0] as FieldPaletteGroup;
		expect(group.label).toBe('Bookings');
		expect(group.fields[0]?.type).toBe('booking_slot');
		expect(group.fields[0]?.iconPath).toMatch(/^M/);
	});
});

describe('BookingSlotField renderer', () => {
	it('shows the empty notice when no service is configured', () => {
		const BookingSlot = createBookingSlotField({ baseUrl: '/api', client: client(), ...DISPLAY });
		render(<BookingSlot field={field({ field_config: {} })} value={undefined} onChange={() => {}} />);

		expect(screen.getByText(/No service is configured/)).toBeTruthy();
	});

	it('auto-selects a sole configured service and emits the picked slot as JSON', async () => {
		const onChange = vi.fn();
		const BookingSlot = createBookingSlotField({ baseUrl: '/api', client: client(), ...DISPLAY });
		render(
			<BookingSlot
				field={field({ field_config: { service_slugs: ['haircut'] } })}
				value={undefined}
				onChange={onChange}
			/>,
		);

		// Sole service auto-selects → the calendar's slot appears.
		const slot = await screen.findByRole('button', { name: '9:00 AM' });
		fireEvent.click(slot);

		await waitFor(() => {
			expect(onChange).toHaveBeenCalledWith(
				JSON.stringify({
					service_slug: 'haircut',
					start: '2025-09-01T13:00:00Z',
					provider_id: null,
				}),
			);
		});
	});

	it('offers a service choice when several are configured', async () => {
		const twoServices = client({
			listServices: vi.fn(async () => [
				service({ id: 1, slug: 'haircut', name: 'Haircut' }),
				service({ id: 2, slug: 'color', name: 'Color' }),
			]),
		});
		const BookingSlot = createBookingSlotField({ baseUrl: '/api', client: twoServices, ...DISPLAY });
		render(
			<BookingSlot
				field={field({ field_config: { service_slugs: ['haircut', 'color'] } })}
				value={undefined}
				onChange={() => {}}
			/>,
		);

		expect(await screen.findByRole('button', { name: /Haircut/ })).toBeTruthy();
		expect(screen.getByRole('button', { name: /Color/ })).toBeTruthy();
	});

	it('ignores a configured slug no active service answers to', async () => {
		const BookingSlot = createBookingSlotField({ baseUrl: '/api', client: client(), ...DISPLAY });
		render(
			<BookingSlot
				field={field({ field_config: { service_slugs: ['haircut', 'gone'] } })}
				value={undefined}
				onChange={() => {}}
			/>,
		);

		// Only 'haircut' resolves, so it is the sole service and auto-selects to
		// the calendar rather than showing a one-item service choice.
		expect(await screen.findByRole('button', { name: '9:00 AM' })).toBeTruthy();
	});
});

describe('BookingSlotSettings panel', () => {
	function settingsProps(over: Partial<CustomFieldSettingsProps> = {}): CustomFieldSettingsProps {
		const edited = field({ id: 9, name: 'appointment', field_config: {} });

		return {
			field: edited,
			allFields: [
				{ id: 1, name: 'full_name', type: 'text', label: 'Full name' },
				{ id: 2, name: 'email', type: 'email', label: 'Email' },
				{ id: 3, name: 'heading', type: 'heading', label: 'Section' },
				edited,
			],
			updateField: vi.fn(),
			...over,
		};
	}

	it('loads services and toggles one into the config under service_slugs', async () => {
		const updateField = vi.fn();
		const Settings = createBookingSlotSettings({ baseUrl: '/api', client: client() });
		render(<Settings {...settingsProps({ updateField })} />);

		const checkbox = await screen.findByRole('checkbox', { name: /Haircut/ });
		fireEvent.click(checkbox);

		expect(updateField).toHaveBeenCalledWith({ field_config: { service_slugs: ['haircut'] } });
	});

	it('offers only data-collecting fields as mapping targets, excluding self and layout', () => {
		const Settings = createBookingSlotSettings({ baseUrl: '/api', client: client() });
		render(<Settings {...settingsProps()} />);

		const nameSelect = screen.getByRole('combobox', { name: /Name Form Field/ });
		const optionText = Array.from(nameSelect.querySelectorAll('option')).map((o) => o.textContent);

		expect(optionText).toContain('Full name');
		expect(optionText).toContain('Email');
		expect(optionText).not.toContain('Section'); // heading is a layout element
		expect(optionText).not.toContain('Choose a time'); // the booking field itself
	});
});

describe('BookingSlotCardPreview', () => {
	it('shows the empty notice when unconfigured', () => {
		render(<BookingSlotCardPreview field={field({ field_config: {} })} />);
		expect(screen.getByText(/No service is configured/)).toBeTruthy();
	});

	it('shows the choose-a-time card when configured', () => {
		render(<BookingSlotCardPreview field={field({ field_config: { service_slugs: ['haircut'] } })} />);
		expect(screen.getByText('Choose a time')).toBeTruthy();
		expect(screen.getByText('1 service')).toBeTruthy();
	});
});
