{{--
    The admin booking creator.

    Creates a booking on behalf of a customer who booked by phone or email. The
    timezone is stated outright rather than captured from a browser, and the
    appointment time is typed as a wall-clock time in that zone. Plain HTML and
    utility classes, to sit inside the host application's admin shell.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-booking-create space-y-6">
    <h2 class="text-lg font-semibold">{{ __( 'New booking' ) }}</h2>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Service' ) }}</span>
                <select class="select select-bordered w-full" wire:model.live="serviceId" required>
                    <option value="">{{ __( 'Choose a service' ) }}</option>
                    @foreach ( $this->services as $service )
                        <option value="{{ $service->id }}" wire:key="service-{{ $service->id }}">{{ $service->name }}</option>
                    @endforeach
                </select>
                @error( 'serviceId' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Provider' ) }}</span>
                <select class="select select-bordered w-full" wire:model.live="providerId" required @disabled( null === $serviceId )>
                    <option value="">{{ __( 'Choose a provider' ) }}</option>
                    @foreach ( $this->providers as $provider )
                        <option value="{{ $provider->id }}" wire:key="booking-provider-{{ $provider->id }}">{{ $provider->name }}</option>
                    @endforeach
                </select>
                @error( 'providerId' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Appointment time' ) }}</span>
                <input type="datetime-local" class="input input-bordered w-full" wire:model="startTime" required />
                @error( 'startTime' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Timezone' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model.live="timezone" placeholder="{{ __( 'Defaults to the provider timezone' ) }}" />
                @error( 'timezone' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Customer name' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model="customerName" required />
                @error( 'customerName' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Customer email' ) }}</span>
                <input type="email" class="input input-bordered w-full" wire:model="customerEmail" required />
                @error( 'customerEmail' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>
        </div>

        <label class="block space-y-1">
            <span class="font-medium">{{ __( 'Customer telephone' ) }}</span>
            <input type="text" class="input input-bordered w-full" wire:model="customerPhone" />
            @error( 'customerPhone' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
        </label>

        @if ( count( $this->intakeFields ) > 0 )
            <fieldset class="space-y-4 rounded-lg border border-base-300 p-4">
                <legend class="px-1 font-medium">{{ __( 'Intake details' ) }}</legend>

                @foreach ( $this->intakeFields as $field )
                    @php
                        $fieldError = $errors->first( 'intake.' . $field['name'] );
                        $answer     = $this->intake[ $field['name'] ] ?? null;
                    @endphp

                    <div wire:key="intake-{{ $field['name'] }}">
                        @if ( in_array( $field['type'], [ 'multiselect', 'checkboxes', 'radio' ], true ) )
                            <fieldset>
                                <legend class="font-medium">{{ $field['label'] }}</legend>

                                @foreach ( $field['options'] as $optionIndex => $option )
                                    <label class="flex items-center gap-2">
                                        <input
                                            type="{{ 'radio' === $field['type'] ? 'radio' : 'checkbox' }}"
                                            value="{{ $option }}"
                                            wire:model="intake.{{ $field['name'] }}"
                                            @checked( 'radio' === $field['type'] ? $answer === $option : ( is_array( $answer ) && in_array( $option, $answer, true ) ) )
                                            class="{{ 'radio' === $field['type'] ? 'radio' : 'checkbox' }}"
                                        >
                                        <span>{{ $option }}</span>
                                    </label>
                                @endforeach
                            </fieldset>
                        @elseif ( 'select' === $field['type'] )
                            <label class="space-y-1">
                                <span class="font-medium">{{ $field['label'] }}</span>
                                <select wire:model="intake.{{ $field['name'] }}" class="select select-bordered w-full">
                                    <option value="">{{ __( 'Please choose' ) }}</option>
                                    @foreach ( $field['options'] as $option )
                                        <option value="{{ $option }}" @selected( $answer === $option )>{{ $option }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @elseif ( in_array( $field['type'], [ 'checkbox', 'boolean' ], true ) )
                            <label class="flex items-center gap-2">
                                <input type="checkbox" value="1" wire:model="intake.{{ $field['name'] }}" @checked( (bool) $answer ) class="checkbox">
                                <span class="font-medium">{{ $field['label'] }}</span>
                            </label>
                        @elseif ( 'textarea' === $field['type'] )
                            <label class="space-y-1">
                                <span class="font-medium">{{ $field['label'] }}</span>
                                <textarea wire:model="intake.{{ $field['name'] }}" rows="3" class="textarea textarea-bordered w-full">{{ is_string( $answer ) ? $answer : '' }}</textarea>
                            </label>
                        @else
                            <label class="space-y-1">
                                <span class="font-medium">{{ $field['label'] }}</span>
                                <input
                                    type="{{ in_array( $field['type'], [ 'email', 'url', 'number', 'date', 'time' ], true ) ? $field['type'] : 'text' }}"
                                    wire:model="intake.{{ $field['name'] }}"
                                    value="{{ is_string( $answer ) || is_numeric( $answer ) ? $answer : '' }}"
                                    class="input input-bordered w-full"
                                >
                            </label>
                        @endif

                        @if ( '' !== $fieldError )
                            <span class="text-sm text-error">{{ $fieldError }}</span>
                        @endif
                    </div>
                @endforeach
            </fieldset>
        @endif

        <label class="block space-y-1">
            <span class="font-medium">{{ __( 'Notes' ) }}</span>
            <textarea class="textarea textarea-bordered w-full" rows="3" wire:model="notes"></textarea>
            @error( 'notes' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
        </label>

        <label class="flex items-center gap-2">
            <input type="checkbox" class="checkbox" wire:model="notifyCustomer" />
            <span class="font-medium">{{ __( 'Notify the customer by email' ) }}</span>
        </label>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary">
                <span wire:loading.remove wire:target="save">{{ __( 'Create booking' ) }}</span>
                <span wire:loading wire:target="save">{{ __( 'Creating…' ) }}</span>
            </button>

            <button type="button" class="btn" wire:click="cancel">{{ __( 'Cancel' ) }}</button>
        </div>
    </form>
</div>
