/**
 * The Vue booking widget.
 *
 * The whole customer-facing flow in one component — service, provider, slot,
 * details, confirmation — wired to the framework-agnostic flow through
 * {@link useBookingFlow}. Each step is a component of its own
 * ({@link ProviderPicker}, {@link AvailabilityCalendar}, {@link IntakeForm}),
 * so a consumer wanting a different layout can compose them against their own
 * {@link useBookingFlow}.
 *
 * @packageDocumentation
 */

import { defineComponent, h, type PropType, useId, type VNode } from 'vue';

import { type BookingFlow, type BookingFlowState, formatDate, formatTime } from '../core/index.js';
import type { Booking } from '../core/index.js';
import { AvailabilityCalendar } from './AvailabilityCalendar.js';
import { IntakeForm } from './IntakeForm.js';
import { ProviderPicker } from './ProviderPicker.js';
import { useBookingFlow } from './useBookingFlow.js';

/**
 * A labelled text input with an inline error.
 */
function field(
	flow: BookingFlow,
	state: BookingFlowState,
	name: 'customerName' | 'customerEmail' | 'customerPhone',
	label: string,
	type: string,
	required: boolean,
	idPrefix: string,
): VNode {
	const id = `${idPrefix}${name}`;
	const error = state.errors[name]?.[0];

	return h('div', { class: 'apbk-field' }, [
		h('label', { class: 'apbk-label', for: id }, [
			label,
			required ? h('span', { class: 'apbk-required' }, ' *') : null,
		]),
		h('input', {
			id,
			class: 'apbk-input',
			type,
			value: state.details[name],
			onInput: (event: Event) => {
				flow.setDetail(name, (event.target as HTMLInputElement).value);
			},
		}),
		error !== undefined ? h('p', { class: 'apbk-error' }, error) : null,
	]);
}

/**
 * The "Back" control, which asks the flow to step back one screen.
 */
function backButton(flow: BookingFlow): VNode {
	return h(
		'button',
		{
			type: 'button',
			class: 'apbk-back',
			onClick: () => {
				flow.back();
			},
		},
		'Back',
	);
}

/**
 * Draws the booking widget.
 */
export const BookingWidget = defineComponent({
	name: 'BookingWidget',
	props: {
		client: { type: Object as PropType<UseClient>, default: undefined },
		baseUrl: { type: String, default: undefined },
		service: { type: String as PropType<string | null>, default: null },
		timezone: { type: String, default: undefined },
		locale: { type: String, default: undefined },
		onBooked: { type: Function as PropType<(booking: Booking) => void>, default: undefined },
	},
	setup(props) {
		const { state, flow } = useBookingFlow({
			client: props.client,
			baseUrl: props.baseUrl,
			service: props.service,
			timezone: props.timezone,
			locale: props.locale,
			onBooked: props.onBooked,
		});

		// Namespaces this instance's field ids so two widgets on one page do not
		// mint colliding DOM ids and break their label associations.
		const idPrefix = `${useId() ?? 'apbk'}-`;

		return (): VNode => {
			const snapshot = state.value;
			const children: (VNode | null)[] = [];

			if (snapshot.error !== null) {
				children.push(h('p', { class: 'apbk-error apbk-error-general' }, snapshot.error));
			}

			if (snapshot.step === 'service') {
				children.push(
					h('div', { class: 'apbk-services' }, [
						h('h3', { class: 'apbk-step-title' }, 'Choose a service'),
						snapshot.loading && snapshot.services.length === 0
							? h('p', { class: 'apbk-loading' }, 'Loading services…')
							: null,
						h(
							'ul',
							{ class: 'apbk-service-list' },
							snapshot.services.map((service) =>
								h('li', { key: service.id }, [
									h(
										'button',
										{
											type: 'button',
											class: 'apbk-service',
											onClick: () => {
												void flow.selectService(service.slug);
											},
										},
										[
											h('span', { class: 'apbk-service-name' }, service.name),
											service.description !== null
												? h('span', { class: 'apbk-service-description' }, service.description)
												: null,
										],
									),
								]),
							),
						),
					]),
				);
			} else if (snapshot.step === 'provider') {
				children.push(h(ProviderPicker, { flow, state: snapshot }));
			} else if (snapshot.step === 'slot') {
				children.push(
					h('div', {}, [h(AvailabilityCalendar, { flow, state: snapshot }), backButton(flow)]),
				);
			} else if (snapshot.step === 'details') {
				children.push(
					h(
						'form',
						{
							class: 'apbk-details',
							onSubmit: (event: Event) => {
								event.preventDefault();
								void flow.submit();
							},
						},
						[
							h('h3', { class: 'apbk-step-title' }, 'Your details'),
							field(flow, snapshot, 'customerName', 'Name', 'text', true, idPrefix),
							field(flow, snapshot, 'customerEmail', 'Email', 'email', true, idPrefix),
							field(flow, snapshot, 'customerPhone', 'Telephone', 'tel', false, idPrefix),
							h('div', { class: 'apbk-field' }, [
								h('label', { class: 'apbk-label', for: `${idPrefix}notes` }, 'Notes'),
								h('textarea', {
									id: `${idPrefix}notes`,
									class: 'apbk-input',
									value: snapshot.details.notes,
									onInput: (event: Event) => {
										flow.setDetail('notes', (event.target as HTMLTextAreaElement).value);
									},
								}),
								snapshot.errors.notes?.[0] !== undefined
									? h('p', { class: 'apbk-error' }, snapshot.errors.notes[0])
									: null,
							]),
							h(IntakeForm, { flow, state: snapshot, idPrefix }),
							h('div', { class: 'apbk-actions' }, [
								backButton(flow),
								h(
									'button',
									{ type: 'submit', class: 'apbk-submit', disabled: snapshot.loading },
									snapshot.loading ? 'Booking…' : 'Book',
								),
							]),
						],
					),
				);
			} else if (snapshot.step === 'done' && snapshot.confirmation !== null) {
				const confirmation = snapshot.confirmation;

				children.push(
					h('div', { class: 'apbk-confirmation' }, [
						h('h3', { class: 'apbk-step-title' }, 'You’re booked'),
						h('p', { class: 'apbk-confirmation-service' }, confirmation.service.name ?? ''),
						confirmation.start_time !== null
							? h(
									'p',
									{ class: 'apbk-confirmation-when' },
									`${formatDate(confirmation.start_time, snapshot.timezone, snapshot.locale)} at ${formatTime(confirmation.start_time, snapshot.timezone, snapshot.locale)}`,
								)
							: null,
						h(
							'p',
							{ class: 'apbk-confirmation-email' },
							`A confirmation has been sent to ${confirmation.customer_email}.`,
						),
						h(
							'button',
							{
								type: 'button',
								class: 'apbk-book-another',
								onClick: () => {
									flow.bookAnother();
								},
							},
							'Book another',
						),
					]),
				);
			}

			return h('div', { class: 'apbk-widget', 'data-step': snapshot.step }, children);
		};
	},
});

/**
 * The client shape a widget may be handed, kept loose for the prop's runtime
 * `Object` type while the composable narrows it.
 */
type UseClient = NonNullable<Parameters<typeof useBookingFlow>[0]['client']>;
