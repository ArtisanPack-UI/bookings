/**
 * The slot step of the React booking widget.
 *
 * A month to browse, the days that have openings in it, and the times behind
 * the chosen day. The month heading and every time are drawn in the flow's
 * display timezone, so the customer reads them on their own clock.
 *
 * @packageDocumentation
 */

import type { JSX } from 'react';

import { type BookingFlow, type BookingFlowState, formatDate, formatTime, monthLabel } from '../core/index.js';

/**
 * The slot step's props.
 */
export interface AvailabilityCalendarProps {
	/**
	 * The flow driving the widget.
	 */
	flow: BookingFlow;

	/**
	 * The flow's current snapshot.
	 */
	state: BookingFlowState;
}

/**
 * Draws the slot step.
 *
 * @param props - The flow and its snapshot.
 * @returns The calendar and slot list.
 */
export function AvailabilityCalendar({ flow, state }: AvailabilityCalendarProps): JSX.Element {
	const chosenDay = state.slotDays.find((day) => day.day === state.selectedDay) ?? null;

	return (
		<div className="apbk-calendar">
			<div className="apbk-calendar-nav">
				<button
					type="button"
					className="apbk-month-prev"
					aria-label="Previous month"
					onClick={() => {
						void flow.shiftMonth(-1);
					}}
				>
					‹
				</button>

				<span className="apbk-month-label">{monthLabel(state.month, state.locale)}</span>

				<button
					type="button"
					className="apbk-month-next"
					aria-label="Next month"
					onClick={() => {
						void flow.shiftMonth(1);
					}}
				>
					›
				</button>
			</div>

			{state.loading && <p className="apbk-loading">Loading times…</p>}

			{!state.loading && state.slotDays.length === 0 && (
				<p className="apbk-empty">No times are available this month.</p>
			)}

			{state.slotDays.length > 0 && (
				<ul className="apbk-day-list">
					{state.slotDays.map((day) => {
						const first = day.slots[0];
						const label =
							first !== undefined ? formatDate(first.start, state.timezone, state.locale) : day.day;

						return (
							<li key={day.day}>
								<button
									type="button"
									className="apbk-day"
									aria-pressed={state.selectedDay === day.day}
									onClick={() => {
										flow.selectDay(day.day);
									}}
								>
									{label}
								</button>
							</li>
						);
					})}
				</ul>
			)}

			{chosenDay !== null && (
				<ul className="apbk-slot-list">
					{chosenDay.slots.map((slot) => (
						<li key={`${slot.start}-${slot.provider_id ?? 'any'}`}>
							<button
								type="button"
								className="apbk-slot"
								aria-pressed={state.selectedSlot?.start === slot.start}
								onClick={() => {
									flow.selectSlot(slot);
								}}
							>
								{formatTime(slot.start, state.timezone, state.locale)}
							</button>
						</li>
					))}
				</ul>
			)}
		</div>
	);
}
