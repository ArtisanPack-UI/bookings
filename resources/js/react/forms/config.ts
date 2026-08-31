/**
 * The booking_slot field's stored shapes.
 *
 * The `field_config` keys and the submitted-value shape are the contract this
 * React field shares with the server: the same keys
 * {@link \ArtisanPackUI\Bookings\Integrations\Forms\BookingSlotField} reads from
 * `field_config`, and the same slot JSON
 * {@link \ArtisanPackUI\Bookings\Integrations\Forms\FormBookingListener} reads
 * from the submission. They are named here exactly as they are there so a form
 * built in a React host books through the identical listener a Livewire-built
 * one does.
 *
 * @packageDocumentation
 */

/** The field type key, as stored on a form field and matched on render. */
export const BOOKING_SLOT_TYPE = 'booking_slot';

/** The palette category the booking_slot type is filed under. */
export const BOOKING_SLOT_CATEGORY = 'advanced';

/**
 * The `field_config` keys, mirroring `BookingSlotField`'s `CONFIG_*` constants.
 */
export const BookingSlotConfigKeys = {
	/** The slugs of the services the field books. */
	services: 'service_slugs',
	/** The form field the customer's name is mapped to. */
	nameField: 'name_field',
	/** The form field the customer's email is mapped to. */
	emailField: 'email_field',
	/** The form field the customer's phone is mapped to. */
	phoneField: 'phone_field',
	/** The form field the opt-in answer is mapped to. */
	optinField: 'optin_field',
	/** The description shown under the field. */
	description: 'description',
} as const;

/**
 * The booking_slot `field_config`, as this module reads and writes it.
 */
export interface BookingSlotConfig {
	/** The configured service slugs. */
	service_slugs?: string[];

	/** The form field mapped to the customer's name. */
	name_field?: string;

	/** The form field mapped to the customer's email. */
	email_field?: string;

	/** The form field mapped to the customer's phone. */
	phone_field?: string;

	/** The form field mapped to the opt-in answer. */
	optin_field?: string;

	/** The description shown under the field. */
	description?: string;
}

/**
 * The slot JSON the picker writes into the submission.
 *
 * The shape `FormBookingListener` reads: the chosen service's slug, the slot's
 * UTC start instant, and the provider that would serve it (null for a
 * round-robin slot the backend has yet to assign).
 */
export interface BookingSlotValue {
	/** The chosen service's slug. */
	service_slug: string;

	/** The chosen slot's start instant, ISO 8601 in UTC. */
	start: string;

	/** The chosen provider's id, or null for no preference. */
	provider_id: number | null;
}

/**
 * Reads a field's `field_config` as a {@link BookingSlotConfig}.
 *
 * @param config - The raw `field_config` bag, which may be null.
 * @returns The configuration, defaulting an absent bag to empty.
 */
export function readBookingSlotConfig(config: Record<string, unknown> | null | undefined): BookingSlotConfig {
	return (config ?? {}) as BookingSlotConfig;
}

/**
 * Reads the configured service slugs, trimmed and de-duplicated.
 *
 * Mirrors `BookingSlotField::pickerServices()`' cleaning of the stored slugs: a
 * non-array config, blank entries, and duplicates are dropped, so an
 * empty result is the reliable signal that the field has no service configured.
 *
 * @param config - The booking_slot configuration.
 * @returns The cleaned service slugs.
 */
export function configuredServiceSlugs(config: BookingSlotConfig): string[] {
	const raw = config.service_slugs;

	if (!Array.isArray(raw)) {
		return [];
	}

	const seen = new Set<string>();

	for (const entry of raw) {
		const slug = typeof entry === 'string' ? entry.trim() : '';

		if (slug !== '') {
			seen.add(slug);
		}
	}

	return [...seen];
}
