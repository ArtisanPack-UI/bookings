/**
 * The Vue self-serve manage widget.
 *
 * The page behind a confirmation link: what was booked, and — while the
 * booking's `meta` still allows them — the cancel and reschedule actions. The
 * widget only ever offers what the meta permits, so it can never present an
 * action the backend will refuse.
 *
 * @packageDocumentation
 */

import { defineComponent, h, type PropType, ref, useId, type VNode } from 'vue';

import {
	formatDate,
	formatTime,
	instantToZonedInput,
	zonedInputToInstant,
} from '../core/index.js';
import type { BookingsClient } from '../core/index.js';
import { useManageBooking } from './useManageBooking.js';

/**
 * Draws the manage widget.
 */
export const ManageBooking = defineComponent({
	name: 'ManageBooking',
	props: {
		token: { type: String, required: true },
		client: { type: Object as PropType<BookingsClient>, default: undefined },
		baseUrl: { type: String, default: undefined },
		timezone: { type: String, default: undefined },
		locale: { type: String, default: undefined },
	},
	setup(props) {
		const { state, flow } = useManageBooking({
			token: props.token,
			client: props.client,
			baseUrl: props.baseUrl,
			timezone: props.timezone,
			locale: props.locale,
		});

		const reason = ref('');
		const when = ref('');
		const whenError = ref<string | null>(null);
		const idPrefix = `${useId() ?? 'apbk'}-`;

		return (): VNode => {
			const snapshot = state.value;
			const children: (VNode | null)[] = [];

			if (snapshot.view === 'loading') {
				children.push(h('p', { class: 'apbk-loading' }, 'Loading your booking…'));
			} else if (snapshot.view === 'error') {
				children.push(
					h(
						'p',
						{ class: 'apbk-error apbk-error-general' },
						snapshot.error ?? 'This booking could not be loaded.',
					),
				);
			} else if (snapshot.booking !== null) {
				const booking = snapshot.booking;
				const inner: (VNode | null)[] = [
					h('h3', { class: 'apbk-step-title' }, booking.service.name ?? ''),
					booking.start_time !== null
						? h(
								'p',
								{ class: 'apbk-manage-when' },
								`${formatDate(booking.start_time, snapshot.timezone, flow.locale)} at ${formatTime(booking.start_time, snapshot.timezone, flow.locale)}`,
							)
						: null,
					h('p', { class: 'apbk-manage-status', 'data-status': booking.status }, booking.status),
					snapshot.error !== null
						? h('p', { class: 'apbk-error apbk-error-general' }, snapshot.error)
						: null,
				];

				if (snapshot.view === 'cancelled') {
					inner.push(
						h('p', { class: 'apbk-manage-cancelled' }, 'This booking has been cancelled.'),
					);
				}

				if (snapshot.view === 'view') {
					const actions: (VNode | null)[] = [];

					if (snapshot.meta?.can_reschedule === true) {
						actions.push(
							h(
								'button',
								{
									type: 'button',
									class: 'apbk-reschedule-start',
									onClick: () => {
										// Seed the field with the current time, drawn in the same
										// zone the rest of the widget shows it in.
										when.value =
											booking.start_time !== null
												? instantToZonedInput(booking.start_time, snapshot.timezone)
												: '';
										whenError.value = null;
										flow.startReschedule();
									},
								},
								'Reschedule',
							),
						);
					}

					if (snapshot.meta?.can_cancel === true) {
						actions.push(
							h(
								'form',
								{
									class: 'apbk-cancel',
									onSubmit: (event: Event) => {
										event.preventDefault();
										void flow.cancel(reason.value === '' ? undefined : reason.value);
									},
								},
								[
									h(
										'label',
										{ class: 'apbk-label', for: `${idPrefix}cancel-reason` },
										'Reason (optional)',
									),
									h('textarea', {
										id: `${idPrefix}cancel-reason`,
										class: 'apbk-input',
										value: reason.value,
										onInput: (event: Event) => {
											reason.value = (event.target as HTMLTextAreaElement).value;
										},
									}),
									h(
										'button',
										{ type: 'submit', class: 'apbk-cancel-submit', disabled: snapshot.loading },
										snapshot.loading ? 'Cancelling…' : 'Cancel booking',
									),
								],
							),
						);
					}

					inner.push(h('div', { class: 'apbk-manage-actions' }, actions));
				}

				if (snapshot.view === 'reschedule') {
					inner.push(
						h(
							'form',
							{
								class: 'apbk-reschedule',
								onSubmit: (event: Event) => {
									event.preventDefault();

									let instant: string;

									try {
										// The entered wall time is read in the booking's display
										// zone, not the browser's, so the instant sent matches the
										// time the customer picked wherever they happen to be.
										instant = zonedInputToInstant(when.value, snapshot.timezone);
									} catch {
										whenError.value = 'Please choose a valid date and time.';

										return;
									}

									whenError.value = null;
									void flow.reschedule(instant);
								},
							},
							[
								h(
									'label',
									{ class: 'apbk-label', for: `${idPrefix}reschedule-when` },
									'New time',
								),
								h('input', {
									id: `${idPrefix}reschedule-when`,
									class: 'apbk-input',
									type: 'datetime-local',
									value: when.value,
									onInput: (event: Event) => {
										when.value = (event.target as HTMLInputElement).value;
									},
								}),
								whenError.value !== null
									? h('p', { class: 'apbk-error' }, whenError.value)
									: null,
								snapshot.errors.startTime?.[0] !== undefined
									? h('p', { class: 'apbk-error' }, snapshot.errors.startTime[0])
									: null,
								h('div', { class: 'apbk-actions' }, [
									h(
										'button',
										{
											type: 'button',
											class: 'apbk-reschedule-cancel',
											onClick: () => {
												flow.cancelReschedule();
											},
										},
										'Back',
									),
									h(
										'button',
										{ type: 'submit', class: 'apbk-reschedule-submit', disabled: snapshot.loading },
										snapshot.loading ? 'Rescheduling…' : 'Confirm new time',
									),
								]),
							],
						),
					);
				}

				children.push(h('div', { class: 'apbk-manage-booking' }, inner));
			}

			return h('div', { class: 'apbk-manage', 'data-view': snapshot.view }, children);
		};
	},
});
