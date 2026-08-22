/**
 * Browser timezone capture and validation.
 *
 * The customer's zone is theirs to volunteer, not something the server can see:
 * every instant the API returns is UTC, and it is the browser that knows which
 * wall clock the person in front of it reads. These helpers capture that once,
 * so a booking is stored against the zone the customer actually chose their
 * slot in.
 *
 * @packageDocumentation
 */

/**
 * The IANA zone name a booking is stored against when none can be detected.
 *
 * UTC rather than a guess: a wrong zone silently shifts every time the customer
 * is shown, while UTC is at least honestly zoneless.
 */
export const FALLBACK_TIMEZONE = 'UTC';

/**
 * Captures the browser's current IANA timezone.
 *
 * Reads `Intl.DateTimeFormat().resolvedOptions().timeZone`, which every engine
 * the widget targets supports. Falls back to {@link FALLBACK_TIMEZONE} if the
 * runtime returns nothing usable — some older embedded webviews leave it empty
 * rather than throwing.
 *
 * @returns The detected IANA zone name, or `UTC` when none is available.
 */
export function captureTimezone(): string {
	try {
		const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

		return zone && isValidTimezone(zone) ? zone : FALLBACK_TIMEZONE;
	} catch {
		return FALLBACK_TIMEZONE;
	}
}

/**
 * Determines whether a string is an IANA zone the runtime accepts.
 *
 * The only reliable test is to ask `Intl` to build a formatter for it: a name
 * it does not recognise throws a `RangeError`. An empty or non-string value is
 * refused without asking.
 *
 * @param timezone - The candidate zone name.
 * @returns True when the runtime can format instants in that zone.
 */
export function isValidTimezone(timezone: unknown): timezone is string {
	if (typeof timezone !== 'string' || timezone.trim() === '') {
		return false;
	}

	try {
		new Intl.DateTimeFormat('en-US', { timeZone: timezone });

		return true;
	} catch {
		return false;
	}
}
