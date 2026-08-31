/**
 * The booking_slot settings panel for a React forms editor.
 *
 * The React counterpart of `BookingSlotField::settings()` and its
 * `booking-slot-settings` blade: the Services multi-select, drawn from this
 * package's own API, and the Name / Email / Phone / Opt-In mappings onto the
 * form's other fields — so the appointment reuses the form's existing contact
 * questions rather than standing up its own. Every change is persisted into
 * `field_config` under the same keys the server reads.
 *
 * @packageDocumentation
 */

import { type JSX, useEffect, useState } from 'react';

import { type BookingsClient, createBookingsClient } from '../../core/index.js';
import { BOOKING_SLOT_TYPE, BookingSlotConfigKeys, readBookingSlotConfig } from './config.js';
import type { CustomFieldSettingsProps, FormFieldLike } from './types.js';

/**
 * How {@link createBookingSlotSettings} builds the panel.
 */
export interface BookingSlotSettingsOptions {
	/** The bookings public API base URL the service list is fetched from. */
	baseUrl: string;

	/** A ready-made client, used in preference to `baseUrl` (chiefly for tests). */
	client?: BookingsClient;
}

/** The field types a booking's contacts cannot be mapped onto. */
const UNMAPPABLE_TYPES = [BOOKING_SLOT_TYPE, 'heading', 'paragraph', 'divider', 'html'];

/**
 * Builds the booking_slot settings panel bound to a host's API options.
 *
 * @param options - The API base URL (or a client), for the service list.
 * @returns The settings component forms registers for `booking_slot`.
 */
export function createBookingSlotSettings(
	options: BookingSlotSettingsOptions,
): (props: CustomFieldSettingsProps) => JSX.Element {
	return function BookingSlotSettings({ field, allFields, updateField }: CustomFieldSettingsProps): JSX.Element {
		const config = readBookingSlotConfig(field.field_config);
		const selectedSlugs = Array.isArray(config.service_slugs) ? config.service_slugs : [];

		const [services, setServices] = useState<Array<{ slug: string; name: string }>>([]);
		const [loading, setLoading] = useState(true);

		useEffect(() => {
			const client = options.client ?? createBookingsClient({ baseUrl: options.baseUrl });
			let live = true;

			void client
				.listServices()
				.then((loaded) => {
					if (live) {
						setServices(loaded.map((service) => ({ slug: service.slug, name: service.name })));
					}
				})
				.catch(() => {
					// A failed load leaves the list empty; the panel still renders
					// its mappings, and the builder is not blocked by an API hiccup.
				})
				.finally(() => {
					if (live) {
						setLoading(false);
					}
				});

			return () => {
				live = false;
			};
		}, []);

		const patch = (data: Record<string, unknown>): void => {
			updateField({ field_config: { ...config, ...data } });
		};

		const toggleService = (slug: string): void => {
			const next = selectedSlugs.includes(slug)
				? selectedSlugs.filter((entry) => entry !== slug)
				: [...selectedSlugs, slug];
			patch({ [BookingSlotConfigKeys.services]: next });
		};

		const mappable = mappableFields(allFields, field);

		return (
			<div className="apbk-booking-settings">
				<p className="apbk-booking-settings-heading">Appointment</p>

				<div className="apbk-booking-settings-services">
					<span className="apbk-label">Services</span>

					{loading && <p className="apbk-loading">Loading services…</p>}

					{!loading && services.length === 0 && (
						<p className="apbk-empty">No services are available to book.</p>
					)}

					{services.map((service) => (
						<label key={service.slug} className="apbk-booking-settings-service">
							<input
								type="checkbox"
								checked={selectedSlugs.includes(service.slug)}
								onChange={() => {
									toggleService(service.slug);
								}}
							/>
							<span>{service.name}</span>
						</label>
					))}

					<p className="apbk-booking-settings-hint">
						Choose one or more. With several, the visitor picks the service first.
					</p>
				</div>

				<FieldMapSelect
					label="Name Form Field"
					value={config.name_field}
					options={mappable}
					onChange={(value) => {
						patch({ [BookingSlotConfigKeys.nameField]: value });
					}}
				/>
				<FieldMapSelect
					label="Email Form Field"
					value={config.email_field}
					options={mappable}
					onChange={(value) => {
						patch({ [BookingSlotConfigKeys.emailField]: value });
					}}
				/>
				<FieldMapSelect
					label="Phone Form Field"
					value={config.phone_field}
					options={mappable}
					onChange={(value) => {
						patch({ [BookingSlotConfigKeys.phoneField]: value });
					}}
				/>
				<FieldMapSelect
					label="Opt-In Form Field"
					value={config.optin_field}
					options={mappable}
					onChange={(value) => {
						patch({ [BookingSlotConfigKeys.optinField]: value });
					}}
				/>
			</div>
		);
	};
}

/**
 * The form's other fields a booking's contacts can be mapped onto.
 *
 * Mirrors `BookingSlotField::formFieldOptions()`: the booking_slot field itself
 * and the layout elements that collect no answer are left out, so the mapping
 * selects only offer fields that actually carry a value.
 *
 * @param allFields - Every field in the form.
 * @param field - The booking_slot field being edited, excluded from the result.
 * @returns The mappable fields.
 */
function mappableFields(allFields: FormFieldLike[], field: FormFieldLike): FormFieldLike[] {
	return allFields.filter((candidate) => {
		const name = candidate.name ?? '';

		return name !== '' && name !== field.name && !UNMAPPABLE_TYPES.includes(candidate.type);
	});
}

/**
 * A field-mapping select, populated from the form's other fields.
 */
function FieldMapSelect({
	label,
	value,
	options,
	onChange,
}: {
	label: string;
	value?: string;
	options: FormFieldLike[];
	onChange: (value: string) => void;
}): JSX.Element {
	return (
		<label className="apbk-booking-settings-map">
			<span className="apbk-label">{label}</span>
			<select
				className="apbk-select"
				value={value ?? ''}
				onChange={(event) => {
					onChange(event.target.value);
				}}
			>
				<option value="">Select a field</option>
				{options.map((option) => (
					<option key={option.id} value={option.name}>
						{option.label ?? option.name}
					</option>
				))}
			</select>
		</label>
	);
}
