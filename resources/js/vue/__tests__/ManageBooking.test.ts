// @vitest-environment jsdom

import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

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

describe('ManageBooking (Vue)', () => {
	it('loads the booking and offers the permitted actions', async () => {
		const wrapper = mount(ManageBooking, {
			props: { client: client(), token: 'tok', timezone: 'America/New_York', locale: 'en-US' },
		});

		await flushPromises();

		expect(wrapper.text()).toContain('Haircut');
		expect(wrapper.find('.apbk-reschedule-start').exists()).toBe(true);
		expect(wrapper.find('.apbk-cancel-submit').exists()).toBe(true);
	});

	it('cancels the booking', async () => {
		const api = client();
		const wrapper = mount(ManageBooking, {
			props: { client: api, token: 'tok', timezone: 'America/New_York' },
		});

		await flushPromises();
		await wrapper.get('.apbk-cancel').trigger('submit');
		await flushPromises();

		expect(wrapper.text()).toContain('This booking has been cancelled.');
		expect(api.cancelBooking).toHaveBeenCalledWith('tok', { reason: null });
	});

	it('hides actions the meta forbids', async () => {
		const api = client();
		(api.getManagedBooking as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
			...managed(),
			meta: { can_cancel: false, can_reschedule: false, changes_allowed_until: null },
		});

		const wrapper = mount(ManageBooking, {
			props: { client: api, token: 'tok', timezone: 'America/New_York' },
		});

		await flushPromises();

		expect(wrapper.text()).toContain('Haircut');
		expect(wrapper.find('.apbk-reschedule-start').exists()).toBe(false);
		expect(wrapper.find('.apbk-cancel-submit').exists()).toBe(false);
	});
});

describe('ManageBooking (Vue) reschedule', () => {
	it('sends the new time as an instant in the booking timezone', async () => {
		const api = client();
		const wrapper = mount(ManageBooking, {
			props: { client: api, token: 'tok', timezone: 'America/New_York', locale: 'en-US' },
		});

		await flushPromises();
		await wrapper.get('.apbk-reschedule-start').trigger('click');
		await wrapper.get('.apbk-reschedule input').setValue('2025-12-01T09:00');
		await wrapper.get('.apbk-reschedule').trigger('submit');
		await flushPromises();

		// 09:00 in New York in December is EST (UTC-5), i.e. 14:00 UTC.
		expect(api.rescheduleBooking).toHaveBeenCalledWith('tok', {
			startTime: '2025-12-01T14:00:00.000Z',
		});
	});

	it('shows a reschedule validation error under the startTime key', async () => {
		const api = client();
		const { BookingsApiError } = await import('../../core/index.js');
		(api.rescheduleBooking as ReturnType<typeof vi.fn>).mockRejectedValueOnce(
			new BookingsApiError('Invalid.', 422, { start_time: ['That time is taken.'] }),
		);
		const wrapper = mount(ManageBooking, {
			props: { client: api, token: 'tok', timezone: 'America/New_York' },
		});

		await flushPromises();
		await wrapper.get('.apbk-reschedule-start').trigger('click');
		await wrapper.get('.apbk-reschedule input').setValue('2025-12-01T09:00');
		await wrapper.get('.apbk-reschedule').trigger('submit');
		await flushPromises();

		expect(wrapper.text()).toContain('That time is taken.');
	});
});
