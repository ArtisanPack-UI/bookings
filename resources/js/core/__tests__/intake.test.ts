import { describe, expect, it } from 'vitest';

import { answersFor, isMultiAnswer } from '../intake.js';
import type { BookingFlowState } from '../booking-flow.js';

function state(intake: Record<string, unknown>): BookingFlowState {
	return { intake } as unknown as BookingFlowState;
}

describe('isMultiAnswer', () => {
	it('is true for the array-valued field types', () => {
		expect(isMultiAnswer('multiselect')).toBe(true);
		expect(isMultiAnswer('checkboxes')).toBe(true);
	});

	it('is false for single-answer field types', () => {
		expect(isMultiAnswer('text')).toBe(false);
		expect(isMultiAnswer('select')).toBe(false);
		expect(isMultiAnswer('')).toBe(false);
	});
});

describe('answersFor', () => {
	it('reads an array answer as strings', () => {
		expect(answersFor(state({ topics: ['a', 'b'] }), 'topics')).toEqual(['a', 'b']);
	});

	it('is an empty array when the field holds no array', () => {
		expect(answersFor(state({ topics: 'a' }), 'topics')).toEqual([]);
		expect(answersFor(state({}), 'topics')).toEqual([]);
	});

	it('coerces non-string entries to strings', () => {
		expect(answersFor(state({ topics: [1, 2] }), 'topics')).toEqual(['1', '2']);
	});
});
