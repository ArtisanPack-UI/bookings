{{--
    The admin booking calendar.

    A week of bookings laid out seven days across, rendered on the server from the
    same models the list uses. Choosing a booking hands off to the detail view as
    an event rather than resolving here. The markup is plain HTML and utility
    classes on purpose: this component is mounted inside whatever admin shell the
    host application already runs.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-calendar space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">
            {{ $days[0]->isoFormat( 'll' ) }} – {{ $days[6]->isoFormat( 'll' ) }}
        </h2>

        <div class="flex items-center gap-2">
            <button type="button" class="btn btn-sm" wire:click="previousWeek">{{ __( 'Previous' ) }}</button>
            <button type="button" class="btn btn-sm" wire:click="thisWeek">{{ __( 'This week' ) }}</button>
            <button type="button" class="btn btn-sm" wire:click="nextWeek">{{ __( 'Next' ) }}</button>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <label>
            <span class="sr-only">{{ __( 'Filter by provider' ) }}</span>
            <select class="select select-bordered" wire:model.live="providerId">
                <option value="">{{ __( 'All providers' ) }}</option>
                @foreach ( $providers as $id => $name )
                    <option value="{{ $id }}" wire:key="cal-provider-{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>

        <label>
            <span class="sr-only">{{ __( 'Filter by service' ) }}</span>
            <select class="select select-bordered" wire:model.live="serviceId">
                <option value="">{{ __( 'All services' ) }}</option>
                @foreach ( $services as $id => $name )
                    <option value="{{ $id }}" wire:key="cal-service-{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <div wire:loading.delay class="text-sm opacity-70" role="status">{{ __( 'Loading…' ) }}</div>

    <div class="grid gap-3 md:grid-cols-7">
        @foreach ( $days as $day )
            @php ( $dayKey = $day->toDateString() )
            @php ( $dayBookings = $bookingsByDay[ $dayKey ] ?? collect() )
            <div class="rounded-box border p-2 space-y-2" wire:key="day-{{ $dayKey }}">
                <div class="text-xs font-semibold uppercase opacity-70">
                    {{ $day->isoFormat( 'ddd' ) }}
                    <span class="block text-sm opacity-100">{{ $day->isoFormat( 'D MMM' ) }}</span>
                </div>

                @forelse ( $dayBookings as $booking )
                    @php ( $service = $booking->service )
                    {{-- Painted in the service colour only when a readable text
                         colour is available too: contrast_color is null when
                         artisanpack-ui/accessibility is not installed, and a
                         coloured chip with unreadable text is worse than none. --}}
                    <button
                        type="button"
                        @class( [ 'block w-full rounded-box border px-2 py-1 text-left text-sm', 'hover:bg-base-200' => null === $service?->contrast_color ] )
                        @if ( null !== $service?->color && null !== $service?->contrast_color )
                            style="background-color: {{ $service->color }}; color: {{ $service->contrast_color }}; border-color: {{ $service->color }};"
                        @endif
                        wire:key="cal-booking-{{ $booking->id }}"
                        wire:click="view( {{ $booking->id }} )"
                    >
                        <span class="font-medium">{{ $booking->start_time->copy()->setTimezone( $timezone )->isoFormat( 'LT' ) }}</span>
                        <span class="block truncate">{{ $booking->customer_name }}</span>
                        <span class="block truncate text-xs opacity-60">{{ $service?->name }}</span>
                    </button>
                @empty
                    <p class="text-xs opacity-40">{{ __( 'No bookings' ) }}</p>
                @endforelse
            </div>
        @endforeach
    </div>
</div>
