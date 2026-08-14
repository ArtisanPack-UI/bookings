{{--
    The admin bookings list.

    Every booking the site owns, narrowed by search, status, provider, and
    service, with the single-booking actions handed off to the detail view rather
    than resolved here. The markup is plain HTML and utility classes on purpose:
    this component is mounted inside whatever admin shell the host application
    already runs, and pulling in a component library would fight that shell rather
    than fit it.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-index space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">{{ __( 'Bookings' ) }}</h2>

        <button type="button" class="btn" wire:click="export">
            {{ __( 'Export CSV' ) }}
        </button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <label class="lg:col-span-1">
            <span class="sr-only">{{ __( 'Search bookings' ) }}</span>
            <input
                type="search"
                class="input input-bordered w-full"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __( 'Name, email, or number…' ) }}"
            />
        </label>

        <label>
            <span class="sr-only">{{ __( 'Filter by status' ) }}</span>
            <select class="select select-bordered w-full" wire:model.live="status">
                <option value="">{{ __( 'All statuses' ) }}</option>
                @foreach ( $statuses as $case )
                    <option value="{{ $case->value }}" wire:key="status-{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
        </label>

        <label>
            <span class="sr-only">{{ __( 'Filter by provider' ) }}</span>
            <select class="select select-bordered w-full" wire:model.live="providerId">
                <option value="">{{ __( 'All providers' ) }}</option>
                @foreach ( $providers as $id => $name )
                    <option value="{{ $id }}" wire:key="provider-{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>

        <label>
            <span class="sr-only">{{ __( 'Filter by service' ) }}</span>
            <select class="select select-bordered w-full" wire:model.live="serviceId">
                <option value="">{{ __( 'All services' ) }}</option>
                @foreach ( $services as $id => $name )
                    <option value="{{ $id }}" wire:key="service-{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <div wire:loading.delay class="text-sm opacity-70" role="status">{{ __( 'Loading…' ) }}</div>

    @if ( 0 === $bookings->total() )
        <p class="rounded-box border border-dashed p-6 text-center opacity-70">
            {{ __( 'No bookings match these filters.' ) }}
        </p>
    @else
        <table class="table w-full">
            <thead>
                <tr>
                    <th>{{ __( 'Booking' ) }}</th>
                    <th>{{ __( 'When' ) }}</th>
                    <th>{{ __( 'Service' ) }}</th>
                    <th>{{ __( 'Provider' ) }}</th>
                    <th>{{ __( 'Status' ) }}</th>
                    <th class="text-right">{{ __( 'Actions' ) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $bookings as $booking )
                    <tr wire:key="booking-{{ $booking->id }}">
                        <td>
                            <span class="font-medium">{{ $booking->customer_name }}</span>
                            <span class="block text-xs opacity-60">{{ $booking->booking_number }}</span>
                        </td>
                        <td>{{ $booking->start_time->isoFormat( 'lll' ) }}</td>
                        <td>{{ $booking->service?->name ?? '—' }}</td>
                        <td>{{ $booking->provider?->name ?? '—' }}</td>
                        <td>
                            <span class="badge">{{ $booking->status->label() }}</span>
                        </td>
                        <td class="text-right">
                            <button type="button" class="btn btn-sm" wire:click="view( {{ $booking->id }} )">
                                {{ __( 'View' ) }}
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div>{{ $bookings->links() }}</div>
    @endif
</div>
