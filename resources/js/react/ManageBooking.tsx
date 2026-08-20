/**
 * The React self-serve manage widget.
 *
 * The page behind a confirmation link: what was booked, and — while the
 * booking's `meta` still allows them — the cancel and reschedule actions. The
 * widget only ever offers what the meta permits, so it can never present an
 * action the backend will refuse.
 *
 * @packageDocumentation
 */

import { type JSX, useState } from 'react';

import { formatDate, formatTime } from '../core/index.js';
import { type UseManageBookingOptions, useManageBooking } from './useManageBooking.js';

/**
 * The manage widget's props: everything {@link useManageBooking} takes.
 */
export type ManageBookingProps = UseManageBookingOptions;

/**
 * Draws the manage widget.
 *
 * @param props - The token, the client or base URL, and zone and locale.
 * @returns The widget.
 */
export function ManageBooking(props: ManageBookingProps): JSX.Element {
	const { state, flow } = useManageBooking(props);
	const [reason, setReason] = useState('');
	const [when, setWhen] = useState('');

	const booking = state.booking;

	return (
		<div className="apbk-manage" data-view={state.view}>
			{state.view === 'loading' && <p className="apbk-loading">Loading your booking…</p>}

			{state.view === 'error' && (
				<p className="apbk-error apbk-error-general">
					{state.error ?? 'This booking could not be loaded.'}
				</p>
			)}

			{booking !== null && state.view !== 'loading' && state.view !== 'error' && (
				<div className="apbk-manage-booking">
					<h3 className="apbk-step-title">{booking.service.name}</h3>

					{booking.start_time !== null && (
						<p className="apbk-manage-when">
							{formatDate(booking.start_time, state.timezone, flow.locale)}
							{' at '}
							{formatTime(booking.start_time, state.timezone, flow.locale)}
						</p>
					)}

					<p className="apbk-manage-status" data-status={booking.status}>
						{booking.status}
					</p>

					{state.error !== null && (
						<p className="apbk-error apbk-error-general">{state.error}</p>
					)}

					{state.view === 'cancelled' && (
						<p className="apbk-manage-cancelled">This booking has been cancelled.</p>
					)}

					{state.view === 'view' && (
						<div className="apbk-manage-actions">
							{state.meta?.can_reschedule === true && (
								<button
									type="button"
									className="apbk-reschedule-start"
									onClick={() => {
										flow.startReschedule();
									}}
								>
									Reschedule
								</button>
							)}

							{state.meta?.can_cancel === true && (
								<form
									className="apbk-cancel"
									onSubmit={(event) => {
										event.preventDefault();
										void flow.cancel(reason === '' ? undefined : reason);
									}}
								>
									<label className="apbk-label" htmlFor="apbk-cancel-reason">
										Reason (optional)
									</label>
									<textarea
										id="apbk-cancel-reason"
										className="apbk-input"
										value={reason}
										onChange={(event) => {
											setReason(event.target.value);
										}}
									/>
									<button type="submit" className="apbk-cancel-submit" disabled={state.loading}>
										{state.loading ? 'Cancelling…' : 'Cancel booking'}
									</button>
								</form>
							)}
						</div>
					)}

					{state.view === 'reschedule' && (
						<form
							className="apbk-reschedule"
							onSubmit={(event) => {
								event.preventDefault();
								const parsed = new Date(when);

								if (Number.isNaN(parsed.getTime())) {
									return;
								}

								void flow.reschedule(parsed.toISOString());
							}}
						>
							<label className="apbk-label" htmlFor="apbk-reschedule-when">
								New time
							</label>
							<input
								id="apbk-reschedule-when"
								className="apbk-input"
								type="datetime-local"
								value={when}
								onChange={(event) => {
									setWhen(event.target.value);
								}}
							/>
							{state.errors.start_time?.[0] !== undefined && (
								<p className="apbk-error">{state.errors.start_time[0]}</p>
							)}

							<div className="apbk-actions">
								<button
									type="button"
									className="apbk-reschedule-cancel"
									onClick={() => {
										flow.cancelReschedule();
									}}
								>
									Back
								</button>
								<button type="submit" className="apbk-reschedule-submit" disabled={state.loading}>
									{state.loading ? 'Rescheduling…' : 'Confirm new time'}
								</button>
							</div>
						</form>
					)}
				</div>
			)}
		</div>
	);
}
