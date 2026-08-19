{{--
    The booking_slot field's settings panel.

    Rendered into artisanpack-ui/forms' field editor via `ap.forms.fieldSettings`,
    so `$wire` is the form builder: every control persists through the builder's
    own `updateField()` — `field_config` for the appointment settings, and the
    top-level `label`/`is_required` for the two the builder already owns. The
    field mappings point at other fields already on the form, so the appointment
    field reuses the form's name/email/phone questions rather than duplicating them.

    @var object $field         The booking_slot field being edited.
    @var array  $services      [{slug,name}] the active services to offer.
    @var array  $fieldOptions  [{name,label}] the form's other mappable fields.
--}}
@php
    $config = (array) ( $field->field_config ?? [] );
    $initial = [
        'service_slugs' => array_values( (array) ( $config['service_slugs'] ?? [] ) ),
        'name_field'    => (string) ( $config['name_field'] ?? '' ),
        'email_field'   => (string) ( $config['email_field'] ?? '' ),
        'phone_field'   => (string) ( $config['phone_field'] ?? '' ),
        'optin_field'   => (string) ( $config['optin_field'] ?? '' ),
        'description'   => (string) ( $config['description'] ?? '' ),
    ];
@endphp

<div
    x-data="{
        config: @js( $initial ),
        label: @js( (string) $field->label ),
        required: @js( (bool) $field->is_required ),
        persistConfig() { this.$wire.updateField({ field_config: this.config }); },
        persistLabel() { this.$wire.updateField({ label: this.label }); },
        persistRequired() { this.$wire.updateField({ is_required: this.required }); },
    }"
    class="space-y-3"
>
    <p class="text-xs font-semibold uppercase tracking-wide text-base-content/50">{{ __( 'Appointment' ) }}</p>

    {{-- Filter By: which services this field can book. --}}
    <div class="form-control">
        <label class="label"><span class="label-text text-xs">{{ __( 'Services' ) }}</span></label>
        @if ( [] === $services )
            <p class="text-xs text-error">{{ __( 'No active services exist yet. Create a service first.' ) }}</p>
        @else
            <div class="space-y-1 rounded-lg border border-base-300 p-2">
                @foreach ( $services as $service )
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-sm"
                            value="{{ $service['slug'] }}"
                            x-model="config.service_slugs"
                            @change="persistConfig()"
                        />
                        <span>{{ $service['name'] }}</span>
                    </label>
                @endforeach
            </div>
            <label class="label"><span class="label-text-alt text-xs text-base-content/50">{{ __( 'Choose one or more. With several, the visitor picks which to book.' ) }}</span></label>
        @endif
    </div>

    {{-- Map the appointment's contact details to fields already on the form. --}}
    @foreach ( [
        'name_field'  => __( 'Name Form Field' ),
        'email_field' => __( 'Email Form Field' ),
        'phone_field' => __( 'Phone Form Field' ),
        'optin_field' => __( 'Opt-In Form Field' ),
    ] as $key => $mapLabel )
        <div class="form-control">
            <label class="label"><span class="label-text text-xs">{{ $mapLabel }}</span></label>
            <select
                class="select select-sm select-bordered w-full"
                x-model="config.{{ $key }}"
                @change="persistConfig()"
            >
                <option value="">{{ __( 'Select a field' ) }}</option>
                @foreach ( $fieldOptions as $option )
                    <option value="{{ $option['name'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
        </div>
    @endforeach

    {{-- Field label, mirrored here the way SSA surfaces it beside the mappings. --}}
    <div class="form-control">
        <label class="label"><span class="label-text text-xs">{{ __( 'Field Label' ) }}</span></label>
        <input type="text" class="input input-sm input-bordered w-full" x-model="label" @change="persistLabel()" />
    </div>

    {{-- Description shown under the label on the public form. --}}
    <div class="form-control">
        <label class="label"><span class="label-text text-xs">{{ __( 'Description' ) }}</span></label>
        <textarea class="textarea textarea-sm textarea-bordered w-full" rows="2" x-model="config.description" @change="persistConfig()"></textarea>
    </div>

    <div class="form-control">
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" class="checkbox checkbox-sm" x-model="required" @change="persistRequired()" />
            <span>{{ __( 'Required' ) }}</span>
        </label>
    </div>
</div>
