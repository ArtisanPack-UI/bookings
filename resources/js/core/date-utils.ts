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

/**
 * Gets a zone's offset from UTC, in milliseconds, at a given instant.
 *
 * The offset is `zone wall clock − UTC` at that moment, so it already accounts
 * for daylight saving. Derived by formatting the instant in the zone and
 * reading the wall-clock components back — the only offset a browser exposes
 * for an arbitrary IANA zone.
 *
 * @param timestamp - The instant, in epoch milliseconds.
 * @param timezone - The IANA zone.
 * @returns The offset in milliseconds.
 */
function zoneOffsetMs(timestamp: number, timezone: string): number {
	const parts = new Intl.DateTimeFormat('en-US', {
		timeZone: timezone,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
		hourCycle: 'h23',
	}).formatToParts(new Date(timestamp));

	const lookup = (type: Intl.DateTimeFormatPartTypes): number =>
		Number(parts.find((part) => part.type === type)?.value ?? '0');

	const asUtc = Date.UTC(
		lookup('year'),
		lookup('month') - 1,
		lookup('day'),
		lookup('hour'),
		lookup('minute'),
		lookup('second'),
	);

	return asUtc - timestamp;
}

/**
 * Renders an instant as the `datetime-local` value for a given zone.
 *
 * The shape an `<input type="datetime-local">` reads and writes —
 * `YYYY-MM-DDTHH:mm` — with the wall clock resolved in the target zone rather
 * than the browser's, so a widget can seed the reschedule field with the time
 * the customer already has in the zone the rest of the widget shows.
 *
 * @param iso - An ISO 8601 instant.
 * @param timezone - The IANA zone to resolve the wall clock in.
 * @returns The local datetime string, e.g. `2025-09-01T09:00`.
 */
export function instantToZonedInput(iso: string, timezone: string): string {
	const parts = new Intl.DateTimeFormat('en-CA', {
		timeZone: timezone,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
		hourCycle: 'h23',
	}).formatToParts(parseInstant(iso));

	const lookup = (type: Intl.DateTimeFormatPartTypes): string =>
		parts.find((part) => part.type === type)?.value ?? '';

	return `${lookup('year')}-${lookup('month')}-${lookup('day')}T${lookup('hour')}:${lookup('minute')}`;
}

/**
 * Reads a `datetime-local` value as an instant in a given zone.
 *
 * The inverse of {@link instantToZonedInput}: a `datetime-local` value carries
 * no zone, so this reads its wall clock as being in `timezone` and resolves the
 * UTC instant it names — the instant the customer means when they pick a time
 * in the zone the widget renders. The zone offset is taken at the resolved
 * instant and re-checked once, so a time that lands on a daylight-saving change
 * still maps to the right side of it.
 *
 * @param input - A `datetime-local` value, `YYYY-MM-DDTHH:mm` (seconds optional).
 * @param timezone - The IANA zone the wall clock is read in.
 * @returns The instant, as an ISO 8601 UTC string.
 * @throws RangeError When the value is not a `datetime-local` string.
 */
export function zonedInputToInstant(input: string, timezone: string): string {
	const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(input.trim());

	if (match === null) {
		throw new RangeError(`Cannot parse "${input}" as a datetime-local value.`);
	}

	const [year, month, day, hour, minute, second] = match
		.slice(1)
		.map((part) => Number(part ?? '0'));

	const wallAsUtc = Date.UTC(
		year as number,
		(month as number) - 1,
		day as number,
		hour as number,
		minute as number,
		second ?? 0,
	);

	const firstGuess = wallAsUtc - zoneOffsetMs(wallAsUtc, timezone);
	const timestamp = wallAsUtc - zoneOffsetMs(firstGuess, timezone);

	return new Date(timestamp).toISOString();
}
