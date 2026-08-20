// @vitest-environment jsdom

import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';

import { BookingsApiError, type BookingsClient } from '../../core/index.js';
import { BookingWidget } from '../BookingWidget.js';

function client(): BookingsClient {
	return {
		listServices: vi.fn(async () => [
			{
				id: 1,
				slug: 'haircut',
				name: 'Haircut',
				description: 'A trim.',
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
			},
		]),
		listProviders: vi.fn(async () => []),
		listSlots: vi.fn(async () => [
			{ start: '2025-09-01T13:00:00Z', end: '2025-09-01T13:30:00Z', provider_id: null },
		]),
		createBooking: vi.fn(async () => ({
			id: 99,
			status: 'confirmed' as const,
			start_time: '2025-09-01T13:00:00Z',
			end_time: '2025-09-01T13:30:00Z',
			customer_name: 'Sam',
			customer_email: 'sam@example.test',
			customer_timezone: 'America/New_York',
			service: { id: 1, slug: 'haircut', name: 'Haircut' },
			provider: null,
		})),
		getManagedBooking: vi.fn(),
		cancelBooking: vi.fn(),
		rescheduleBooking: vi.fn(),
	} as unknown as BookingsClient;
}

afterEach(() => {
	cleanup();
});

describe('BookingWidget', () => {
	it('walks a single service through to a confirmation', async () => {
		const api = client();
		render(<BookingWidget client={api} timezone="America/New_York" locale="en-US" />);

		// The single service auto-selects and lands on the slot step.
		const slot = await screen.findByRole('button', { name: '9:00 AM' });
		fireEvent.click(slot);

		fireEvent.change(await screen.findByLabelText(/Name/), { target: { value: 'Sam' } });
		fireEvent.change(screen.getByLabelText(/Email/), { target: { value: 'sam@example.test' } });
		fireEvent.click(screen.getByRole('button', { name: 'Book' }));

		await waitFor(() => {
			expect(screen.getByText('You’re booked')).toBeTruthy();
		});
		expect(api.createBooking).toHaveBeenCalledWith(
			expect.objectContaining({ serviceSlug: 'haircut', startTime: '2025-09-01T13:00:00Z' }),
		);
	});

	it('shows a validation error returned by the API', async () => {
		const api = client();
		api.createBooking = vi.fn(async () => {
			throw new BookingsApiError('Invalid.', 422, {
				customer_email: ['This email address is not allowed.'],
			});
		});

		render(<BookingWidget client={api} timezone="America/New_York" locale="en-US" />);

		fireEvent.click(await screen.findByRole('button', { name: '9:00 AM' }));
		fireEvent.change(await screen.findByLabelText(/Name/), { target: { value: 'Sam' } });
		// An HTML-valid address the API rejects on its own merits, so the submit
		// reaches the server rather than being stopped by the input's own
		// `type="email"` constraint in jsdom.
		fireEvent.change(screen.getByLabelText(/Email/), { target: { value: 'blocked@example.test' } });
		fireEvent.click(screen.getByRole('button', { name: 'Book' }));

		await waitFor(() => {
			expect(screen.getByText('This email address is not allowed.')).toBeTruthy();
		});
	});
});
