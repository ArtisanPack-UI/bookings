import { describe, expect, it } from 'vitest';

import {
	formatDate,
	formatSlotRange,
	formatTime,
	groupSlotsByDay,
	instantToZonedInput,
	monthLabel,
	slotDurationMinutes,
	toDayKey,
	zonedInputToInstant,
} from '../date-utils.js';
import type { Slot } from '../types.js';

function slot(start: string, end: string, providerId: number | null = null): Slot {
	return { start, end, provider_id: providerId };
}

describe('monthLabel', () => {
	it('names a YYYY-MM key as its month and year', () => {
		expect(monthLabel('2025-09', 'en-US')).toBe('September 2025');
	});

	it('returns the key unchanged when it does not parse', () => {
		expect(monthLabel('not-a-month', 'en-US')).toBe('not-a-month');
	});
});

describe('formatTime', () => {
	it('renders the wall-clock time in the given zone', () => {
		expect(formatTime('2025-09-01T13:00:00Z', 'America/New_York', 'en-US')).toBe(
			'9:00 AM',
		);
		expect(formatTime('2025-09-01T13:00:00Z', 'Europe/Berlin', 'en-US')).toBe(
			'3:00 PM',
		);
	});

	it('throws on an unparseable instant', () => {
		expect(() => formatTime('not-a-date', 'UTC', 'en-US')).toThrow(RangeError);
	});
});

describe('formatDate', () => {
	it('renders the calendar date in the given zone', () => {
		expect(formatDate('2025-09-01T13:00:00Z', 'America/New_York', 'en-US')).toBe(
			'Monday, September 1',
		);
	});

	it('resolves the date in the target zone, not UTC', () => {
		// 03:30 UTC is still August 31st in New York.
		expect(formatDate('2025-09-01T03:30:00Z', 'America/New_York', 'en-US')).toBe(
			'Sunday, August 31',
		);
	});
});

describe('formatSlotRange', () => {
	it('renders a start–end range', () => {
		const rendered = formatSlotRange(
			slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z'),
			'America/New_York',
			'en-US',
		);

		expect(rendered).toContain('9:00');
		expect(rendered).toContain('9:30');
	});
});

describe('slotDurationMinutes', () => {
	it('measures whole minutes between start and end', () => {
		expect(
			slotDurationMinutes(
				slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z'),
			),
		).toBe(30);
		expect(
			slotDurationMinutes(
				slot('2025-09-01T13:00:00Z', '2025-09-01T14:15:00Z'),
			),
		).toBe(75);
	});
});

describe('toDayKey', () => {
	it('resolves the day key in the target zone', () => {
		expect(toDayKey('2025-09-01T03:30:00Z', 'America/New_York')).toBe(
			'2025-08-31',
		);
		expect(toDayKey('2025-09-01T03:30:00Z', 'Europe/Berlin')).toBe('2025-09-01');
	});
});

describe('groupSlotsByDay', () => {
	it('groups slots under their start day, preserving order', () => {
		const slots = [
			slot('2025-09-01T13:00:00Z', '2025-09-01T13:30:00Z'),
			slot('2025-09-01T14:00:00Z', '2025-09-01T14:30:00Z'),
			slot('2025-09-02T13:00:00Z', '2025-09-02T13:30:00Z'),
		];

		const grouped = groupSlotsByDay(slots, 'America/New_York');

		expect(grouped).toHaveLength(2);
		expect(grouped[0]?.day).toBe('2025-09-01');
		expect(grouped[0]?.slots).toHaveLength(2);
		expect(grouped[1]?.day).toBe('2025-09-02');
		expect(grouped[1]?.slots).toHaveLength(1);
	});

	it('returns no days for no slots', () => {
		expect(groupSlotsByDay([], 'UTC')).toEqual([]);
	});
});

describe('instantToZonedInput', () => {
	it('renders the datetime-local wall clock in the target zone', () => {
		expect(instantToZonedInput('2025-09-01T13:00:00Z', 'America/New_York')).toBe(
			'2025-09-01T09:00',
		);
		expect(instantToZonedInput('2025-09-01T13:00:00Z', 'Europe/Berlin')).toBe('2025-09-01T15:00');
	});

	it('resolves the day in the target zone, not UTC', () => {
		expect(instantToZonedInput('2025-09-01T03:30:00Z', 'America/New_York')).toBe(
			'2025-08-31T23:30',
		);
	});
});

describe('zonedInputToInstant', () => {
	it('reads a datetime-local value as an instant in the given zone', () => {
		expect(zonedInputToInstant('2025-09-01T09:00', 'America/New_York')).toBe(
			'2025-09-01T13:00:00.000Z',
		);
		expect(zonedInputToInstant('2025-09-01T09:00', 'America/Los_Angeles')).toBe(
			'2025-09-01T16:00:00.000Z',
		);
	});

	it('accounts for standard vs daylight time', () => {
		// January is EST (UTC-5) in New York, not EDT (UTC-4).
		expect(zonedInputToInstant('2025-01-01T09:00', 'America/New_York')).toBe(
			'2025-01-01T14:00:00.000Z',
		);
	});

	it('round-trips with instantToZonedInput', () => {
		const iso = '2025-06-15T17:30:00.000Z';
		const local = instantToZonedInput(iso, 'Europe/Berlin');

		expect(zonedInputToInstant(local, 'Europe/Berlin')).toBe(iso);
	});

	it('throws on a value that is not a datetime-local string', () => {
		expect(() => zonedInputToInstant('not-a-date', 'UTC')).toThrow(RangeError);
	});
});
