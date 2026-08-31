/**
 * The public booking_slot field for a React forms host.
 *
 * The React counterpart of `BookingSlotField::render()`: a service choice, then
 * the month calendar and its slots, assembled from the widgets this package
 * already ships — {@link useBookingFlow}, {@link ProviderPicker}, and
 * {@link AvailabilityCalendar} — rather than a second copy of the flow. The
 * chosen slot is written into the form submission as the same JSON
 * `FormBookingListener` reads, so the field books through the identical listener
 * the Livewire renderer's field does.
 *
 * @packageDocumentation
 */

import { type JSX, useEffect, useMemo, useRef } from 'react';

import { AvailabilityCalendar } from '../AvailabilityCalendar.js';
import { ProviderPicker } from '../ProviderPicker.js';
import { useBookingFlow } from '../useBookingFlow.js';
import type { BookingsClient, Service } from '../../core/index.js';
import { type BookingSlotValue, configuredServiceSlugs, readBookingSlotConfig } from './config.js';
import type { FieldComponentProps } from './types.js';

/**
 * How {@link createBookingSlotField} builds the field.
 */
export interface BookingSlotFieldOptions {
	/** The bookings public API base URL the flow fetches services and slots from. */
	baseUrl: string;

	/** The IANA zone to render times in. Defaults to the browser's. */
	timezone?: string;

	/** The BCP 47 locale to format times with. */
	locale?: string;

	/** A ready-made client, used in preference to `baseUrl` (chiefly for tests). */
	client?: BookingsClient;
}

/**
 * Builds the public booking_slot renderer bound to a host's API options.
 *
 * The options are closed over here because forms passes a field renderer only
 * the field and its value — not where this package's API lives — so the base
 * URL is supplied once at registration and captured for every field the
 * component renders.
 *
 * @param options - The API base URL, and the display zone and locale.
 * @returns The renderer component forms registers for `booking_slot`.
 */
export function createBookingSlotField(
	options: BookingSlotFieldOptions,
): (props: FieldComponentProps) => JSX.Element {
	return function BookingSlotField({ field, error, onChange }: FieldComponentProps): JSX.Element {
		const config = readBookingSlotConfig(field.field_config);
		const slugs = useMemo(() => configuredServiceSlugs(config), [config]);
		// `field_config` is a loosely-typed bag, so a malformed description could be
		// a non-string; coercing here keeps a bad value from reaching JSX, where a
		// non-string child throws. Mirrors the server-side `(string)` cast.
		const description = typeof config.description === 'string' ? config.description : '';

		const { state, flow } = useBookingFlow({
			client: options.client,
			baseUrl: options.baseUrl,
			timezone: options.timezone,
			locale: options.locale,
		});

		// The configured services, in the order the flow loaded them. A slug that
		// no active service answers to is dropped, exactly as the server-side
		// `pickerServices()` drops it, so an empty result means "not configured".
		const availableServices = useMemo(
			() => state.services.filter((service) => slugs.includes(service.slug)),
			[state.services, slugs],
		);

		// A single configured service is not a choice — auto-select it so the
		// visitor lands straight on the calendar, matching the pinned Livewire
		// picker. The ref keeps the async select from firing twice before the
		// flow's state has caught up.
		const soleService: Service | undefined = availableServices.length === 1 ? availableServices[0] : undefined;
		const autoSelectedRef = useRef(false);
		useEffect(() => {
			if (state.selectedService === null && soleService !== undefined && !autoSelectedRef.current) {
				autoSelectedRef.current = true;
				void flow.selectService(soleService.slug);
			}
		}, [flow, state.selectedService, soleService]);

		// The chosen slot, written into the submission as the JSON the listener
		// reads. Only an actual pick is emitted — never a blank on mount, which
		// would clobber a value the form already held — and a later change of
		// service that clears the pick clears the value in turn.
		const onChangeRef = useRef(onChange);
		onChangeRef.current = onChange;
		const lastEmittedRef = useRef<string | null>(null);
		useEffect(() => {
			const service = state.selectedService;
			const slot = state.selectedSlot;
			let next: string | null = null;

			if (service !== null && slot !== null) {
				const value: BookingSlotValue = {
					service_slug: service.slug,
					start: slot.start,
					provider_id: slot.provider_id,
				};
				next = JSON.stringify(value);
			} else if (lastEmittedRef.current !== null) {
				next = '';
			}

			if (next !== null && next !== lastEmittedRef.current) {
				lastEmittedRef.current = next;
				onChangeRef.current(next);
			}
		}, [state.selectedService, state.selectedSlot]);

		if (slugs.length === 0) {
			return (
				<div className="apbk-field apbk-booking-slot">
					<FieldLabel field={field} />
					<p className="apbk-empty apbk-booking-slot-empty">
						No service is configured for this booking field yet.
					</p>
				</div>
			);
		}

		return (
			<div className="apbk-field apbk-booking-slot" data-step={state.step}>
				<FieldLabel field={field} />
				{description !== '' && <p className="apbk-booking-slot-description">{description}</p>}

				{state.error !== null && <p className="apbk-error apbk-error-general">{state.error}</p>}

				{state.selectedService === null ? (
					<div className="apbk-services">
						{state.loading && availableServices.length === 0 && (
							<p className="apbk-loading">Loading services…</p>
						)}

						{!state.loading && availableServices.length === 0 && (
							<p className="apbk-empty apbk-booking-slot-empty">
								No service is configured for this booking field yet.
							</p>
						)}

						<ul className="apbk-service-list">
							{availableServices.map((service) => (
								<li key={service.id}>
									<button
										type="button"
										className="apbk-service"
										onClick={() => {
											void flow.selectService(service.slug);
										}}
									>
										<span className="apbk-service-name">{service.name}</span>
										{service.description !== null && (
											<span className="apbk-service-description">{service.description}</span>
										)}
									</button>
								</li>
							))}
						</ul>
					</div>
				) : state.step === 'provider' ? (
					<ProviderPicker flow={flow} state={state} />
				) : (
					<AvailabilityCalendar flow={flow} state={state} />
				)}

				{error !== undefined && <p className="apbk-error">{error}</p>}
			</div>
		);
	};
}

/**
 * The field's label, with a required marker when the field is required.
 */
function FieldLabel({ field }: { field: FieldComponentProps['field'] }): JSX.Element | null {
	if (field.label === null || field.label === undefined || field.label === '') {
		return null;
	}

	return (
		<span className="apbk-label apbk-booking-slot-label">
			{field.label}
			{field.is_required === true && <span className="apbk-required"> *</span>}
		</span>
	);
}
