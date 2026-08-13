{{--
    The self-serve booking management page.

    Reached from the link in a customer's confirmation email, so it opens on what
    was booked rather than on a form: the appointment, then a way out of it, then
    a way to move it. Both writes are real <form> submissions with a confirmation
    step in front of them — a cancellation is not undoable, and a stray click on
    a phone should not be all it takes.

    Field ids carry the component id so that a host page embedding anything else
    of this package's does not hand a screen reader two labels pointing at one
    input.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@php
    $uid      = $this->getId();
    $booking  = $this->booking;
    $zone     = $this->displayTimezone;
    $month    = $this->browsedMonth;
    $deadline = $this->changesAllowedUntil;

    // `Z` rather than `+00:00`: a `+` decodes to a space wherever this value
    // travels through a query string, and comes back as an instant nothing can
    // parse.
    $instant = static fn ( $moment ): string => \ArtisanPackUI\Bookings\Livewire\Public\BookingWidget::instantKey( $moment );

    // Every date read here has already been through the component's own parsing —
    // the keys of the grouped slots are built server-side, and `$this->date` is
    // canonicalised on every change — so the format is exact rather than a guess.
    $asDay = static fn ( string $date ): \Carbon\CarbonImmutable => \Carbon\CarbonImmutable::createFromFormat( '!Y-m-d', $date, $zone );
@endphp

<div class="artisanpack-manage-booking space-y-6" role="region" aria-label="{{ __( 'Manage your booking' ) }}">
    @if ( null === $booking )
        <p role="alert">{{ __( 'That booking link is no longer valid. Please check your confirmation email.' ) }}</p>
    @else
        @php $start = $booking->start_time->copy()->setTimezone( $zone ); @endphp

        <div aria-live="polite">
            @if ( null !== $this->notice )
                <p class="rounded-box border border-success p-4" role="status">{{ $this->notice }}</p>
            @endif
        </div>

        <div class="space-y-2">
            <h2 class="text-lg font-semibold">{{ __( 'Your appointment' ) }}</h2>

            <p>
                {{ __( ':service on :when (:timezone)', [
                    'service'  => $booking->service?->name ?? __( 'Appointment' ),
                    'when'     => $start->translatedFormat( 'l j F Y, g:i a' ),
                    'timezone' => $zone,
                ] ) }}
            </p>

            @if ( null !== $booking->provider )
                <p>{{ __( 'With :provider', [ 'provider' => $booking->provider->name ] ) }}</p>
            @endif

            <p>{{ __( 'Reference: :number', [ 'number' => $booking->booking_number ] ) }}</p>

            {{-- The status is said out loud rather than left to be inferred from
                 which buttons are missing: a customer who has just cancelled, or
                 whose appointment was cancelled from the other end, is the person
                 most in need of being told. --}}
            <p>{{ __( 'Status: :status', [ 'status' => $this->statusLabel ] ) }}</p>

            @if ( null !== $deadline && $booking->occupiesSlot() )
                <p class="text-sm">
                    {{ __( 'Changes can be made online until :when (:timezone).', [
                        'when'     => $deadline->setTimezone( $zone )->translatedFormat( 'l j F Y, g:i a' ),
                        'timezone' => $zone,
                    ] ) }}
                </p>
            @endif
        </div>

        @error ( 'cancel' )
            <p class="text-error" role="alert">{{ $message }}</p>
        @enderror

        {{-- ---------------------------------------------------------------- --}}
        @if ( $this->confirmingCancel )
            <form wire:submit="cancel" class="space-y-4">
                <h3 class="text-lg font-semibold">{{ __( 'Cancel this appointment?' ) }}</h3>

                <p>{{ __( 'This cannot be undone. The time will be offered to somebody else.' ) }}</p>

                <div>
                    <label for="reason-{{ $uid }}" class="label">{{ __( 'Why are you cancelling? (optional)' ) }}</label>
                    <textarea
                        id="reason-{{ $uid }}"
                        wire:model="reason"
                        rows="3"
                        maxlength="1000"
                        class="textarea textarea-bordered w-full"
                        @error ( 'reason' ) aria-invalid="true" aria-describedby="reason-error-{{ $uid }}" @enderror
                    >{{ $this->reason }}</textarea>
                    @error ( 'reason' )
                        <p id="reason-error-{{ $uid }}" class="text-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-error" wire:loading.attr="disabled" wire:target="cancel">
                        <span wire:loading.remove wire:target="cancel">{{ __( 'Yes, cancel it' ) }}</span>
                        <span wire:loading wire:target="cancel">{{ __( 'Cancelling…' ) }}</span>
                    </button>

                    <button type="button" class="btn btn-ghost" wire:click="stopCancel">
                        {{ __( 'Keep my appointment' ) }}
                    </button>
                </div>
            </form>

        {{-- ---------------------------------------------------------------- --}}
        @elseif ( $this->choosingTime )
            <form wire:submit="reschedule" class="space-y-4">
                <h3 class="text-lg font-semibold">{{ __( 'Choose a new time' ) }}</h3>

                <p class="text-sm">{{ __( 'Times are shown in :timezone.', [ 'timezone' => $zone ] ) }}</p>

                @error ( 'slotStart' )
                    <p class="text-error" role="alert">{{ $message }}</p>
                @enderror

                <div class="flex items-center justify-between gap-2">
                    <button type="button" class="btn btn-sm" wire:click="shiftMonth(-1)">
                        <span aria-hidden="true">←</span>
                        <span class="sr-only">{{ __( 'Previous month' ) }}</span>
                    </button>

                    <h4 class="text-lg font-semibold">{{ $month->translatedFormat( 'F Y' ) }}</h4>

                    <button type="button" class="btn btn-sm" wire:click="shiftMonth(1)">
                        <span aria-hidden="true">→</span>
                        <span class="sr-only">{{ __( 'Next month' ) }}</span>
                    </button>
                </div>

                <fieldset>
                    <legend class="font-semibold mb-2">{{ __( 'Available days' ) }}</legend>

                    @forelse ( array_keys( $this->slotsByDay ) as $day )
                        <button
                            type="button"
                            wire:key="day-{{ $day }}"
                            wire:click="chooseDate({{ \Illuminate\Support\Js::from( $day ) }})"
                            @if ( $day === $this->date ) aria-current="date" @endif
                            @class( [ 'btn btn-sm mb-2 mr-2', 'btn-primary' => $day === $this->date ] )
                        >
                            {{ $asDay( $day )->translatedFormat( 'D j' ) }}
                        </button>
                    @empty
                        <p>{{ __( 'There are no appointments available this month.' ) }}</p>
                    @endforelse
                </fieldset>

                @if ( '' !== $this->date )
                    <fieldset>
                        <legend class="font-semibold mb-2">
                            {{ __( 'Times on :date', [ 'date' => $asDay( $this->date )->translatedFormat( 'l j F' ) ] ) }}
                        </legend>

                        @forelse ( $this->slotsOnDate as $slot )
                            @php $key = $instant( $slot->period->start ); @endphp

                            <button
                                type="button"
                                wire:key="slot-{{ $key }}"
                                wire:click="chooseSlot({{ \Illuminate\Support\Js::from( $key ) }})"
                                @if ( $key === $this->slotStart ) aria-current="time" @endif
                                @class( [ 'btn btn-sm mb-2 mr-2', 'btn-primary' => $key === $this->slotStart ] )
                            >
                                <time datetime="{{ $key }}">
                                    {{ $slot->period->start->copy()->setTimezone( $zone )->translatedFormat( 'g:i a' ) }}
                                </time>
                            </button>
                        @empty
                            <p>{{ __( 'There is nothing left on that day. Please choose another.' ) }}</p>
                        @endforelse
                    </fieldset>
                @endif

                <div class="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        class="btn btn-primary"
                        @disabled( null === $this->chosenStart )
                        wire:loading.attr="disabled"
                        wire:target="reschedule"
                    >
                        <span wire:loading.remove wire:target="reschedule">{{ __( 'Confirm new time' ) }}</span>
                        <span wire:loading wire:target="reschedule">{{ __( 'Moving…' ) }}</span>
                    </button>

                    <button type="button" class="btn btn-ghost" wire:click="stopReschedule">
                        {{ __( 'Keep my current time' ) }}
                    </button>
                </div>
            </form>

        {{-- ---------------------------------------------------------------- --}}
        @else
            @error ( 'slotStart' )
                <p class="text-error" role="alert">{{ $message }}</p>
            @enderror

            @if ( $this->canCancel || $this->canReschedule )
                <div class="flex flex-wrap gap-2">
                    @if ( $this->canReschedule )
                        <button type="button" class="btn btn-primary" wire:click="startReschedule">
                            {{ __( 'Reschedule' ) }}
                        </button>
                    @endif

                    @if ( $this->canCancel )
                        <button type="button" class="btn" wire:click="startCancel">
                            {{ __( 'Cancel appointment' ) }}
                        </button>
                    @endif
                </div>
            @elseif ( $booking->occupiesSlot() )
                {{-- Why there are no buttons, rather than a page that simply has
                     none. A customer who cannot see how to cancel telephones; one
                     who has been told why telephones about the right thing. --}}
                <p>{{ __( 'This appointment can no longer be changed online. Please contact us instead.' ) }}</p>
            @endif
        @endif
    @endif
</div>

@script
<script>
    // The one thing on this page the server cannot know. Reported once, on load,
    // so the appointment and any new time are both read in the clock the customer
    // is actually looking at. Without it the times stay in the zone the booking
    // was made in, which the page says out loud rather than leaving to be guessed.
    //
    // Wrapped in an expression, and on Livewire 3 it has to be: that version
    // hands this block straight to Alpine.evaluate(), which compiles it as an
    // expression rather than as a statement list — so a bare const at the top is
    // a SyntaxError, the block never runs, and every time on the page silently
    // stays in the wrong zone with nothing but a console warning to say so.
    // Livewire 4 wraps the block itself; the package supports both, so the
    // wrapper stays.
    ( function () {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if ( zone && zone !== $wire.timezone ) {
            $wire.set( 'timezone', zone );
        }
    } )()
</script>
@endscript
