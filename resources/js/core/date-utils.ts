/**
 * Slot formatting and grouping helpers.
 *
 * Every instant the API returns is a UTC ISO 8601 string; a widget always draws
 * it in some zone the customer recognises — the service's, the provider's, or
 * the browser's. These helpers do that conversion in one place, so two widgets
 * built on this core never disagree about what "9:00 AM" means for the same
 * slot.
 *
 * @packageDocumentation
 */

import type { Slot } from './types.js';

/**
 * A slot grouped under the calendar day it starts on.
 *
 * `day` is a `YYYY-MM-DD` key in the formatting zone; `slots` are the slots
 * starting on that day, in the order they were given.
 */
export interface SlotDay {
	day: string;
	slots: Slot[];
}

/**
 * Parses an ISO 8601 instant into a `Date`, or throws if it cannot.
 *
 * The API only ever emits valid UTC strings, so a failure here means a caller
 * passed something that did not come from the API — worth surfacing loudly
 * rather than formatting `Invalid Date` into the UI.
 *
 * @param iso - The ISO 8601 instant.
 * @returns The parsed instant.
 * @throws RangeError When the string is not a parseable date.
 */
function parseInstant(iso: string): Date {
	const date = new Date(iso);

	if (Number.isNaN(date.getTime())) {
		throw new RangeError(`Cannot parse "${iso}" as an ISO 8601 instant.`);
	}

	return date;
}

/**
 * Formats a slot's start time as a wall-clock time.
 *
 * @param iso - An ISO 8601 instant, typically a slot's `start` or `end`.
 * @param timezone - The IANA zone to render it in.
 * @param locale - The BCP 47 locale, defaulting to the runtime's.
 * @returns The localized time, e.g. `9:00 AM`.
 */
export function formatTime(
	iso: string,
	timezone: string,
	locale?: string,
): string {
	return new Intl.DateTimeFormat(locale, {
		timeZone: timezone,
		hour: 'numeric',
		minute: '2-digit',
	}).format(parseInstant(iso));
}

/**
 * Formats a slot's start date.
 *
 * @param iso - An ISO 8601 instant, typically a slot's `start`.
 * @param timezone - The IANA zone to render it in.
 * @param locale - The BCP 47 locale, defaulting to the runtime's.
 * @returns The localized date, e.g. `Monday, September 1`.
 */
export function formatDate(
	iso: string,
	timezone: string,
	locale?: string,
): string {
	return new Intl.DateTimeFormat(locale, {
		timeZone: timezone,
		weekday: 'long',
		month: 'long',
		day: 'numeric',
	}).format(parseInstant(iso));
}

/**
 * Formats a slot as a start–end time range.
 *
 * Uses `formatRange` so the parts a widget does not need are elided — two times
 * on the same day render as `9:00 – 9:30 AM` rather than repeating the meridiem.
 *
 * @param slot - The slot to format.
 * @param timezone - The IANA zone to render it in.
 * @param locale - The BCP 47 locale, defaulting to the runtime's.
 * @returns The localized range, e.g. `9:00 – 9:30 AM`.
 */
export function formatSlotRange(
	slot: Slot,
	timezone: string,
	locale?: string,
): string {
	return new Intl.DateTimeFormat(locale, {
		timeZone: timezone,
		hour: 'numeric',
		minute: '2-digit',
	}).formatRange(parseInstant(slot.start), parseInstant(slot.end));
}

/**
 * Gets a slot's length in whole minutes.
 *
 * @param slot - The slot to measure.
 * @returns The number of whole minutes between its start and end.
 */
export function slotDurationMinutes(slot: Slot): number {
	const start = parseInstant(slot.start).getTime();
	const end = parseInstant(slot.end).getTime();

	return Math.floor((end - start) / 60000);
}

/**
 * Gets the `YYYY-MM-DD` calendar day a slot starts on, in a given zone.
 *
 * The day is resolved in the target zone rather than UTC: a slot at
 * `2025-09-01T03:30:00Z` belongs to August 31st for a customer in New York and
 * to September 1st for one in Berlin, and the grouping has to match the day the
 * widget draws it under.
 *
 * @param iso - An ISO 8601 instant, typically a slot's `start`.
 * @param timezone - The IANA zone to resolve the day in.
 * @returns The day key, e.g. `2025-09-01`.
 */
export function toDayKey(iso: string, timezone: string): string {
	const parts = new Intl.DateTimeFormat('en-CA', {
		timeZone: timezone,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	}).formatToParts(parseInstant(iso));

	const lookup = (type: Intl.DateTimeFormatPartTypes): string =>
		parts.find((part) => part.type === type)?.value ?? '';

	return `${lookup('year')}-${lookup('month')}-${lookup('day')}`;
}

/**
 * Groups slots by the calendar day they start on, in a given zone.
 *
 * Order is preserved: the days come out in the order their first slot appears,
 * and each day's slots stay in the order they were given. A widget that already
 * receives slots ascending by start therefore gets days ascending too, without
 * a second sort.
 *
 * @param slots - The slots to group, typically a month's worth from one query.
 * @param timezone - The IANA zone to resolve each day in.
 * @returns The slots grouped under their day keys.
 */
export function groupSlotsByDay(slots: Slot[], timezone: string): SlotDay[] {
	const days = new Map<string, Slot[]>();

	for (const slot of slots) {
		const key = toDayKey(slot.start, timezone);
		const bucket = days.get(key);

		if (bucket) {
			bucket.push(slot);
		} else {
			days.set(key, [slot]);
		}
	}

	return Array.from(days, ([day, daySlots]) => ({ day, slots: daySlots }));
}
