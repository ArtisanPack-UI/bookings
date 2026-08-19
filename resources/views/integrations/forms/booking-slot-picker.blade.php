{{--
    The booking_slot availability picker.

    Shared by two surfaces: the public form (via `ap.forms.fieldRender`, where it
    is interactive and writes the chosen slot into the Livewire form's `formData`)
    and the builder canvas (via `ap.forms.fieldCardPreview`, where `$preview` is
    true and a chosen slot is only shown, not stored, because the surrounding
    Livewire component there is the builder, not a form being filled in).

    @var array  $services     [{slug,name,durationLabel,slotsUrl}] the field books.
    @var string $fieldName     The form field name the chosen slot is stored under.
    @var bool   $preview       True on the builder canvas, false on a live form.
    @var string $label         The field label.
    @var string $description   Optional helper text shown under the label.
    @var bool   $required      Whether the field is required.
--}}
@php
    $hasServices = [] !== $services;
@endphp

<div class="artisanpack-bookings-slot-field">
    @if ( '' !== $label )
        <p class="mb-1 text-sm font-medium">
            {{ $label }}
            @if ( $required )
                <span class="text-error" aria-hidden="true">*</span>
            @endif
        </p>
    @endif

    @if ( '' !== $description )
        <p class="mb-3 text-xs text-base-content/60">{{ $description }}</p>
    @endif

    @if ( ! $hasServices )
        <p class="rounded-lg bg-base-200 p-4 text-sm text-error" role="alert">
            {{ __( 'No service is configured for this booking field yet.' ) }}
        </p>
    @else
        <div
            @if ( ! $preview ) wire:ignore @endif
            x-data="artisanpackBookingSlotPicker(@js( [
                'services'  => $services,
                'field'     => $fieldName,
                'preview'   => $preview,
                'month'     => now()->format( 'Y-m' ),
            ] ))"
            x-init="init()"
            class="overflow-hidden rounded-lg border border-base-300 bg-base-100"
        >
            {{-- Service chooser, only when the field books more than one service. --}}
            <template x-if="services.length > 1">
                <div class="border-b border-base-200 p-3">
                    <label class="mb-1 block text-xs font-medium text-base-content/60">{{ __( 'Service' ) }}</label>
                    <select
                        class="select select-sm select-bordered w-full"
                        x-model="serviceSlug"
                        @change="onServiceChange()"
                        @if ( $preview ) disabled @endif
                    >
                        <template x-for="service in services" :key="service.slug">
                            <option :value="service.slug" x-text="service.name"></option>
                        </template>
                    </select>
                </div>
            </template>

            {{-- Service header: name, duration, the viewer's timezone. --}}
            <div class="bg-base-200 p-4">
                <p class="text-lg font-semibold" x-text="currentService?.name"></p>
                <p class="text-sm text-base-content/60" x-text="currentService?.durationLabel"></p>
                <p class="mt-2 text-xs">
                    <span class="font-medium">{{ __( 'Your timezone:' ) }}</span>
                    <span x-text="timezone"></span>
                </p>
            </div>

            <div class="p-4">
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-sm font-medium">{{ __( 'Select a date' ) }}</p>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            class="rounded border border-base-300 p-1 disabled:opacity-30"
                            @click="previousMonth()"
                            :disabled="loading"
                            aria-label="{{ __( 'Previous month' ) }}"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>
                        <span class="min-w-[9rem] text-center text-xs" x-text="monthLabel"></span>
                        <button
                            type="button"
                            class="rounded border border-base-300 p-1 disabled:opacity-30"
                            @click="nextMonth()"
                            :disabled="loading"
                            aria-label="{{ __( 'Next month' ) }}"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>
                </div>

                <p x-show="loading" class="py-6 text-center text-sm opacity-70">{{ __( 'Loading available times…' ) }}</p>

                <p x-show="! loading && days.length === 0" class="py-6 text-center text-sm opacity-70">
                    {{ __( 'No times are available this month.' ) }}
                </p>

                {{-- The days that have availability, as a scrollable column of cards. --}}
                <div x-show="! loading && days.length > 0" class="max-h-72 space-y-2 overflow-y-auto">
                    <template x-for="day in days" :key="day.date">
                        <div class="rounded-lg border border-base-200">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between p-3 text-left"
                                :class="selectedDay === day.date ? 'bg-base-200' : ''"
                                @click="selectDay(day.date)"
                            >
                                <span>
                                    <span class="block text-base font-medium" x-text="day.weekday"></span>
                                    <span class="block text-sm text-base-content/60" x-text="day.label"></span>
                                </span>
                                <span class="text-xs text-base-content/50" x-text="day.slots.length + ' ' + @js( __( 'times' ) )"></span>
                            </button>

                            {{-- The chosen day's time slots. --}}
                            <div x-show="selectedDay === day.date" x-cloak class="flex flex-wrap gap-2 border-t border-base-200 p-3">
                                <template x-for="slot in day.slots" :key="slot.start">
                                    <button
                                        type="button"
                                        class="rounded border px-3 py-1 text-sm"
                                        :class="selectedSlot === slot.start
                                            ? 'border-primary bg-primary text-primary-content'
                                            : 'border-base-300 hover:border-primary'"
                                        :aria-pressed="selectedSlot === slot.start"
                                        @click="choose(slot)"
                                        x-text="slot.label"
                                    ></button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    @endif

    {{--
        The picker factory, defined inline and guarded rather than pushed to a
        stack: forms renders each field through an isolated View::render(), so a
        @push would not reliably reach a host layout's @stack. Assigning it
        idempotently registers it wherever a booking_slot field or its preview
        lands, and a second one on the page re-assigns the same factory.
    --}}
    <script>
        window.artisanpackBookingSlotPicker = window.artisanpackBookingSlotPicker || function ( config ) {
            return {
                services: config.services,
                field: config.field,
                preview: config.preview,
                month: config.month,
                serviceSlug: '',
                timezone: '',
                loading: false,
                days: [],
                selectedDay: '',
                selectedSlot: '',
                init() {
                    this.serviceSlug = this.services.length ? this.services[0].slug : '';
                    try {
                        this.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
                    } catch ( error ) {
                        this.timezone = 'UTC';
                    }
                    this.load();
                },
                get currentService() {
                    return this.services.find( ( service ) => service.slug === this.serviceSlug ) || this.services[0];
                },
                get monthLabel() {
                    const [ year, month ] = this.month.split( '-' ).map( Number );
                    return new Date( year, month - 1, 1 ).toLocaleDateString( undefined, {
                        month: 'long',
                        year: 'numeric',
                    } );
                },
                onServiceChange() {
                    this.selectedDay = '';
                    this.selectedSlot = '';
                    this.load();
                },
                async load() {
                    const service = this.currentService;

                    if ( ! service ) {
                        return;
                    }

                    this.loading = true;
                    this.days = [];

                    try {
                        const url = new URL( service.slotsUrl, window.location.origin );
                        url.searchParams.set( 'date', this.month );

                        const response = await fetch( url, { headers: { 'Accept': 'application/json' } } );

                        if ( ! response.ok ) {
                            return;
                        }

                        const body = await response.json();
                        this.days = this.groupByDay( body.data || [] );
                    } catch ( error ) {
                        this.days = [];
                    } finally {
                        this.loading = false;
                    }
                },
                groupByDay( slots ) {
                    const groups = {};

                    slots.forEach( ( slot ) => {
                        const start = new Date( slot.start );
                        const key = slot.start.slice( 0, 10 );

                        if ( ! groups[ key ] ) {
                            groups[ key ] = {
                                date: key,
                                weekday: start.toLocaleDateString( undefined, { weekday: 'long' } ),
                                label: start.toLocaleDateString( undefined, { month: 'long', day: 'numeric', year: 'numeric' } ),
                                slots: [],
                            };
                        }

                        groups[ key ].slots.push( {
                            start: slot.start,
                            providerId: slot.provider_id ?? null,
                            label: start.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } ),
                        } );
                    } );

                    return Object.values( groups );
                },
                selectDay( date ) {
                    this.selectedDay = this.selectedDay === date ? '' : date;
                },
                choose( slot ) {
                    this.selectedSlot = slot.start;

                    if ( this.preview ) {
                        return;
                    }

                    this.$wire.set( 'formData.' + this.field, JSON.stringify( {
                        service_slug: this.serviceSlug,
                        start: slot.start,
                        provider_id: slot.providerId,
                        timezone: this.timezone,
                    } ) );
                },
                previousMonth() {
                    this.month = this.shiftMonth( -1 );
                    this.selectedDay = '';
                    this.load();
                },
                nextMonth() {
                    this.month = this.shiftMonth( 1 );
                    this.selectedDay = '';
                    this.load();
                },
                shiftMonth( delta ) {
                    const [ year, month ] = this.month.split( '-' ).map( Number );
                    const shifted = new Date( year, month - 1 + delta, 1 );
                    return shifted.getFullYear() + '-' + String( shifted.getMonth() + 1 ).padStart( 2, '0' );
                },
            };
        };
    </script>
</div>
