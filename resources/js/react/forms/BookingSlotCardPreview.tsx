/**
 * The booking_slot builder-canvas preview for a React forms host.
 *
 * The React counterpart of `BookingSlotField::preview()`: the "Choose a time"
 * widget the person building the form sees on the field's card, and the
 * "No service is configured…" notice when the field has no service yet. It draws
 * from `field_config` alone, with no API call, because a builder canvas shows
 * many cards at once and a preview is a hint, not a live picker.
 *
 * @packageDocumentation
 */

import type { JSX } from 'react';

import { configuredServiceSlugs, readBookingSlotConfig } from './config.js';
import type { FieldCardPreviewProps } from './types.js';

/**
 * Draws the booking_slot card preview.
 *
 * @param props - The field being previewed.
 * @returns The preview.
 */
export function BookingSlotCardPreview({ field }: FieldCardPreviewProps): JSX.Element {
	const config = readBookingSlotConfig(field.field_config);
	const slugs = configuredServiceSlugs(config);
	const label = field.label ?? '';
	const heading = label !== '' ? label : 'Choose a time';

	if (slugs.length === 0) {
		return (
			<div className="apbk-booking-preview">
				<p className="apbk-booking-preview-heading">{heading}</p>
				<p className="apbk-empty apbk-booking-slot-empty">
					No service is configured for this booking field yet.
				</p>
			</div>
		);
	}

	return (
		<div className="apbk-booking-preview">
			<p className="apbk-booking-preview-heading">{heading}</p>
			<div className="apbk-booking-preview-card">
				<p className="apbk-booking-preview-service">
					{slugs.length === 1 ? '1 service' : `${slugs.length} services`}
				</p>
				<div className="apbk-booking-preview-calendar">
					<span>Select a date</span>
					<span className="apbk-booking-preview-month">‹ Month ›</span>
				</div>
			</div>
		</div>
	);
}
