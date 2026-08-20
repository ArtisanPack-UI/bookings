/**
 * The provider step of the Vue booking widget.
 *
 * Shown only when the chosen service has more than one bookable provider —
 * {@link BookingWidget} decides that and does not render this otherwise.
 * Alongside the named providers it offers "No preference", leaving the
 * assignment to the backend's strategy.
 *
 * @packageDocumentation
 */

import { defineComponent, h, type PropType, type VNode } from 'vue';

import type { BookingFlow, BookingFlowState } from '../core/index.js';

/**
 * Draws the provider step from a flow and its snapshot.
 */
export const ProviderPicker = defineComponent({
	name: 'ProviderPicker',
	props: {
		flow: { type: Object as PropType<BookingFlow>, required: true },
		state: { type: Object as PropType<BookingFlowState>, required: true },
	},
	setup(props) {
		return (): VNode => {
			const { flow, state } = props;

			const items: VNode[] = state.providers.map((provider) =>
				h('li', { key: provider.id }, [
					h(
						'button',
						{
							type: 'button',
							class: 'apbk-provider',
							'aria-pressed': state.providerChosen && state.providerId === provider.id,
							onClick: () => {
								void flow.selectProvider(provider.id);
							},
						},
						[
							h('span', { class: 'apbk-provider-name' }, provider.name),
							provider.bio !== null
								? h('span', { class: 'apbk-provider-bio' }, provider.bio)
								: null,
						],
					),
				]),
			);

			items.push(
				h('li', { key: 'any' }, [
					h(
						'button',
						{
							type: 'button',
							class: 'apbk-provider apbk-provider-any',
							'aria-pressed': state.providerChosen && state.providerId === null,
							onClick: () => {
								void flow.selectProvider(null);
							},
						},
						'No preference',
					),
				]),
			);

			return h('div', { class: 'apbk-provider-picker' }, [
				h('h3', { class: 'apbk-step-title' }, 'Choose a provider'),
				h('ul', { class: 'apbk-provider-list' }, items),
			]);
		};
	},
});
