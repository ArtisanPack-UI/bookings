/**
 * The React booking widget.
 *
 * The whole customer-facing flow in one component — service, provider, slot,
 * details, confirmation — wired to the framework-agnostic flow through
 * {@link useBookingFlow}. Each step is a small component of its own
 * ({@link ProviderPicker}, {@link AvailabilityCalendar}, {@link IntakeForm}),
 * so a consumer who wants a different layout can compose them directly against
 * their own {@link useBookingFlow}.
 *
 * @packageDocumentation
 */

import { type ChangeEvent, type JSX, useId } from 'react';

import { formatDate, formatTime } from '../core/index.js';
import { AvailabilityCalendar } from './AvailabilityCalendar.js';
import { IntakeForm } from './IntakeForm.js';
import { ProviderPicker } from './ProviderPicker.js';
import { type UseBookingFlowOptions, useBookingFlow } from './useBookingFlow.js';

/**
 * The booking widget's props: everything {@link useBookingFlow} takes.
 */
export type BookingWidgetProps = UseBookingFlowOptions;

/**
 * Draws the booking widget.
 *
 * @param props - The client or base URL, and the pinning, zone, and locale.
 * @returns The widget.
 */
export function BookingWidget(props: BookingWidgetProps): JSX.Element {
	const { state, flow } = useBookingFlow(props);
	// Namespaces this instance's field ids so two widgets on one page do not
	// mint colliding DOM ids and break their label associations.
	const idPrefix = `${useId()}-`;

	return (
		<div className="apbk-widget" data-step={state.step}>
			{state.error !== null && <p className="apbk-error apbk-error-general">{state.error}</p>}

			{state.step === 'service' && (
				<div className="apbk-services">
					<h3 className="apbk-step-title">Choose a service</h3>

					{state.loading && state.services.length === 0 && (
						<p className="apbk-loading">Loading services…</p>
					)}

					<ul className="apbk-service-list">
						{state.services.map((service) => (
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
			)}

			{state.step === 'provider' && <ProviderPicker flow={flow} state={state} />}

			{state.step === 'slot' && (
				<div>
					<AvailabilityCalendar flow={flow} state={state} />
					<BackButton flow={flow} />
				</div>
			)}

			{state.step === 'details' && (
				<form
					className="apbk-details"
					onSubmit={(event) => {
						event.preventDefault();
						void flow.submit();
					}}
				>
					<h3 className="apbk-step-title">Your details</h3>

					<Field
						name="customerName"
						label="Name"
						type="text"
						required
						idPrefix={idPrefix}
						value={state.details.customerName}
						error={state.errors.customerName?.[0]}
						onChange={(value) => {
							flow.setDetail('customerName', value);
						}}
					/>

					<Field
						name="customerEmail"
						label="Email"
						type="email"
						required
						idPrefix={idPrefix}
						value={state.details.customerEmail}
						error={state.errors.customerEmail?.[0]}
						onChange={(value) => {
							flow.setDetail('customerEmail', value);
						}}
					/>

					<Field
						name="customerPhone"
						label="Telephone"
						type="tel"
						idPrefix={idPrefix}
						value={state.details.customerPhone}
						error={state.errors.customerPhone?.[0]}
						onChange={(value) => {
							flow.setDetail('customerPhone', value);
						}}
					/>

					<div className="apbk-field">
						<label className="apbk-label" htmlFor={`${idPrefix}notes`}>
							Notes
						</label>
						<textarea
							id={`${idPrefix}notes`}
							className="apbk-input"
							value={state.details.notes}
							onChange={(event: ChangeEvent<HTMLTextAreaElement>) => {
								flow.setDetail('notes', event.target.value);
							}}
						/>
						{state.errors.notes?.[0] !== undefined && (
							<p className="apbk-error">{state.errors.notes[0]}</p>
						)}
					</div>

					<IntakeForm flow={flow} state={state} idPrefix={idPrefix} />

					<div className="apbk-actions">
						<BackButton flow={flow} />
						<button type="submit" className="apbk-submit" disabled={state.loading}>
							{state.loading ? 'Booking…' : 'Book'}
						</button>
					</div>
				</form>
			)}

			{state.step === 'done' && state.confirmation !== null && (
				<div className="apbk-confirmation">
					<h3 className="apbk-step-title">You’re booked</h3>
					<p className="apbk-confirmation-service">{state.confirmation.service.name}</p>
					{state.confirmation.start_time !== null && (
						<p className="apbk-confirmation-when">
							{formatDate(state.confirmation.start_time, state.timezone, state.locale)}
							{' at '}
							{formatTime(state.confirmation.start_time, state.timezone, state.locale)}
						</p>
					)}
					<p className="apbk-confirmation-email">
						A confirmation has been sent to {state.confirmation.customer_email}.
					</p>
					<button
						type="button"
						className="apbk-book-another"
						onClick={() => {
							flow.bookAnother();
						}}
					>
						Book another
					</button>
				</div>
			)}
		</div>
	);
}

/**
 * A labelled text input with an inline error.
 */
function Field({
	name,
	label,
	type,
	value,
	error,
	required,
	idPrefix,
	onChange,
}: {
	name: string;
	label: string;
	type: string;
	value: string;
	error?: string;
	required?: boolean;
	idPrefix: string;
	onChange: (value: string) => void;
}): JSX.Element {
	const id = `${idPrefix}${name}`;

	return (
		<div className="apbk-field">
			<label className="apbk-label" htmlFor={id}>
				{label}
				{required === true && <span className="apbk-required"> *</span>}
			</label>
			<input
				id={id}
				className="apbk-input"
				type={type}
				value={value}
				onChange={(event: ChangeEvent<HTMLInputElement>) => {
					onChange(event.target.value);
				}}
			/>
			{error !== undefined && <p className="apbk-error">{error}</p>}
		</div>
	);
}

/**
 * The "Back" control, which asks the flow to step back one screen.
 */
function BackButton({ flow }: { flow: ReturnType<typeof useBookingFlow>['flow'] }): JSX.Element {
	return (
		<button
			type="button"
			className="apbk-back"
			onClick={() => {
				flow.back();
			}}
		>
			Back
		</button>
	);
}
