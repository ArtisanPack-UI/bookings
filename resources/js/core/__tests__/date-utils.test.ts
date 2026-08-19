import { describe, expect, it } from 'vitest';

import {
	formatDate,
	formatSlotRange,
	formatTime,
	groupSlotsByDay,
	slotDurationMinutes,
	toDayKey,
} from '../date-utils';
import type { Slot } from '../types';

function slot(start: string, end: string, providerId: number | null = null): Slot {
	return { start, end, provider_id: providerId };
}

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
