/**
 * The forms field-seam contract, mirrored structurally.
 *
 * The React equivalent of {@link \ArtisanPackUI\Bookings\Integrations\Forms\BookingSlotField}'s
 * discipline: nothing here imports `artisanpack-ui/forms`. The server-side
 * integration types every form field as `object` and reads only the properties
 * it needs, because forms is a `suggest` and a signature naming a forms class
 * would fatal an installation without it. This module makes the same choice for
 * the same reason — `artisanpack-ui/forms` ships its React source by
 * `vendor:publish`, not as an npm dependency this package can resolve at build
 * time — so the seam is described here by shape and the host injects the real
 * registrars (the forms package's registry module satisfies {@link FormsFieldSeam}
 * structurally).
 *
 * @packageDocumentation
 */

import type { ComponentType } from 'react';

/**
 * The subset of a forms `FormField` the booking_slot components read.
 *
 * Kept to what is actually used so the structural type stays a supertype of the
 * richer field forms passes: the type key it is matched on, the label and hints
 * drawn on the field, the required flag, and the `field_config` bag the settings
 * panel persists into and the renderer/preview read back.
 */
export interface FormFieldLike {
	/** The field's stable numeric id, used as a React key among sibling fields. */
	id: number;

	/** The machine name the value is submitted under. */
	name: string;

	/** The field type key, `'booking_slot'` for the fields this module owns. */
	type: string;

	/** The field's label, or null when it has none. */
	label?: string | null;

	/** The field's help text, or null when it has none. */
	help_text?: string | null;

	/** Whether the field must be answered. */
	is_required?: boolean;

	/** The free-form configuration bag, where the booking settings are stored. */
	field_config?: Record<string, unknown> | null;
}

/**
 * A partial field update, as the editor's `updateField` callback takes it.
 *
 * Only `field_config` is ever written by the booking settings panel.
 */
export interface UpdateFieldRequestLike {
	field_config?: Record<string, unknown> | null;
}

/**
 * The props forms passes a public field renderer component.
 *
 * A structural mirror of the forms `FieldComponentProps`. Only the four members
 * the booking_slot renderer reads are required; the rest forms may pass
 * (`displayConfig`, `onFileChange`) are left off so the type stays a supertype.
 */
export interface FieldComponentProps {
	/** The field being rendered. */
	field: FormFieldLike;

	/** The field's current value. */
	value: unknown;

	/** The first validation error for the field, if any. */
	error?: string;

	/** Writes a new value for the field into the submission. */
	onChange: (value: unknown) => void;
}

/**
 * The props forms passes a custom settings panel in the field editor.
 *
 * Mirrors the forms `CustomFieldSettingsProps`: the field, every field in the
 * form (so the panel can map a booking's contacts onto other fields, as the
 * server-side `ap.forms.fieldSettings` filter's `Form` argument allows), and the
 * debounced-saving update callback.
 */
export interface CustomFieldSettingsProps {
	/** The field being edited. */
	field: FormFieldLike;

	/** Every field in the form, including the one being edited. */
	allFields: FormFieldLike[];

	/** Applies a partial update to the field. */
	updateField: (data: UpdateFieldRequestLike) => void;
}

/**
 * The props forms passes a builder-canvas preview component.
 */
export interface FieldCardPreviewProps {
	/** The field being previewed on the builder canvas. */
	field: FormFieldLike;
}

/**
 * One entry in a builder palette group.
 *
 * Mirrors the forms `FieldPaletteItem`. `iconPath` supplies a raw 16×16 SVG path
 * for a type outside the built-in icon set — which `booking_slot` is.
 */
export interface FieldPaletteItem {
	/** The field type the button drops. */
	type: string;

	/** The button's label. */
	label: string;

	/** The built-in icon key; ignored when `iconPath` is given. */
	icon: string;

	/** The palette category the button is filed under. */
	category: string;

	/** Raw 16×16 SVG path data drawn in place of the built-in icon lookup. */
	iconPath?: string;
}

/**
 * A named group of palette items in the builder sidebar.
 *
 * Mirrors the forms `FieldPaletteGroup`.
 */
export interface FieldPaletteGroup {
	/** The group heading. */
	label: string;

	/** The buttons in the group. */
	fields: FieldPaletteItem[];
}

/**
 * The forms field-registry seam a host injects.
 *
 * The forms package's registry module (`registerFieldComponent`,
 * `registerFieldSettings`, `registerFieldCardPreview`,
 * `registerFieldPaletteGroup`) satisfies this structurally, so a host wires the
 * two packages together by handing the forms module — or the four functions —
 * to {@link registerBookingSlotField}.
 */
export interface FormsFieldSeam {
	/** Registers the public renderer for a field type. */
	registerFieldComponent(type: string, component: ComponentType<FieldComponentProps>): void;

	/** Registers the editor settings panel for a field type. */
	registerFieldSettings(type: string, component: ComponentType<CustomFieldSettingsProps>): void;

	/** Registers the builder-canvas preview for a field type. */
	registerFieldCardPreview(type: string, component: ComponentType<FieldCardPreviewProps>): void;

	/** Appends a group to the builder palette. */
	registerFieldPaletteGroup(group: FieldPaletteGroup): void;
}
