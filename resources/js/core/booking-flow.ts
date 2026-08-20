/**
 * The framework-agnostic booking flow.
 *
 * The React hook and the Vue composable are thin skins over this: it holds the
 * whole customer-facing flow — pick a service, maybe a provider, a day, a slot,
 * fill in the details, book — as a plain state machine driven by the typed
 * {@link BookingsClient}. Nothing here is React or Vue, so the two widgets can
 * never disagree about what step the customer is on or which request is in
 * flight; they only disagree about how to draw it.
 *
 * The design mirrors the Livewire `BookingWidget`: the step is derived from the
 * choices, not stored alongside them, so it cannot fall out of sync with the
 * service, provider, and slot that decide it.
 *
 * @packageDocumentation
 */

import { BookingsApiError, type BookingsClient, type ValidationErrors } from './api-client.js';
import { groupSlotsByDay, type SlotDay } from './date-utils.js';
import { captureTimezone } from './timezone.js';
import type {
	Booking,
	CreateBookingPayload,
	Provider,
	Service,
	Slot,
} from './types.js';

/**
 * The step the flow is on.
 *
 * Derived from the choices made so far, in the same order the Livewire widget
 * derives it: a confirmed booking is `done`, no service is `service`, and so on
 * down to `details` once a slot is chosen.
 */
export type BookingStep = 'service' | 'provider' | 'slot' | 'details' | 'done';

/**
 * The customer's own contact fields.
 */
export interface BookingDetails {
	customerName: string;
	customerEmail: string;
	customerPhone: string;
	notes: string;
}

/**
 * An immutable snapshot of the whole flow.
 *
 * Every field a widget draws is here; a widget reads this and calls the actions
 * on the {@link BookingFlow}, never reaching for state of its own.
 */
export interface BookingFlowState {
	/**
	 * The step the flow is on, derived from the choices below.
	 */
	step: BookingStep;

	/**
	 * The IANA zone times are rendered in, and the booking is stored against.
	 */
	timezone: string;

	/**
	 * The BCP 47 locale slot times are formatted with, when one was given.
	 */
	locale: string | undefined;

	/**
	 * Whether a request is in flight for the current step.
	 */
	loading: boolean;

	/**
	 * The bookable services, once loaded.
	 */
	services: Service[];

	/**
	 * The chosen service, or null while none is chosen.
	 */
	selectedService: Service | null;

	/**
	 * The providers the chosen service can be booked with.
	 */
	providers: Provider[];

	/**
	 * Whether the customer is asked to pick a provider at all.
	 *
	 * Only when there is a real choice — more than one provider. One or none is
	 * a screen between the customer and a time, not a decision.
	 */
	offersProviderChoice: boolean;

	/**
	 * The chosen provider's id, or null for no preference / round-robin.
	 */
	providerId: number | null;

	/**
	 * Whether the provider step has been answered.
	 *
	 * Distinct from `providerId` being null, which is also what "any provider"
	 * looks like — the flow needs to tell "not chosen yet" from "chose any".
	 */
	providerChosen: boolean;

	/**
	 * The month being browsed, as `YYYY-MM` in the display timezone.
	 */
	month: string;

	/**
	 * The bookable slots of the browsed month, grouped by day.
	 */
	slotDays: SlotDay[];

	/**
	 * The chosen day, as `YYYY-MM-DD` in the display timezone, or null.
	 */
	selectedDay: string | null;

	/**
	 * The chosen slot, or null while none is chosen.
	 */
	selectedSlot: Slot | null;

	/**
	 * The customer's contact fields.
	 */
	details: BookingDetails;

	/**
	 * The answers to the service's intake form, keyed by field name.
	 */
	intake: Record<string, unknown>;

	/**
	 * The booking, once it exists.
	 */
	confirmation: Booking | null;

	/**
	 * Field-keyed validation messages from the last refused submission.
	 *
	 * Keyed by the flow's own field names — `customerName`, `slot`,
	 * `intake.<field>` — not the API's, so a widget looks each message up under
	 * the same name it bound the input to.
	 */
	errors: ValidationErrors;

	/**
	 * A general error not tied to a field, e.g. a slot that was taken.
	 */
	error: string | null;
}

/**
 * How a {@link BookingFlow} is built.
 */
export interface BookingFlowOptions {
	/**
	 * The client the flow drives.
	 */
	client: BookingsClient;

	/**
	 * The slug of the service to pin the flow to, or null to let the customer
	 * choose. A pinned flow never shows the service list and never leaves it.
	 */
	pinnedServiceSlug?: string | null;

	/**
	 * The IANA zone to render times in. Defaults to the browser's.
	 */
	timezone?: string;

	/**
	 * The BCP 47 locale to format times with. Defaults to the runtime's.
	 */
	locale?: string;

	/**
	 * Called with the booking once it is made.
	 */
	onBooked?: (booking: Booking) => void;
}

/**
 * The flow's action surface.
 *
 * `getState` returns the current immutable snapshot; `subscribe` registers a
 * listener called after every change, returning an unsubscribe. The rest drive
 * the flow forward and are safe to call from a template.
 */
export interface BookingFlow {
	getState(): BookingFlowState;
	subscribe(listener: () => void): () => void;
	start(): Promise<void>;
	selectService(slug: string): Promise<void>;
	selectProvider(providerId: number | null): Promise<void>;
	shiftMonth(delta: number): Promise<void>;
	selectDay(day: string | null): void;
	selectSlot(slot: Slot | null): void;
	setDetail(field: keyof BookingDetails, value: string): void;
	setIntake(name: string, value: unknown): void;
	back(): void;
	submit(): Promise<void>;
	bookAnother(): void;
}

/**
 * Names the month a `Date` falls in, in a given zone, as `YYYY-MM`.
 */
function monthKey(date: Date, timezone: string): string {
	const parts = new Intl.DateTimeFormat('en-CA', {
		timeZone: timezone,
		year: 'numeric',
		month: '2-digit',
	}).formatToParts(date);

	const lookup = (type: Intl.DateTimeFormatPartTypes): string =>
		parts.find((part) => part.type === type)?.value ?? '';

	return `${lookup('year')}-${lookup('month')}`;
}

/**
 * Moves a `YYYY-MM` month key by a number of months.
 *
 * Done on the numbers rather than through a `Date`, so a month key never drifts
 * across a daylight-saving boundary the way adding 30 days would.
 */
function shiftMonthKey(month: string, delta: number): string {
	const [yearPart, monthPart] = month.split('-');
	const year = Number(yearPart);
	const zeroBased = Number(monthPart) - 1;

	if (!Number.isInteger(year) || !Number.isInteger(zeroBased)) {
		return month;
	}

	const total = year * 12 + zeroBased + delta;
	const newYear = Math.floor(total / 12);
	const newMonth = String((total % 12) + 1).padStart(2, '0');

	return `${newYear}-${newMonth}`;
}

/**
 * Maps an API validation key onto the flow's own field name.
 *
 * The write endpoint answers a 422 keyed by the request's names —
 * `customer_name`, `start_time`, `intake_data.age` — while the flow, and so the
 * widget, works in its own — `customerName`, `slot`, `intake.age`. Translating
 * once here lets the widget ask about one set of names.
 */
function mapErrorKey(key: string): string {
	const direct: Record<string, string> = {
		service_slug: 'service',
		provider_id: 'provider',
		start_time: 'slot',
		customer_name: 'customerName',
		customer_email: 'customerEmail',
		customer_phone: 'customerPhone',
		customer_timezone: 'timezone',
		notes: 'notes',
	};

	if (key in direct) {
		return direct[key] as string;
	}

	if (key.startsWith('intake_data.')) {
		return `intake.${key.slice('intake_data.'.length)}`;
	}

	return key;
}

/**
 * Re-keys an API error bag onto the flow's field names.
 */
function normaliseErrors(errors: ValidationErrors): ValidationErrors {
	const mapped: ValidationErrors = {};

	for (const [key, messages] of Object.entries(errors)) {
		mapped[mapErrorKey(key)] = messages;
	}

	return mapped;
}

/**
 * Creates a booking flow bound to one client.
 *
 * The flow starts empty; call {@link BookingFlow.start} to load the services
 * (or, when pinned, the one service and its providers). Everything after is
 * driven by the customer through the action methods.
 *
 * @param options - The client and the pinning, zone, and locale choices.
 * @returns The flow.
 */
export function createBookingFlow(options: BookingFlowOptions): BookingFlow {
	const { client, onBooked } = options;
	const pinnedServiceSlug =
		typeof options.pinnedServiceSlug === 'string' && options.pinnedServiceSlug.trim() !== ''
			? options.pinnedServiceSlug.trim()
			: null;
	const timezone =
		typeof options.timezone === 'string' && options.timezone.trim() !== ''
			? options.timezone
			: captureTimezone();

	const listeners = new Set<() => void>();

	// Bumped before each async load so a response that arrives after the
	// customer has moved on — a slower slot fetch for a month they have already
	// clicked past — is recognised as stale and dropped rather than painting an
	// older month's slots over the newer one.
	let slotsRequest = 0;

	let state: BookingFlowState = {
		step: 'service',
		timezone,
		locale: options.locale,
		loading: false,
		services: [],
		selectedService: null,
		providers: [],
		offersProviderChoice: false,
		providerId: null,
		providerChosen: false,
		month: monthKey(new Date(), timezone),
		slotDays: [],
		selectedDay: null,
		selectedSlot: null,
		details: {
			customerName: '',
			customerEmail: '',
			customerPhone: '',
			notes: '',
		},
		intake: {},
		confirmation: null,
		errors: {},
		error: null,
	};

	/**
	 * Works the step out from the choices, the way the Livewire widget does.
	 */
	function deriveStep(next: BookingFlowState): BookingStep {
		if (next.confirmation !== null) {
			return 'done';
		}

		if (next.selectedService === null) {
			return 'service';
		}

		if (next.offersProviderChoice && !next.providerChosen) {
			return 'provider';
		}

		return next.selectedSlot === null ? 'slot' : 'details';
	}

	function notify(): void {
		for (const listener of listeners) {
			listener();
		}
	}

	function setState(patch: Partial<BookingFlowState>): void {
		const merged = { ...state, ...patch };
		merged.step = deriveStep(merged);
		state = merged;
		notify();
	}

	/**
	 * Loads the browsed month's slots for the current service and provider.
	 *
	 * A round-robin service with no provider chosen still has slots — the
	 * backend assigns one — so this runs whenever a service is settled, whether
	 * or not a provider was picked.
	 */
	async function loadSlots(): Promise<void> {
		const service = state.selectedService;

		if (service === null) {
			return;
		}

		const request = ++slotsRequest;

		setState({ loading: true, error: null });

		try {
			const slots = await client.listSlots(service.slug, {
				date: state.month,
				providerId: state.providerId,
			});

			if (request !== slotsRequest) {
				return;
			}

			const slotDays = groupSlotsByDay(slots, state.timezone);
			const selectedDay =
				state.selectedDay !== null && slotDays.some((day) => day.day === state.selectedDay)
					? state.selectedDay
					: (slotDays[0]?.day ?? null);

			setState({ loading: false, slotDays, selectedDay });
		} catch (error) {
			if (request !== slotsRequest) {
				return;
			}

			setState({
				loading: false,
				slotDays: [],
				selectedDay: null,
				error: messageFor(error),
			});
		}
	}

	/**
	 * Pulls a human-readable message out of whatever a client method threw.
	 */
	function messageFor(error: unknown): string {
		if (error instanceof BookingsApiError) {
			return error.message;
		}

		if (error instanceof Error) {
			return error.message;
		}

		return 'Something went wrong. Please try again.';
	}

	/**
	 * Settles the provider question for a service that does not ask it.
	 *
	 * A service with one provider or none needs no provider step, so it is
	 * marked answered up front and its month's slots are loaded straight away.
	 */
	async function settleService(service: Service, providers: Provider[]): Promise<void> {
		const offersProviderChoice = providers.length > 1;

		setState({
			selectedService: service,
			providers,
			offersProviderChoice,
			providerId: null,
			providerChosen: !offersProviderChoice,
			selectedSlot: null,
			selectedDay: null,
			slotDays: [],
			errors: {},
			error: null,
		});

		if (!offersProviderChoice) {
			await loadSlots();
		}
	}

	return {
		getState(): BookingFlowState {
			return state;
		},

		subscribe(listener: () => void): () => void {
			listeners.add(listener);

			return () => {
				listeners.delete(listener);
			};
		},

		async start(): Promise<void> {
			setState({ loading: true, error: null });

			try {
				const services = await client.listServices();

				if (pinnedServiceSlug !== null) {
					const pinned = services.find((service) => service.slug === pinnedServiceSlug) ?? null;

					if (pinned === null) {
						setState({ loading: false, services, error: 'That service is not available.' });

						return;
					}

					const providers = await client.listProviders(pinned.slug);

					setState({ loading: false, services });
					await settleService(pinned, providers);

					return;
				}

				// A site offering one service should not open on a list of one.
				if (services.length === 1) {
					const only = services[0] as Service;
					const providers = await client.listProviders(only.slug);

					setState({ loading: false, services });
					await settleService(only, providers);

					return;
				}

				setState({ loading: false, services });
			} catch (error) {
				setState({ loading: false, error: messageFor(error) });
			}
		},

		async selectService(slug: string): Promise<void> {
			if (pinnedServiceSlug !== null) {
				return;
			}

			const service = state.services.find((candidate) => candidate.slug === slug) ?? null;

			if (service === null) {
				return;
			}

			// The new service asks its own questions, so the old answers go.
			setState({ loading: true, intake: {}, error: null, errors: {} });

			try {
				const providers = await client.listProviders(slug);

				setState({ loading: false });
				await settleService(service, providers);
			} catch (error) {
				setState({ loading: false, error: messageFor(error) });
			}
		},

		async selectProvider(providerId: number | null): Promise<void> {
			setState({
				providerId,
				providerChosen: true,
				selectedSlot: null,
				selectedDay: null,
				slotDays: [],
			});

			await loadSlots();
		},

		async shiftMonth(delta: number): Promise<void> {
			const clamped = Math.max(-12, Math.min(12, Math.trunc(delta)));

			setState({
				month: shiftMonthKey(state.month, clamped),
				selectedSlot: null,
				selectedDay: null,
			});

			await loadSlots();
		},

		selectDay(day: string | null): void {
			setState({ selectedDay: day, selectedSlot: null });
		},

		selectSlot(slot: Slot | null): void {
			setState({ selectedSlot: slot, errors: {}, error: null });
		},

		setDetail(field: keyof BookingDetails, value: string): void {
			setState({ details: { ...state.details, [field]: value } });
		},

		setIntake(name: string, value: unknown): void {
			setState({ intake: { ...state.intake, [name]: value } });
		},

		back(): void {
			switch (state.step) {
				case 'details':
					setState({ selectedSlot: null });
					break;
				case 'slot':
					if (state.offersProviderChoice) {
						setState({ providerChosen: false, providerId: null, selectedDay: null, slotDays: [] });
					} else if (pinnedServiceSlug === null) {
						setState({
							selectedService: null,
							providers: [],
							offersProviderChoice: false,
							selectedDay: null,
							slotDays: [],
						});
					}
					break;
				case 'provider':
					if (pinnedServiceSlug === null) {
						setState({
							selectedService: null,
							providers: [],
							offersProviderChoice: false,
							providerChosen: false,
						});
					}
					break;
				default:
					break;
			}
		},

		async submit(): Promise<void> {
			const service = state.selectedService;
			const slot = state.selectedSlot;

			if (service === null || slot === null) {
				return;
			}

			setState({ loading: true, errors: {}, error: null });

			const payload: CreateBookingPayload = {
				serviceSlug: service.slug,
				startTime: slot.start,
				customerName: state.details.customerName,
				customerEmail: state.details.customerEmail,
				providerId: state.providerId ?? slot.provider_id ?? null,
				customerPhone: state.details.customerPhone === '' ? null : state.details.customerPhone,
				customerTimezone: state.timezone,
				notes: state.details.notes === '' ? null : state.details.notes,
				intakeData: state.intake,
			};

			try {
				const booking = await client.createBooking(payload);

				setState({ loading: false, confirmation: booking });
				onBooked?.(booking);
			} catch (error) {
				if (error instanceof BookingsApiError) {
					if (error.isValidation && error.errors !== undefined) {
						setState({ loading: false, errors: normaliseErrors(error.errors) });

						return;
					}

					// A taken or busy slot is no longer a choice the customer can
					// keep — drop it and send them back to the list, the way the
					// Livewire widget does on the same statuses.
					if (error.isConflict || error.isBusy) {
						setState({ loading: false, selectedSlot: null });

						// Refresh the list the customer is being sent back to, then
						// state the message over it — `loadSlots` clears `error` on
						// its way in, so the message has to be set after it settles.
						await loadSlots();
						setState({ error: error.message });

						return;
					}

					setState({ loading: false, error: error.message });

					return;
				}

				setState({ loading: false, error: messageFor(error) });
			}
		},

		bookAnother(): void {
			setState({
				confirmation: null,
				selectedSlot: null,
				selectedDay: null,
				details: {
					customerName: '',
					customerEmail: '',
					customerPhone: '',
					notes: '',
				},
				intake: {},
				errors: {},
				error: null,
			});
		},
	};
}
