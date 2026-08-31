/**
 * The React forms field-seam demo entry.
 *
 * Exercises the real {@link registerBookingSlotField} consumer — the public
 * renderer, the editor settings panel, and the builder-canvas preview — against
 * the in-memory mock client and a stand-in forms seam, so the `booking_slot`
 * field can be clicked through in a browser with no Laravel or forms backend.
 *
 * A host wires the two packages by handing the forms registry module to
 * {@link registerBookingSlotField}; this demo stands in a tiny Map-backed seam
 * for that module, then resolves the registered components from it exactly as
 * the forms renderer, editor, and builder would.
 */

import { type ComponentType, type JSX, StrictMode, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { registerBookingSlotField } from '../dist/react/index.js';
import type {
	CustomFieldSettingsProps,
	FieldCardPreviewProps,
	FieldComponentProps,
	FieldPaletteGroup,
	FormFieldLike,
	FormsFieldSeam,
} from '../dist/react/index.js';
import { createMockClient } from './mock-client.js';

/** A Map-backed forms seam that also lets the demo resolve what was registered. */
interface DemoSeam extends FormsFieldSeam {
	component(type: string): ComponentType<FieldComponentProps> | undefined;
	settings(type: string): ComponentType<CustomFieldSettingsProps> | undefined;
	preview(type: string): ComponentType<FieldCardPreviewProps> | undefined;
	groups(): FieldPaletteGroup[];
}

function createDemoSeam(): DemoSeam {
	const components = new Map<string, ComponentType<FieldComponentProps>>();
	const settings = new Map<string, ComponentType<CustomFieldSettingsProps>>();
	const previews = new Map<string, ComponentType<FieldCardPreviewProps>>();
	const paletteGroups: FieldPaletteGroup[] = [];

	return {
		registerFieldComponent: (type, component) => components.set(type, component),
		registerFieldSettings: (type, component) => settings.set(type, component),
		registerFieldCardPreview: (type, component) => previews.set(type, component),
		registerFieldPaletteGroup: (group) => paletteGroups.push(group),
		component: (type) => components.get(type),
		settings: (type) => settings.get(type),
		preview: (type) => previews.get(type),
		groups: () => paletteGroups,
	};
}

const seam = createDemoSeam();
const client = createMockClient();

registerBookingSlotField(seam, { baseUrl: '', client, locale: 'en-US' });

const Renderer = seam.component('booking_slot');
const Settings = seam.settings('booking_slot');
const Preview = seam.preview('booking_slot');

/** A booking_slot field for the demo, with the members the components read. */
function field(over: Partial<FormFieldLike>): FormFieldLike {
	return {
		id: 1,
		name: 'appointment',
		type: 'booking_slot',
		label: 'Choose a time',
		help_text: null,
		is_required: true,
		field_config: {},
		...over,
	};
}

/** The public renderer, with a live read-out of the value it writes. */
function RendererDemo({ label, config }: { label: string; config: Record<string, unknown> }): JSX.Element {
	const [value, setValue] = useState<unknown>(undefined);
	const f = useMemo(() => field({ label, field_config: config }), [label, config]);

	if (Renderer === undefined) {
		return <p>Renderer not registered.</p>;
	}

	return (
		<div>
			<Renderer field={f} value={value} onChange={setValue} />
			<p className="demo-value-label">Submitted value:</p>
			<pre className="demo-value">{JSON.stringify(value ?? null, null, 2)}</pre>
		</div>
	);
}

/** The editor settings panel, editing a booking field's config in place. */
function SettingsDemo(): JSX.Element {
	const [edited, setEdited] = useState<FormFieldLike>(field({ id: 9, label: 'Pick a time' }));
	const allFields: FormFieldLike[] = [
		{ id: 1, name: 'full_name', type: 'text', label: 'Full name' },
		{ id: 2, name: 'email', type: 'email', label: 'Email' },
		{ id: 3, name: 'phone', type: 'phone', label: 'Phone' },
		{ id: 4, name: 'section', type: 'heading', label: 'Section heading' },
		edited,
	];

	if (Settings === undefined) {
		return <p>Settings not registered.</p>;
	}

	return (
		<div>
			<Settings
				field={edited}
				allFields={allFields.map((f) => (f.id === edited.id ? edited : f))}
				updateField={(data) => {
					setEdited((prev) => ({ ...prev, ...data }));
				}}
			/>
			<p className="demo-value-label">field_config:</p>
			<pre className="demo-value">{JSON.stringify(edited.field_config ?? {}, null, 2)}</pre>
		</div>
	);
}

/** The palette group, rendered from what was registered. */
function PaletteDemo(): JSX.Element {
	return (
		<ul>
			{seam.groups().map((group) => (
				<li key={group.label}>
					<strong>{group.label}</strong>
					<ul>
						{group.fields.map((item) => (
							<li key={item.type}>
								<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
									<path d={item.iconPath} fill="currentColor" />
								</svg>{' '}
								{item.label} <code>({item.type})</code>
							</li>
						))}
					</ul>
				</li>
			))}
		</ul>
	);
}

function mount(id: string, node: JSX.Element): void {
	const host = document.getElementById(id);
	if (host !== null) {
		createRoot(host).render(<StrictMode>{node}</StrictMode>);
	}
}

mount('renderer-single', <RendererDemo label="Choose a time" config={{ service_slugs: ['haircut'] }} />);
mount(
	'renderer-multi',
	<RendererDemo label="Choose a time" config={{ service_slugs: ['haircut', 'consultation'] }} />,
);
mount('renderer-empty', <RendererDemo label="Choose a time" config={{}} />);
mount('settings', <SettingsDemo />);
mount(
	'preview-configured',
	Preview !== undefined ? <Preview field={field({ field_config: { service_slugs: ['haircut'] } })} /> : <span />,
);
mount(
	'preview-empty',
	Preview !== undefined ? <Preview field={field({ field_config: {} })} /> : <span />,
);
mount('palette', <PaletteDemo />);
