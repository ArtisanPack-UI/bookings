/**
 * The slot step of the Vue booking widget.
 *
 * A month to browse, the days with openings in it, and the times behind the
 * chosen day — all drawn in the flow's display timezone.
 *
 * @packageDocumentation
 */

import { defineComponent, h, type PropType, type VNode } from 'vue';

import { type BookingFlow, type BookingFlowState, formatDate, formatTime, monthLabel } from '../core/index.js';

/**
 * Draws the slot step from a flow and its snapshot.
 */
export const AvailabilityCalendar = defineComponent({
	name: 'AvailabilityCalendar',
	props: {
		flow: { type: Object as PropType<BookingFlow>, required: true },
		state: { type: Object as PropType<BookingFlowState>, required: true },
	},
	setup(props) {
		return (): VNode => {
			const { flow, state } = props;
			const chosenDay = state.slotDays.find((day) => day.day === state.selectedDay) ?? null;

			const children: (VNode | null)[] = [
				h('div', { class: 'apbk-calendar-nav' }, [
					h(
						'button',
						{
							type: 'button',
							class: 'apbk-month-prev',
							'aria-label': 'Previous month',
							onClick: () => {
								void flow.shiftMonth(-1);
							},
						},
						'‹',
					),
					h('span', { class: 'apbk-month-label' }, monthLabel(state.month, state.locale)),
					h(
						'button',
						{
							type: 'button',
							class: 'apbk-month-next',
							'aria-label': 'Next month',
							onClick: () => {
								void flow.shiftMonth(1);
							},
						},
						'›',
					),
				]),
			];

			if (state.loading) {
				children.push(h('p', { class: 'apbk-loading' }, 'Loading times…'));
			}

			if (!state.loading && state.slotDays.length === 0) {
				children.push(h('p', { class: 'apbk-empty' }, 'No times are available this month.'));
			}

			if (state.slotDays.length > 0) {
				children.push(
					h(
						'ul',
						{ class: 'apbk-day-list' },
						state.slotDays.map((day) => {
							const first = day.slots[0];
							const label =
								first !== undefined ? formatDate(first.start, state.timezone, state.locale) : day.day;

							return h('li', { key: day.day }, [
								h(
									'button',
									{
										type: 'button',
										class: 'apbk-day',
										'aria-pressed': state.selectedDay === day.day,
										onClick: () => {
											flow.selectDay(day.day);
										},
									},
									label,
								),
							]);
						}),
					),
				);
			}

			if (chosenDay !== null) {
				children.push(
					h(
						'ul',
						{ class: 'apbk-slot-list' },
						chosenDay.slots.map((slot) =>
							h('li', { key: `${slot.start}-${slot.provider_id ?? 'any'}` }, [
								h(
									'button',
									{
										type: 'button',
										class: 'apbk-slot',
										'aria-pressed': state.selectedSlot?.start === slot.start,
										onClick: () => {
											flow.selectSlot(slot);
										},
									},
									formatTime(slot.start, state.timezone, state.locale),
								),
							]),
						),
					),
				);
			}

			return h('div', { class: 'apbk-calendar' }, children);
		};
	},
});
