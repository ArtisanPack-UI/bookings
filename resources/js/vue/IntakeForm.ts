/**
 * The intake fields of the Vue booking widget's details step.
 *
 * Renders the questions the chosen service asks, straight from its
 * `intake_schema`. Each answer is held on the flow under its own name, and each
 * error is looked up under `intake.<name>` — the key the flow maps a rejected
 * `intake_data.<name>` onto.
 *
 * @packageDocumentation
 */

import { defineComponent, h, type PropType, type VNode } from 'vue';

import type { BookingFlow, BookingFlowState, IntakeFieldSchema } from '../core/index.js';

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
 * Renders one intake field, dispatched on its declared type.
 */
function renderField(field: IntakeFieldSchema, flow: BookingFlow, state: BookingFlowState): VNode {
	const label = field.label ?? field.name;
	const error = state.errors[`intake.${field.name}`]?.[0];
	const fieldId = `apbk-intake-${field.name}`;
	const options = field.options ?? [];
	const value = state.intake[field.name];

	const control: (VNode | null)[] = [];

	if (field.type !== 'checkbox') {
		control.push(
			h('label', { class: 'apbk-label', for: fieldId }, [
				label,
				field.required === true ? h('span', { class: 'apbk-required' }, ' *') : null,
			]),
		);
	}

	if (field.type === 'textarea') {
		control.push(
			h('textarea', {
				id: fieldId,
				class: 'apbk-input',
				value: typeof value === 'string' ? value : '',
				onInput: (event: Event) => {
					flow.setIntake(field.name, (event.target as HTMLTextAreaElement).value);
				},
			}),
		);
	} else if (field.type === 'select') {
		control.push(
			h(
				'select',
				{
					id: fieldId,
					class: 'apbk-input',
					value: typeof value === 'string' ? value : '',
					onChange: (event: Event) => {
						flow.setIntake(field.name, (event.target as HTMLSelectElement).value);
					},
				},
				[
					h('option', { value: '' }, 'Choose…'),
					...options.map((option) => h('option', { key: option, value: option }, option)),
				],
			),
		);
	} else if (field.type === 'radio') {
		control.push(
			h(
				'div',
				{ class: 'apbk-options' },
				options.map((option) =>
					h('label', { key: option, class: 'apbk-option' }, [
						h('input', {
							type: 'radio',
							name: fieldId,
							value: option,
							checked: value === option,
							onChange: () => {
								flow.setIntake(field.name, option);
							},
						}),
						option,
					]),
				),
			),
		);
	} else if (isMultiAnswer(field.type)) {
		control.push(
			h(
				'div',
				{ class: 'apbk-options' },
				options.map((option) =>
					h('label', { key: option, class: 'apbk-option' }, [
						h('input', {
							type: 'checkbox',
							value: option,
							checked: answersFor(state, field.name).includes(option),
							onChange: (event: Event) => {
								const checked = (event.target as HTMLInputElement).checked;
								const current = answersFor(state, field.name);
								const next = checked
									? [...current, option]
									: current.filter((entry) => entry !== option);

								flow.setIntake(field.name, next);
							},
						}),
						option,
					]),
				),
			),
		);
	} else if (field.type === 'checkbox') {
		control.push(
			h('label', { class: 'apbk-option', for: fieldId }, [
				h('input', {
					id: fieldId,
					type: 'checkbox',
					checked: value === true,
					onChange: (event: Event) => {
						flow.setIntake(field.name, (event.target as HTMLInputElement).checked);
					},
				}),
				label,
				field.required === true ? h('span', { class: 'apbk-required' }, ' *') : null,
			]),
		);
	} else {
		control.push(
			h('input', {
				id: fieldId,
				class: 'apbk-input',
				type: field.type,
				value: typeof value === 'string' || typeof value === 'number' ? String(value) : '',
				onInput: (event: Event) => {
					const raw = (event.target as HTMLInputElement).value;

					flow.setIntake(field.name, field.type === 'number' && raw !== '' ? Number(raw) : raw);
				},
			}),
		);
	}

	if (error !== undefined) {
		control.push(h('p', { class: 'apbk-error' }, error));
	}

	return h('div', { key: field.name, class: 'apbk-field' }, control);
}

/**
 * Draws the service's intake fields from a flow and its snapshot.
 */
export const IntakeForm = defineComponent({
	name: 'IntakeForm',
	props: {
		flow: { type: Object as PropType<BookingFlow>, required: true },
		state: { type: Object as PropType<BookingFlowState>, required: true },
	},
	setup(props) {
		return (): VNode | null => {
			const { flow, state } = props;
			const fields = state.selectedService?.intake_schema?.fields ?? [];

			if (fields.length === 0) {
				return null;
			}

			return h(
				'div',
				{ class: 'apbk-intake' },
				fields.map((field) => renderField(field, flow, state)),
			);
		};
	},
});
