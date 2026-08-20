/**
 * The React demo entry.
 *
 * Mounts the compiled {@link BookingWidget} and {@link ManageBooking} from
 * `dist/react` against the mock client, so the widgets can be exercised in a
 * browser with no backend.
 */

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { BookingWidget, ManageBooking } from '../dist/react/index.js';
import { createMockClient } from './mock-client.js';

const client = createMockClient();

const widgetHost = document.getElementById('widget');
const manageHost = document.getElementById('manage');

if (widgetHost !== null) {
	createRoot(widgetHost).render(
		<StrictMode>
			<BookingWidget client={client} locale="en-US" />
		</StrictMode>,
	);
}

if (manageHost !== null) {
	createRoot(manageHost).render(
		<StrictMode>
			<ManageBooking client={client} token="demo-token" locale="en-US" />
		</StrictMode>,
	);
}
