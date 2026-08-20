// @vitest-environment jsdom

import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

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

describe('BookingWidget (Vue)', () => {
	it('walks a single service through to a confirmation', async () => {
		const api = client();
		const wrapper = mount(BookingWidget, {
			props: { client: api, timezone: 'America/New_York', locale: 'en-US' },
		});

		await flushPromises();

		expect(wrapper.get('.apbk-slot').text()).toBe('9:00 AM');
		await wrapper.get('.apbk-slot').trigger('click');

		await wrapper.get('.apbk-details input[type="text"]').setValue('Sam');
		await wrapper.get('.apbk-details input[type="email"]').setValue('sam@example.test');
		await wrapper.get('.apbk-details').trigger('submit');
		await flushPromises();

		expect(wrapper.text()).toContain('You’re booked');
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

		const wrapper = mount(BookingWidget, {
			props: { client: api, timezone: 'America/New_York', locale: 'en-US' },
		});

		await flushPromises();
		await wrapper.get('.apbk-slot').trigger('click');
		await wrapper.get('.apbk-details input[type="text"]').setValue('Sam');
		await wrapper.get('.apbk-details input[type="email"]').setValue('sam@example.test');
		await wrapper.get('.apbk-details').trigger('submit');
		await flushPromises();

		expect(wrapper.text()).toContain('This email address is not allowed.');
	});
});
