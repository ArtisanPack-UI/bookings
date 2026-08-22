/**
 * The Vue demo entry.
 *
 * Mounts the compiled {@link BookingWidget} and {@link ManageBooking} from
 * `dist/vue` against the mock client, so the widgets can be exercised in a
 * browser with no backend.
 */

import { createApp, h } from 'vue';

import { BookingWidget, ManageBooking } from '../dist/vue/index.js';
import { createMockClient } from './mock-client.js';

const client = createMockClient();

const widgetHost = document.getElementById('widget');
const manageHost = document.getElementById('manage');

if (widgetHost !== null) {
	createApp({
		render: () => h(BookingWidget, { client, locale: 'en-US' }),
	}).mount(widgetHost);
}

if (manageHost !== null) {
	createApp({
		render: () => h(ManageBooking, { client, token: 'demo-token', locale: 'en-US' }),
	}).mount(manageHost);
}
