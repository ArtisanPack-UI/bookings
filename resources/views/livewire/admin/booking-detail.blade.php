{{--
    The admin booking detail.

    One booking in full — its customer, its times, the intake answers as the form
    stood when it was made — and the three transitions an administrator can make:
    cancel, reschedule, and no-show. Each transition posts to a method that routes
    through the booking service, so the markup here is only the form around it. The
    markup is plain HTML and utility classes on purpose: this component is mounted
    inside whatever admin shell the host application already runs.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-detail space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold">{{ $booking->customer_name }}</h2>
            <p class="text-xs opacity-60">{{ $booking->booking_number }}</p>
        </div>

        <span class="badge">{{ $booking->status->label() }}</span>
    </div>

    @error( 'booking' )
        <p class="text-error" role="alert">{{ $message }}</p>
    @enderror

    <dl class="grid gap-4 sm:grid-cols-2">
        <div>
            <dt class="text-xs uppercase opacity-60">{{ __( 'Service' ) }}</dt>
            <dd>{{ $booking->service?->name ?? '—' }}</dd>
        </div>

        <div>
            <dt class="text-xs uppercase opacity-60">{{ __( 'Provider' ) }}</dt>
            <dd>{{ $booking->provider?->name ?? '—' }}</dd>
        </div>

        <div>
            <dt class="text-xs uppercase opacity-60">{{ __( 'Starts' ) }}</dt>
            <dd>{{ $booking->start_time->isoFormat( 'lll' ) }}</dd>
        </div>

        <div>
            <dt class="text-xs uppercase opacity-60">{{ __( 'Ends' ) }}</dt>
            <dd>{{ $booking->end_time->isoFormat( 'lll' ) }}</dd>
        </div>

        <div>
            <dt class="text-xs uppercase opacity-60">{{ __( 'Email' ) }}</dt>
            <dd>{{ $booking->customer_email }}</dd>
        </div>

        <div>
            <dt class="text-xs uppercase opacity-60">{{ __( 'Phone' ) }}</dt>
            <dd>{{ $booking->customer_phone ?: '—' }}</dd>
        </div>
    </dl>

    <section class="space-y-2">
        <h3 class="font-semibold">{{ __( 'Intake answers' ) }}</h3>

        @if ( [] === $intakeAnswers )
            <p class="opacity-70">{{ __( 'No intake answers were captured.' ) }}</p>
        @else
            <dl class="grid gap-3 sm:grid-cols-2">
                @foreach ( $intakeAnswers as $index => $answer )
                    <div wire:key="intake-{{ $index }}">
                        <dt class="text-xs uppercase opacity-60">{{ $answer['label'] }}</dt>
                        <dd>{{ '' === $answer['value'] ? '—' : $answer['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    @if ( $booking->occupiesSlot() )
        <section class="grid gap-6 lg:grid-cols-3">
            <form wire:submit="reschedule" class="space-y-2 lg:col-span-2">
                <h3 class="font-semibold">{{ __( 'Reschedule' ) }}</h3>

                <label class="block">
                    <span class="sr-only">{{ __( 'New start time' ) }}</span>
                    <input
                        type="datetime-local"
                        class="input input-bordered w-full"
                        wire:model="rescheduleStart"
                    />
                </label>

                @error( 'rescheduleStart' )
                    <p class="text-error" role="alert">{{ $message }}</p>
                @enderror

                <button type="submit" class="btn">{{ __( 'Move booking' ) }}</button>
            </form>

            <div class="space-y-2">
                <h3 class="font-semibold">{{ __( 'No-show' ) }}</h3>

                <button
                    type="button"
                    class="btn btn-warning"
                    wire:click="markNoShow"
                    wire:confirm="{{ __( 'Mark this booking as a no-show?' ) }}"
                >
                    {{ __( 'Mark as no-show' ) }}
                </button>
            </div>
        </section>

        <form wire:submit="cancel" class="space-y-2">
            <h3 class="font-semibold">{{ __( 'Cancel' ) }}</h3>

            <label class="block">
                <span class="text-sm">{{ __( 'Reason (optional)' ) }}</span>
                <input
                    type="text"
                    class="input input-bordered w-full"
                    wire:model="cancelReason"
                    placeholder="{{ __( 'Shared with the customer.' ) }}"
                />
            </label>

            <button
                type="submit"
                class="btn btn-error"
                wire:confirm="{{ __( 'Cancel this booking? The customer is notified.' ) }}"
            >
                {{ __( 'Cancel booking' ) }}
            </button>
        </form>
    @else
        <p class="rounded-box border border-dashed p-4 opacity-70">
            {{ __( 'This booking is no longer active, so it cannot be changed.' ) }}
        </p>
    @endif
</div>
