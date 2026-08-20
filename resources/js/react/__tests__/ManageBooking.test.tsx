// @vitest-environment jsdom

import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';

import type { BookingsClient, ManagedBooking } from '../../core/index.js';
import { ManageBooking } from '../ManageBooking.js';

function managed(): ManagedBooking {
	return {
		data: {
			id: 99,
			status: 'confirmed',
			start_time: '2025-09-01T13:00:00Z',
			end_time: '2025-09-01T13:30:00Z',
			customer_name: 'Sam',
			customer_email: 'sam@example.test',
			customer_timezone: 'America/New_York',
			service: { id: 1, slug: 'haircut', name: 'Haircut' },
			provider: null,
		},
		meta: {
			can_cancel: true,
			can_reschedule: true,
			changes_allowed_until: '2025-08-31T13:00:00Z',
		},
	};
}

function client(): BookingsClient {
	return {
		listServices: vi.fn(),
		listProviders: vi.fn(),
		listSlots: vi.fn(),
		createBooking: vi.fn(),
		getManagedBooking: vi.fn(async () => managed()),
		cancelBooking: vi.fn(async () => ({
			...managed(),
			data: { ...managed().data, status: 'cancelled' as const },
		})),
		rescheduleBooking: vi.fn(async () => managed()),
	} as unknown as BookingsClient;
}

afterEach(() => {
	cleanup();
});

describe('ManageBooking', () => {
	it('loads the booking and offers the permitted actions', async () => {
		render(<ManageBooking client={client()} token="tok" timezone="America/New_York" locale="en-US" />);

		expect(await screen.findByText('Haircut')).toBeTruthy();
		expect(screen.getByRole('button', { name: 'Reschedule' })).toBeTruthy();
		expect(screen.getByRole('button', { name: 'Cancel booking' })).toBeTruthy();
	});

	it('cancels the booking', async () => {
		const api = client();
		render(<ManageBooking client={api} token="tok" timezone="America/New_York" />);

		fireEvent.click(await screen.findByRole('button', { name: 'Cancel booking' }));

		await waitFor(() => {
			expect(screen.getByText('This booking has been cancelled.')).toBeTruthy();
		});
		expect(api.cancelBooking).toHaveBeenCalledWith('tok', { reason: null });
	});

	it('hides actions the meta forbids', async () => {
		const api = client();
		(api.getManagedBooking as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
			...managed(),
			meta: { can_cancel: false, can_reschedule: false, changes_allowed_until: null },
		});

		render(<ManageBooking client={api} token="tok" timezone="America/New_York" />);

		await screen.findByText('Haircut');
		expect(screen.queryByRole('button', { name: 'Reschedule' })).toBeNull();
		expect(screen.queryByRole('button', { name: 'Cancel booking' })).toBeNull();
	});
});
