/**
 * Intake-field helpers shared by the framework widgets.
 *
 * The React and Vue details steps ask a service's intake questions from the
 * same `intake_schema`, so the two rules that decide how an answer is shaped —
 * whether a field type collects one answer or many, and how a multi-answer
 * field's current value is read back — live here rather than being duplicated in
 * each framework.
 *
 * `isMultiAnswer` mirrors a contract the server holds: the field types it names
 * are the ones `IntakeFieldValidator` (in `src/Services/IntakeFieldValidator.php`)
 * validates as arrays. The two must agree, or a widget binds a multi-answer field
 * as a scalar and the booking is refused for a question the customer can see they
 * answered — keep this list in step with the server when a field type is added.
 *
 * @packageDocumentation
 */

import type { BookingFlowState } from './booking-flow.js';

/**
 * Whether a field type collects more than one answer.
 *
 * @param type - The intake field's declared type.
 * @returns True when the field holds an array of answers.
 */
export function isMultiAnswer(type: string): boolean {
	return type === 'multiselect' || type === 'checkboxes';
}

/**
 * Reads the current answers for a multi-answer field as an array of strings.
 *
 * @param state - The flow's current snapshot.
 * @param name - The intake field's name.
 * @returns The answers, or an empty array when none are held.
 */
export function answersFor(state: BookingFlowState, name: string): string[] {
	const value = state.intake[name];

	return Array.isArray(value) ? value.map((entry) => String(entry)) : [];
}
