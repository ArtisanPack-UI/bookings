/**
 * The intake fields of the React booking widget's details step.
 *
 * Renders the questions the chosen service asks, straight from its
 * `intake_schema`. Each field's answer is held on the flow under its own name,
 * and each field's error is looked up under `intake.<name>` — the same key the
 * flow maps a rejected `intake_data.<name>` onto.
 *
 * @packageDocumentation
 */

import type { ChangeEvent, JSX } from 'react';

import type { BookingFlow, BookingFlowState, IntakeFieldSchema } from '../core/index.js';

/**
 * The intake form's props.
 */
export interface IntakeFormProps {
	/**
	 * The flow driving the widget.
	 */
	flow: BookingFlow;

	/**
	 * The flow's current snapshot.
	 */
	state: BookingFlowState;
}

/**
 * Whether a field type collects more than one answer.
 */
function isMultiAnswer(type: string): boolean {
	return type === 'multiselect' || type === 'checkboxes';
}

/**
 * Reads the current answers for a multi-answer field as an array.
 */
function answersFor(state: BookingFlowState, name: string): string[] {
	const value = state.intake[name];

	return Array.isArray(value) ? value.map((entry) => String(entry)) : [];
}

/**
 * Draws the service's intake fields.
 *
 * @param props - The flow and its snapshot.
 * @returns The intake fields, or an empty fragment when the service asks none.
 */
export function IntakeForm({ flow, state }: IntakeFormProps): JSX.Element | null {
	const fields = state.selectedService?.intake_schema?.fields ?? [];

	if (fields.length === 0) {
		return null;
	}

	return (
		<div className="apbk-intake">
			{fields.map((field) => (
				<IntakeField key={field.name} field={field} flow={flow} state={state} />
			))}
		</div>
	);
}

/**
 * One intake field, dispatched on its declared type.
 */
function IntakeField({
	field,
	flow,
	state,
}: {
	field: IntakeFieldSchema;
	flow: BookingFlow;
	state: BookingFlowState;
}): JSX.Element {
	const label = field.label ?? field.name;
	const error = state.errors[`intake.${field.name}`]?.[0];
	const fieldId = `apbk-intake-${field.name}`;
	const options = field.options ?? [];
	const value = state.intake[field.name];

	function toggleAnswer(option: string, checked: boolean): void {
		const current = answersFor(state, field.name);
		const next = checked ? [...current, option] : current.filter((entry) => entry !== option);

		flow.setIntake(field.name, next);
	}

	return (
		<div className="apbk-field">
			{field.type !== 'checkbox' && (
				<label className="apbk-label" htmlFor={fieldId}>
					{label}
					{field.required === true && <span className="apbk-required"> *</span>}
				</label>
			)}

			{field.type === 'textarea' && (
				<textarea
					id={fieldId}
					className="apbk-input"
					value={typeof value === 'string' ? value : ''}
					onChange={(event: ChangeEvent<HTMLTextAreaElement>) => {
						flow.setIntake(field.name, event.target.value);
					}}
				/>
			)}

			{field.type === 'select' && (
				<select
					id={fieldId}
					className="apbk-input"
					value={typeof value === 'string' ? value : ''}
					onChange={(event: ChangeEvent<HTMLSelectElement>) => {
						flow.setIntake(field.name, event.target.value);
					}}
				>
					<option value="">Choose…</option>
					{options.map((option) => (
						<option key={option} value={option}>
							{option}
						</option>
					))}
				</select>
			)}

			{field.type === 'radio' && (
				<div className="apbk-options">
					{options.map((option) => (
						<label key={option} className="apbk-option">
							<input
								type="radio"
								name={fieldId}
								value={option}
								checked={value === option}
								onChange={() => {
									flow.setIntake(field.name, option);
								}}
							/>
							{option}
						</label>
					))}
				</div>
			)}

			{isMultiAnswer(field.type) && (
				<div className="apbk-options">
					{options.map((option) => (
						<label key={option} className="apbk-option">
							<input
								type="checkbox"
								value={option}
								checked={answersFor(state, field.name).includes(option)}
								onChange={(event: ChangeEvent<HTMLInputElement>) => {
									toggleAnswer(option, event.target.checked);
								}}
							/>
							{option}
						</label>
					))}
				</div>
			)}

			{field.type === 'checkbox' && (
				<label className="apbk-option" htmlFor={fieldId}>
					<input
						id={fieldId}
						type="checkbox"
						checked={value === true}
						onChange={(event: ChangeEvent<HTMLInputElement>) => {
							flow.setIntake(field.name, event.target.checked);
						}}
					/>
					{label}
					{field.required === true && <span className="apbk-required"> *</span>}
				</label>
			)}

			{['text', 'email', 'number', 'date', 'tel'].includes(field.type) && (
				<input
					id={fieldId}
					className="apbk-input"
					type={field.type}
					value={typeof value === 'string' || typeof value === 'number' ? String(value) : ''}
					onChange={(event: ChangeEvent<HTMLInputElement>) => {
						flow.setIntake(
							field.name,
							field.type === 'number' && event.target.value !== ''
								? Number(event.target.value)
								: event.target.value,
						);
					}}
				/>
			)}

			{error !== undefined && <p className="apbk-error">{error}</p>}
		</div>
	);
}
