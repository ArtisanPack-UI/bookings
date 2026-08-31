/**
 * The React booking_slot field for an `artisanpack-ui/forms` host.
 *
 * A host with forms `^1.5` calls {@link registerBookingSlotField} once at
 * bootstrap to give its React builder and renderer a first-party `booking_slot`
 * field, at parity with the Livewire one. The individual component factories and
 * the stored shapes are exported too, for a host that wants to register a subset
 * or read the config by hand.
 *
 * @packageDocumentation
 */

export { registerBookingSlotField } from './registerBookingSlotField.js';
export type { RegisterBookingSlotFieldOptions } from './registerBookingSlotField.js';

export { createBookingSlotField } from './BookingSlotField.js';
export type { BookingSlotFieldOptions } from './BookingSlotField.js';

export { createBookingSlotSettings } from './BookingSlotSettings.js';
export type { BookingSlotSettingsOptions } from './BookingSlotSettings.js';

export { BookingSlotCardPreview } from './BookingSlotCardPreview.js';

export {
	BOOKING_SLOT_CATEGORY,
	BOOKING_SLOT_TYPE,
	BookingSlotConfigKeys,
	configuredServiceSlugs,
	readBookingSlotConfig,
} from './config.js';
export type { BookingSlotConfig, BookingSlotValue } from './config.js';

export type {
	CustomFieldSettingsProps,
	FieldCardPreviewProps,
	FieldComponentProps,
	FieldPaletteGroup,
	FieldPaletteItem,
	FormFieldLike,
	FormsFieldSeam,
	UpdateFieldRequestLike,
} from './types.js';
