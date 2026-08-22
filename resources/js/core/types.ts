/**
 * Shared type definitions for the bookings public API.
 *
 * Every shape here mirrors a JSON resource the package's public API returns
 * (`src/Http/Resources/*` and the hand-built slot and manage envelopes). They
 * are the contract the framework-specific widgets are written against, so a
 * change to a resource is meant to break a type here first.
 *
 * @packageDocumentation
 */

/**
 * The lifecycle state a booking is in.
 *
 * Mirrors `ArtisanPackUI\Bookings\Enums\BookingStatus`.
 */
export type BookingStatus =
	| 'requested'
	| 'confirmed'
	| 'cancelled'
	| 'completed'
	| 'no_show';

/**
 * How a service decides which provider serves a booking.
 *
 * Mirrors `ArtisanPackUI\Bookings\Enums\ServiceAssignmentStrategy`. A widget
 * branches on `any` to decide whether to draw a provider picker at all.
 */
export type AssignmentStrategy = 'any' | 'round_robin' | 'default_provider';

/**
 * One field a service's intake form asks for.
 *
 * The raw, stored schema shape — looser than the validator's normalised view,
 * because the resource passes `intake_schema` through untouched. Unknown keys
 * are preserved so a newer schema does not lose data round-tripping through
 * this type.
 */
export interface IntakeFieldSchema {
	name: string;
	type: string;
	label?: string;
	required?: boolean;
	options?: string[];
	[key: string]: unknown;
}

/**
 * A service's intake form definition.
 *
 * Mirrors the `intake_schema` column: `{ fields: [ … ] }`, with the fields a
 * JSON list rather than an object keyed by name.
 */
export interface IntakeSchema {
	fields?: IntakeFieldSchema[];
	[key: string]: unknown;
}

/**
 * A service as the public widget sees it.
 *
 * Mirrors `ServiceResource`. `price` is a decimal string (e.g. `"25.00"`) or
 * null; `timezone` is always resolved to a concrete zone, never null.
 */
export interface Service {
	id: number;
	slug: string;
	name: string;
	description: string | null;
	duration: number;
	buffer_before: number;
	buffer_after: number;
	price: string | null;
	is_free: boolean;
	color: string | null;
	image_url: string | null;
	timezone: string;
	assignment_strategy: AssignmentStrategy;
	intake_schema: IntakeSchema | null;
	intake_schema_version: number;
}

/**
 * A provider as the public widget sees it.
 *
 * Mirrors `ServiceProviderResource`. Contact details are deliberately absent.
 */
export interface Provider {
	id: number;
	slug: string;
	name: string;
	bio: string | null;
	timezone: string | null;
	image_url: string | null;
}

/**
 * One bookable span of time, and who would serve it.
 *
 * Mirrors `ArtisanPackUI\Bookings\Support\Slot::toArray()`. `start` and `end`
 * are ISO 8601 instants in UTC; `provider_id` is null for a round-robin slot
 * that has not been assigned yet.
 */
export interface Slot {
	start: string;
	end: string;
	provider_id: number | null;
}

/**
 * The service reference embedded in a booking.
 *
 * Mirrors the `service` object on `BookingResource`.
 */
export interface BookingService {
	id: number;
	slug: string | null;
	name: string | null;
}

/**
 * The provider reference embedded in a booking.
 *
 * Mirrors the `provider` object on `BookingResource`. Null when the booking has
 * no provider assigned.
 */
export interface BookingProvider {
	id: number;
	slug: string | null;
	name: string | null;
}

/**
 * A booking as the customer who made or manages it sees it.
 *
 * Mirrors `BookingResource`. The manage token is deliberately never present.
 */
export interface Booking {
	id: number;
	status: BookingStatus;
	start_time: string | null;
	end_time: string | null;
	customer_name: string;
	customer_email: string;
	customer_timezone: string | null;
	service: BookingService;
	provider: BookingProvider | null;
}

/**
 * What may still be done to a managed booking.
 *
 * Mirrors the `meta` object on the manage endpoints' response. A widget reads
 * these rather than reimplementing the package's change policy.
 */
export interface BookingManageMeta {
	can_cancel: boolean;
	can_reschedule: boolean;
	changes_allowed_until: string | null;
}

/**
 * The envelope every self-serve manage action returns.
 *
 * Mirrors `ManageBookingController::respondWith()`.
 */
export interface ManagedBooking {
	data: Booking;
	meta: BookingManageMeta;
}

/**
 * The query that narrows a service's slots to one month.
 *
 * Mirrors `SlotQueryRequest`. `date` is a `YYYY-MM` month in the service's own
 * timezone; `providerId` restricts the day to a single provider.
 */
export interface SlotQuery {
	date: string;
	providerId?: number | null;
}

/**
 * The payload that creates a booking.
 *
 * Mirrors `StoreBookingRequest`. `startTime` is any string the backend can
 * parse as a date; sending the exact `start` of a resolved {@link Slot} is the
 * safe choice.
 */
export interface CreateBookingPayload {
	serviceSlug: string;
	startTime: string;
	customerName: string;
	customerEmail: string;
	providerId?: number | null;
	customerPhone?: string | null;
	customerTimezone?: string | null;
	notes?: string | null;
	intakeData?: Record<string, unknown>;
}

/**
 * The payload that cancels a managed booking.
 *
 * Mirrors `CancelBookingRequest`.
 */
export interface CancelBookingPayload {
	reason?: string | null;
}

/**
 * The payload that reschedules a managed booking.
 *
 * Mirrors `RescheduleBookingRequest`.
 */
export interface RescheduleBookingPayload {
	startTime: string;
}
