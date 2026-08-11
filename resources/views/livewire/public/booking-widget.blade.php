{{--
    The public booking widget.

    Every choice on every step is a real <form>. Step navigation is a GET back to
    this same page carrying the choice in the query string, which the component
    reads back on mount, and the final submission is a POST to the package's
    widget route — so the whole flow works with Livewire's bundle blocked,
    missing, or still loading. Where JavaScript is available the wire: directives
    on those same elements intercept the click and nothing navigates.

    Each navigation is its own sibling form rather than one form with several
    submit buttons. A submit button carrying a name contributes its value *in
    addition* to any hidden input of the same name, so a single form would put
    two `bookingMonth` values in the query string and leave which one wins to the
    order the browser happened to serialize them in.

    Field ids are suffixed with the component id so that two widgets embedded on
    one page do not hand a screen reader two labels pointing at the same input.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@php
    $uid      = $this->getId();
    $step     = $this->step;
    $service  = $this->service;
    $zone     = $this->displayTimezone;
    $action   = url()->current();
    $byDay    = $this->slotsByDay;
    $month    = $this->browsedMonth;
    $carried  = [ 'bookingService' => $this->serviceSlug, 'bookingProvider' => $this->providerSlug, 'bookingMonth' => $month->format( 'Y-m' ) ];

    // `Z` rather than `+00:00`: the value goes into a query string, and a `+` in
    // one decodes to a space, which comes back as an instant nothing can parse.
    $instant = static fn ( $moment ): string => \ArtisanPackUI\Bookings\Livewire\Public\BookingWidget::instantKey( $moment );

    // Every date this reads has already been through the component's own
    // parsing — `$byDay`'s keys are built server-side, and `$this->date` is
    // canonicalised on mount and on every update — so the format is exact
    // rather than a guess, and a value that somehow is not one raises here
    // rather than being displayed as some other day.
    $asDay = static fn ( string $date ): \Carbon\CarbonImmutable => \Carbon\CarbonImmutable::createFromFormat( '!Y-m-d', $date, $zone );
@endphp

<div class="artisanpack-booking-widget space-y-6" role="region" aria-label="{{ __( 'Book an appointment' ) }}">
    <nav aria-label="{{ __( 'Booking steps' ) }}">
        <ol class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
            @foreach ( [ 'service' => __( 'Service' ), 'provider' => __( 'Who' ), 'slot' => __( 'Time' ), 'details' => __( 'Details' ), 'done' => __( 'Confirmed' ) ] as $key => $label )
                @if ( 'provider' !== $key || $this->offersProviderChoice )
                    <li @class( [ 'font-semibold' => $step === $key, 'opacity-60' => $step !== $key ] ) @if ( $step === $key ) aria-current="step" @endif>
                        {{ $label }}
                    </li>
                @endif
            @endforeach
        </ol>
    </nav>

    <p aria-live="polite" class="sr-only">
        @switch ( $step )
            @case ( 'service' ) {{ __( 'Choose a service.' ) }} @break
            @case ( 'provider' ) {{ __( 'Choose who you would like to see.' ) }} @break
            @case ( 'slot' ) {{ __( 'Choose a date and time.' ) }} @break
            @case ( 'details' ) {{ __( 'Enter your details to confirm the booking.' ) }} @break
            @case ( 'done' ) {{ __( 'Your booking is confirmed.' ) }} @break
        @endswitch
    </p>

    @error ( 'serviceSlug' )
        <p class="text-error" role="alert">{{ $message }}</p>
    @enderror

    {{-- ---------------------------------------------------------------- --}}
    @if ( 'done' === $step )
        <div class="rounded-box border border-success p-4 space-y-2">
            <h3 class="text-lg font-semibold">{{ __( 'Your appointment is booked' ) }}</h3>

            <p>
                {{ __( ':service on :when (:timezone)', [
                    'service'  => $this->confirmation['service'] ?? '',
                    'when'     => $this->confirmation['starts_at'] ?? '',
                    'timezone' => $this->confirmation['timezone'] ?? $zone,
                ] ) }}
            </p>

            <p>{{ __( 'Reference: :number', [ 'number' => $this->confirmation['booking_number'] ?? '' ] ) }}</p>

            @if ( ! empty( $this->confirmation['email'] ) )
                <p>{{ __( 'A confirmation has been sent to :email.', [ 'email' => $this->confirmation['email'] ] ) }}</p>
            @endif

            <form method="GET" action="{{ $action }}">
                @if ( null !== $this->pinnedService )
                    <input type="hidden" name="bookingService" value="{{ $this->pinnedService }}">
                @endif

                <button type="submit" class="btn btn-sm" wire:click.prevent="bookAnother">
                    {{ __( 'Book another appointment' ) }}
                </button>
            </form>
        </div>

    {{-- ---------------------------------------------------------------- --}}
    @elseif ( 'service' === $step )
        <fieldset>
            <legend class="text-lg font-semibold mb-2">{{ __( 'What would you like to book?' ) }}</legend>

            @forelse ( $this->services as $option )
                <form method="GET" action="{{ $action }}">
                    <button
                        type="submit"
                        name="bookingService"
                        value="{{ $option->slug }}"
                        wire:click.prevent="chooseService({{ \Illuminate\Support\Js::from( $option->slug ) }})"
                        class="btn btn-block justify-between mb-2"
                    >
                        <span>{{ $option->name }}</span>
                        <span class="opacity-70">{{ trans_choice( ':count minute|:count minutes', (int) $option->duration, [ 'count' => (int) $option->duration ] ) }}</span>
                    </button>
                </form>
            @empty
                <p>{{ __( 'There is nothing available to book at the moment.' ) }}</p>
            @endforelse
        </fieldset>

    {{-- ---------------------------------------------------------------- --}}
    @elseif ( 'provider' === $step )
        <fieldset>
            <legend class="text-lg font-semibold mb-2">{{ __( 'Who would you like to see?' ) }}</legend>

            @foreach ( array_merge( [ [ 'slug' => 'any', 'name' => __( 'No preference' ) ] ], array_map( static fn ( $provider ) => [ 'slug' => $provider->slug, 'name' => $provider->name ], $this->providers ) ) as $option )
                <form method="GET" action="{{ $action }}">
                    <input type="hidden" name="bookingService" value="{{ $service->slug }}">

                    <button
                        type="submit"
                        name="bookingProvider"
                        value="{{ $option['slug'] }}"
                        wire:click.prevent="chooseProvider({{ \Illuminate\Support\Js::from( $option['slug'] ) }})"
                        class="btn btn-block justify-start mb-2"
                    >
                        {{ $option['name'] }}
                    </button>
                </form>
            @endforeach
        </fieldset>

        @if ( null === $this->pinnedService )
            <form method="GET" action="{{ $action }}">
                <button type="submit" class="btn btn-ghost btn-sm" wire:click.prevent="back">{{ __( 'Back' ) }}</button>
            </form>
        @endif

    {{-- ---------------------------------------------------------------- --}}
    @elseif ( 'slot' === $step )
        <div class="flex items-center justify-between gap-2">
            @foreach ( [ -1 => __( 'Previous month' ), 1 => __( 'Next month' ) ] as $offset => $label )
                @if ( -1 === $offset )
                    @php $target = $month->subMonth(); @endphp
                @else
                    @php $target = $month->addMonth(); @endphp
                @endif

                <form method="GET" action="{{ $action }}" @class( [ 'order-last' => 1 === $offset ] )>
                    <input type="hidden" name="bookingService" value="{{ $service->slug }}">
                    <input type="hidden" name="bookingProvider" value="{{ $this->providerSlug }}">

                    <button type="submit" name="bookingMonth" value="{{ $target->format( 'Y-m' ) }}" wire:click.prevent="shiftMonth({{ $offset }})" class="btn btn-sm">
                        <span aria-hidden="true">{{ -1 === $offset ? '←' : '→' }}</span>
                        <span class="sr-only">{{ $label }}</span>
                    </button>
                </form>
            @endforeach

            <h3 class="text-lg font-semibold">{{ $month->translatedFormat( 'F Y' ) }}</h3>
        </div>

        <fieldset>
            <legend class="font-semibold mb-2">{{ __( 'Available days' ) }}</legend>

            @forelse ( array_keys( $byDay ) as $day )
                <form method="GET" action="{{ $action }}" class="inline-block mb-2 mr-2">
                    @foreach ( $carried as $name => $value )
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach

                    <button
                        type="submit"
                        name="bookingDate"
                        value="{{ $day }}"
                        wire:click.prevent="chooseDate({{ \Illuminate\Support\Js::from( $day ) }})"
                        @if ( $day === $this->date ) aria-current="date" @endif
                        @class( [ 'btn btn-sm', 'btn-primary' => $day === $this->date ] )
                    >
                        {{ $asDay( $day )->translatedFormat( 'D j' ) }}
                    </button>
                </form>
            @empty
                <p>{{ __( 'There are no appointments available this month.' ) }}</p>
            @endforelse
        </fieldset>

        @if ( '' !== $this->date )
            <fieldset>
                <legend class="font-semibold mb-2">
                    {{ __( 'Times on :date', [ 'date' => $asDay( $this->date )->translatedFormat( 'l j F' ) ] ) }}
                </legend>

                <p class="text-sm opacity-70 mb-2">{{ __( 'Times are shown in :timezone.', [ 'timezone' => $zone ] ) }}</p>

                @error ( 'slotStart' )
                    <p class="text-error mb-2" role="alert">{{ $message }}</p>
                @enderror

                @forelse ( $this->slotsOnDate as $slot )
                    @php
                        $start = $slot->period->start->copy()->setTimezone( $zone );
                        $key   = $instant( $slot->period->start );
                    @endphp

                    <form method="GET" action="{{ $action }}" class="inline-block mb-2 mr-2">
                        @foreach ( array_merge( $carried, [ 'bookingDate' => $this->date ] ) as $name => $value )
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach

                        <button
                            type="submit"
                            name="bookingSlot"
                            value="{{ $key }}"
                            wire:click.prevent="chooseSlot({{ \Illuminate\Support\Js::from( $key ) }})"
                            class="btn btn-sm"
                        >
                            <time datetime="{{ $key }}">{{ $start->translatedFormat( 'g:i a' ) }}</time>
                        </button>
                    </form>
                @empty
                    <p>{{ __( 'There is nothing left on that day. Please choose another.' ) }}</p>
                @endforelse
            </fieldset>
        @endif

        <form method="GET" action="{{ $action }}">
            @if ( $this->offersProviderChoice )
                <input type="hidden" name="bookingService" value="{{ $service->slug }}">
            @elseif ( null !== $this->pinnedService )
                <input type="hidden" name="bookingService" value="{{ $this->pinnedService }}">
            @endif

            <button type="submit" class="btn btn-ghost btn-sm" wire:click.prevent="back">{{ __( 'Back' ) }}</button>
        </form>

    {{-- ---------------------------------------------------------------- --}}
    @else
        @php $chosen = $this->chosenStart->setTimezone( $zone ); @endphp

        <form method="POST" action="{{ route( 'artisanpack.bookings.widget.store' ) }}" wire:submit="book" class="space-y-4">
            @csrf

            <input type="hidden" name="service_slug" value="{{ $service->slug }}">
            <input type="hidden" name="provider_id" value="{{ $this->provider?->getKey() }}">
            {{-- The parsed instant rather than the raw property, so a slot that
                 reached this page through a mangled query string is posted in
                 the form the API request object can read. --}}
            <input type="hidden" name="start_time" value="{{ $instant( $this->chosenStart ) }}">
            <input type="hidden" name="customer_timezone" value="{{ $zone }}">

            <h3 class="text-lg font-semibold">{{ __( 'Confirm your details' ) }}</h3>

            <p>
                {{ __( ':service on :when (:timezone)', [
                    'service'  => $service->name,
                    'when'     => $chosen->translatedFormat( 'l j F Y, g:i a' ),
                    'timezone' => $zone,
                ] ) }}
            </p>

            @error ( 'slotStart' )
                <p class="text-error" role="alert">{{ $message }}</p>
            @enderror

            @error ( 'providerSlug' )
                <p class="text-error" role="alert">{{ $message }}</p>
            @enderror

            <div>
                <label for="name-{{ $uid }}" class="label">{{ __( 'Your name' ) }}</label>
                <input
                    id="name-{{ $uid }}"
                    type="text"
                    name="customer_name"
                    wire:model="customerName"
                    value="{{ $this->customerName }}"
                    autocomplete="name"
                    required
                    maxlength="255"
                    class="input input-bordered w-full"
                    @error ( 'customerName' ) aria-invalid="true" aria-describedby="name-error-{{ $uid }}" @enderror
                >
                @error ( 'customerName' )
                    <p id="name-error-{{ $uid }}" class="text-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email-{{ $uid }}" class="label">{{ __( 'Email address' ) }}</label>
                <input
                    id="email-{{ $uid }}"
                    type="email"
                    name="customer_email"
                    wire:model="customerEmail"
                    value="{{ $this->customerEmail }}"
                    autocomplete="email"
                    required
                    maxlength="255"
                    class="input input-bordered w-full"
                    @error ( 'customerEmail' ) aria-invalid="true" aria-describedby="email-error-{{ $uid }}" @enderror
                >
                @error ( 'customerEmail' )
                    <p id="email-error-{{ $uid }}" class="text-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="phone-{{ $uid }}" class="label">{{ __( 'Telephone number (optional)' ) }}</label>
                <input
                    id="phone-{{ $uid }}"
                    type="tel"
                    name="customer_phone"
                    wire:model="customerPhone"
                    value="{{ $this->customerPhone }}"
                    autocomplete="tel"
                    maxlength="50"
                    class="input input-bordered w-full"
                    @error ( 'customerPhone' ) aria-invalid="true" aria-describedby="phone-error-{{ $uid }}" @enderror
                >
                @error ( 'customerPhone' )
                    <p id="phone-error-{{ $uid }}" class="text-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            @foreach ( $this->intakeFields as $field )
                @php
                    $fieldId    = 'intake-' . $loop->index . '-' . $uid;
                    $fieldError = $errors->first( 'intake.' . $field['name'] );
                    $answer     = $this->intake[ $field['name'] ] ?? null;
                    $described  = '' === $fieldError ? null : $fieldId . '-error';
                @endphp

                <div>
                    @if ( in_array( $field['type'], [ 'multiselect', 'checkboxes', 'radio' ], true ) )
                        <fieldset @if ( null !== $described ) aria-describedby="{{ $described }}" @endif>
                            <legend class="label">{{ $field['label'] }}</legend>

                            @foreach ( $field['options'] as $optionIndex => $option )
                                <label for="{{ $fieldId }}-{{ $optionIndex }}" class="flex items-center gap-2">
                                    <input
                                        id="{{ $fieldId }}-{{ $optionIndex }}"
                                        type="{{ 'radio' === $field['type'] ? 'radio' : 'checkbox' }}"
                                        name="intake_data[{{ $field['name'] }}]{{ 'radio' === $field['type'] ? '' : '[]' }}"
                                        value="{{ $option }}"
                                        wire:model="intake.{{ $field['name'] }}"
                                        @checked( 'radio' === $field['type'] ? $answer === $option : ( is_array( $answer ) && in_array( $option, $answer, true ) ) )
                                        @if ( $field['required'] && 'radio' === $field['type'] ) required @endif
                                        class="{{ 'radio' === $field['type'] ? 'radio' : 'checkbox' }}"
                                    >
                                    <span>{{ $option }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @elseif ( 'select' === $field['type'] )
                        <label for="{{ $fieldId }}" class="label">{{ $field['label'] }}</label>
                        <select
                            id="{{ $fieldId }}"
                            name="intake_data[{{ $field['name'] }}]"
                            wire:model="intake.{{ $field['name'] }}"
                            @if ( $field['required'] ) required @endif
                            class="select select-bordered w-full"
                            aria-invalid="{{ '' === $fieldError ? 'false' : 'true' }}"
                            @if ( null !== $described ) aria-describedby="{{ $described }}" @endif
                        >
                            <option value="">{{ __( 'Please choose' ) }}</option>
                            @foreach ( $field['options'] as $option )
                                <option value="{{ $option }}" @selected( $answer === $option )>{{ $option }}</option>
                            @endforeach
                        </select>
                    @elseif ( in_array( $field['type'], [ 'checkbox', 'boolean' ], true ) )
                        <label for="{{ $fieldId }}" class="flex items-center gap-2">
                            <input
                                id="{{ $fieldId }}"
                                type="checkbox"
                                name="intake_data[{{ $field['name'] }}]"
                                value="1"
                                wire:model="intake.{{ $field['name'] }}"
                                @checked( (bool) $answer )
                                @if ( $field['required'] ) required @endif
                                class="checkbox"
                                aria-invalid="{{ '' === $fieldError ? 'false' : 'true' }}"
                                @if ( null !== $described ) aria-describedby="{{ $described }}" @endif
                            >
                            <span>{{ $field['label'] }}</span>
                        </label>
                    @elseif ( 'textarea' === $field['type'] )
                        <label for="{{ $fieldId }}" class="label">{{ $field['label'] }}</label>
                        <textarea
                            id="{{ $fieldId }}"
                            name="intake_data[{{ $field['name'] }}]"
                            wire:model="intake.{{ $field['name'] }}"
                            @if ( $field['required'] ) required @endif
                            rows="3"
                            class="textarea textarea-bordered w-full"
                            aria-invalid="{{ '' === $fieldError ? 'false' : 'true' }}"
                            @if ( null !== $described ) aria-describedby="{{ $described }}" @endif
                        >{{ is_string( $answer ) ? $answer : '' }}</textarea>
                    @else
                        <label for="{{ $fieldId }}" class="label">{{ $field['label'] }}</label>
                        <input
                            id="{{ $fieldId }}"
                            type="{{ in_array( $field['type'], [ 'email', 'url', 'number', 'date', 'time' ], true ) ? $field['type'] : 'text' }}"
                            name="intake_data[{{ $field['name'] }}]"
                            wire:model="intake.{{ $field['name'] }}"
                            value="{{ is_string( $answer ) || is_numeric( $answer ) ? $answer : '' }}"
                            @if ( $field['required'] ) required @endif
                            class="input input-bordered w-full"
                            aria-invalid="{{ '' === $fieldError ? 'false' : 'true' }}"
                            @if ( null !== $described ) aria-describedby="{{ $described }}" @endif
                        >
                    @endif

                    @if ( '' !== $fieldError )
                        <p id="{{ $fieldId }}-error" class="text-error" role="alert">{{ $fieldError }}</p>
                    @endif
                </div>
            @endforeach

            <div>
                <label for="notes-{{ $uid }}" class="label">{{ __( 'Anything else we should know? (optional)' ) }}</label>
                <textarea
                    id="notes-{{ $uid }}"
                    name="notes"
                    wire:model="notes"
                    rows="3"
                    maxlength="2000"
                    class="textarea textarea-bordered w-full"
                    @error ( 'notes' ) aria-invalid="true" aria-describedby="notes-error-{{ $uid }}" @enderror
                >{{ $this->notes }}</textarea>
                @error ( 'notes' )
                    <p id="notes-error-{{ $uid }}" class="text-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="book">
                <span wire:loading.remove wire:target="book">{{ __( 'Confirm booking' ) }}</span>
                <span wire:loading wire:target="book">{{ __( 'Booking…' ) }}</span>
            </button>
        </form>

        <form method="GET" action="{{ $action }}">
            @foreach ( array_merge( $carried, [ 'bookingDate' => $this->date ] ) as $name => $value )
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach

            <button type="submit" class="btn btn-ghost btn-sm" wire:click.prevent="back">{{ __( 'Choose another time' ) }}</button>
        </form>
    @endif
</div>

@script
<script>
    // The one thing on this page the server cannot know. Reported once, on load,
    // so the slot list and the stored booking both use the clock the customer is
    // actually reading. Without it the times stay in the service's zone, which
    // the template says out loud rather than leaving to be guessed.
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    if ( zone && zone !== $wire.timezone ) {
        $wire.set( 'timezone', zone );
    }
</script>
@endscript
