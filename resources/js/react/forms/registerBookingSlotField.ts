/**
 * The one-call bootstrap that activates the React booking_slot field.
 *
 * A host with `artisanpack-ui/forms` `^1.5` calls this once at startup, handing
 * it the forms field-registry seam and this package's public API base URL. It is
 * the React parallel of `FormsIntegration`, which binds `BookingSlotField`'s
 * methods to the `ap.forms.*` filters on the server: the same four surfaces —
 * public renderer, editor settings, canvas preview, and palette group — are
 * registered here so the React builder and renderer reach parity with the
 * Livewire one.
 *
 * @example
 * ```ts
 * import * as forms from '@artisanpack-ui/forms';
 * import { registerBookingSlotField } from '@artisanpack-ui/bookings-js/react';
 *
 * registerBookingSlotField(forms, { baseUrl: '/api/bookings' });
 * ```
 *
 * @packageDocumentation
 */

import { createBookingSlotField } from './BookingSlotField.js';
import { BookingSlotCardPreview } from './BookingSlotCardPreview.js';
import { createBookingSlotSettings } from './BookingSlotSettings.js';
import { BOOKING_SLOT_CATEGORY, BOOKING_SLOT_TYPE } from './config.js';
import type { BookingsClient } from '../../core/index.js';
import type { FormsFieldSeam } from './types.js';

/**
 * How {@link registerBookingSlotField} configures the field.
 */
export interface RegisterBookingSlotFieldOptions {
	/** The bookings public API base URL the field fetches services and slots from. */
	baseUrl: string;

	/** The IANA zone to render times in. Defaults to the browser's. */
	timezone?: string;

	/** The BCP 47 locale to format times with. */
	locale?: string;

	/** A ready-made client, used in preference to `baseUrl` (chiefly for tests). */
	client?: BookingsClient;
}

/**
 * The 16×16 SVG path for the palette's calendar icon.
 *
 * `booking_slot` is outside the forms built-in icon set, so its palette item
 * supplies its own icon as raw path data through `iconPath`.
 */
const CALENDAR_ICON_PATH =
	'M4 0v2H2a1 1 0 00-1 1v11a1 1 0 001 1h12a1 1 0 001-1V3a1 1 0 00-1-1h-2V0h-2v2H6V0H4zM2 6h12v8H2V6z';

/**
 * Registers the booking_slot field's four React surfaces on a forms host.
 *
 * @param seam - The forms field-registry seam (the forms package's registry
 *               module, or the four register functions, satisfies this).
 * @param options - This package's API base URL, and the display zone and locale.
 */
export function registerBookingSlotField(
	seam: FormsFieldSeam,
	options: RegisterBookingSlotFieldOptions,
): void {
	seam.registerFieldComponent(
		BOOKING_SLOT_TYPE,
		createBookingSlotField({
			baseUrl: options.baseUrl,
			timezone: options.timezone,
			locale: options.locale,
			client: options.client,
		}),
	);

	seam.registerFieldSettings(
		BOOKING_SLOT_TYPE,
		createBookingSlotSettings({
			baseUrl: options.baseUrl,
			client: options.client,
		}),
	);

	seam.registerFieldCardPreview(BOOKING_SLOT_TYPE, BookingSlotCardPreview);

	seam.registerFieldPaletteGroup({
		label: 'Bookings',
		fields: [
			{
				type: BOOKING_SLOT_TYPE,
				label: 'Booking Slot',
				icon: BOOKING_SLOT_TYPE,
				category: BOOKING_SLOT_CATEGORY,
				iconPath: CALENDAR_ICON_PATH,
			},
		],
	});
}
