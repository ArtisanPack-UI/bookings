/**
 * The provider step of the React booking widget.
 *
 * Shown only when the chosen service has more than one bookable provider —
 * {@link BookingWidget} decides that from the flow and does not render this
 * otherwise. Alongside the named providers it offers "No preference", which
 * leaves the assignment to the backend's strategy.
 *
 * @packageDocumentation
 */

import type { JSX } from 'react';

import type { BookingFlow, BookingFlowState } from '../core/index.js';

/**
 * The props every step component shares: the flow and its current snapshot.
 */
export interface ProviderPickerProps {
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
 * Draws the provider step.
 *
 * @param props - The flow and its snapshot.
 * @returns The provider picker.
 */
export function ProviderPicker({ flow, state }: ProviderPickerProps): JSX.Element {
	return (
		<div className="apbk-provider-picker">
			<h3 className="apbk-step-title">Choose a provider</h3>

			<ul className="apbk-provider-list">
				{state.providers.map((provider) => (
					<li key={provider.id}>
						<button
							type="button"
							className="apbk-provider"
							aria-pressed={state.providerChosen && state.providerId === provider.id}
							onClick={() => {
								void flow.selectProvider(provider.id);
							}}
						>
							<span className="apbk-provider-name">{provider.name}</span>
							{provider.bio !== null && (
								<span className="apbk-provider-bio">{provider.bio}</span>
							)}
						</button>
					</li>
				))}

				<li>
					<button
						type="button"
						className="apbk-provider apbk-provider-any"
						aria-pressed={state.providerChosen && state.providerId === null}
						onClick={() => {
							void flow.selectProvider(null);
						}}
					>
						No preference
					</button>
				</li>
			</ul>
		</div>
	);
}
