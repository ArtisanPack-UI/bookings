import { afterEach, describe, expect, it, vi } from 'vitest';

import {
	captureTimezone,
	FALLBACK_TIMEZONE,
	isValidTimezone,
} from '../timezone.js';

describe('isValidTimezone', () => {
	it('accepts real IANA zone names', () => {
		expect(isValidTimezone('America/New_York')).toBe(true);
		expect(isValidTimezone('Europe/Berlin')).toBe(true);
		expect(isValidTimezone('UTC')).toBe(true);
	});

	it('rejects unknown or malformed zone names', () => {
		expect(isValidTimezone('Not/AZone')).toBe(false);
		expect(isValidTimezone('America/Nowhere')).toBe(false);
		expect(isValidTimezone('')).toBe(false);
		expect(isValidTimezone('   ')).toBe(false);
	});

	it('rejects non-string values', () => {
		expect(isValidTimezone(null)).toBe(false);
		expect(isValidTimezone(undefined)).toBe(false);
		expect(isValidTimezone(123)).toBe(false);
		expect(isValidTimezone({})).toBe(false);
	});
});

describe('captureTimezone', () => {
	afterEach(() => {
		vi.restoreAllMocks();
	});

	it('returns a zone the runtime can format in', () => {
		const zone = captureTimezone();

		expect(typeof zone).toBe('string');
		expect(isValidTimezone(zone)).toBe(true);
	});

	it('falls back to UTC when the runtime reports no zone', () => {
		vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(
			() =>
				({
					resolvedOptions: () => ({ timeZone: '' }),
				}) as unknown as Intl.DateTimeFormat,
		);

		expect(captureTimezone()).toBe(FALLBACK_TIMEZONE);
	});

	it('falls back to UTC when reading the zone throws', () => {
		vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(() => {
			throw new Error('no Intl here');
		});

		expect(captureTimezone()).toBe('UTC');
	});
});
