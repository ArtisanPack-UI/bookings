{{--
    The admin blackout dates list.

    A site's service-level closures, filtered by reason, with an inline form that
    opens over the list to create or edit one. The markup is plain HTML and
    utility classes on purpose: this component is mounted inside whatever admin
    shell the host application already runs, and pulling in a component library
    would fight that shell rather than fit it.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-blackout-dates-index space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">{{ __( 'Blackout dates' ) }}</h2>

        <button type="button" class="btn btn-primary" wire:click="create">
            {{ __( 'New blackout' ) }}
        </button>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <label class="flex-1">
            <span class="sr-only">{{ __( 'Search blackouts' ) }}</span>
            <input
                type="search"
                class="input input-bordered w-full"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __( 'Search by reason…' ) }}"
            />
        </label>
    </div>

    <div wire:loading.delay class="text-sm opacity-70" role="status">{{ __( 'Loading…' ) }}</div>

    @if ( $showForm )
        <form wire:submit="save" class="space-y-4 rounded-box border p-4">
            <h3 class="font-semibold">
                {{ null === $editingId ? __( 'New blackout' ) : __( 'Edit blackout' ) }}
            </h3>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-1 sm:col-span-2">
                    <span class="block text-sm font-medium">{{ __( 'Service' ) }}</span>
                    <select class="select select-bordered w-full" wire:model="serviceId">
                        <option value="">{{ __( 'All services (site-wide closure)' ) }}</option>
                        @foreach ( $services as $id => $name )
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error( 'serviceId' )
                        <span class="text-sm text-error">{{ $message }}</span>
                    @enderror
                </label>

                <label class="space-y-1">
                    <span class="block text-sm font-medium">{{ __( 'Start date' ) }}</span>
                    <input type="date" class="input input-bordered w-full" wire:model="startsOn" />
                    @error( 'startsOn' )
                        <span class="text-sm text-error">{{ $message }}</span>
                    @enderror
                </label>

                <label class="space-y-1">
                    <span class="block text-sm font-medium">{{ __( 'End date' ) }}</span>
                    <input type="date" class="input input-bordered w-full" wire:model="endsOn" />
                    @error( 'endsOn' )
                        <span class="text-sm text-error">{{ $message }}</span>
                    @enderror
                </label>

                <label class="space-y-1 sm:col-span-2">
                    <span class="block text-sm font-medium">{{ __( 'Reason' ) }}</span>
                    <input
                        type="text"
                        class="input input-bordered w-full"
                        wire:model="reason"
                        placeholder="{{ __( 'e.g. Public holiday' ) }}"
                    />
                    @error( 'reason' )
                        <span class="text-sm text-error">{{ $message }}</span>
                    @enderror
                </label>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="btn" wire:click="cancel">
                    {{ __( 'Cancel' ) }}
                </button>
                <button type="submit" class="btn btn-primary">
                    {{ __( 'Save blackout' ) }}
                </button>
            </div>
        </form>
    @endif

    @if ( 0 === $blackouts->total() )
        <p class="rounded-box border border-dashed p-6 text-center opacity-70">
            {{ __( 'No blackout dates yet. Add one to close a service for a holiday or an off-week.' ) }}
        </p>
    @else
        <table class="table w-full">
            <thead>
                <tr>
                    <th>{{ __( 'Service' ) }}</th>
                    <th>{{ __( 'Dates' ) }}</th>
                    <th>{{ __( 'Reason' ) }}</th>
                    <th class="text-right">{{ __( 'Actions' ) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $blackouts as $blackout )
                    <tr wire:key="blackout-{{ $blackout->id }}">
                        <td>
                            @if ( $blackout->isSiteWide() )
                                <span class="badge badge-neutral">{{ __( 'All services' ) }}</span>
                            @else
                                <span class="font-medium">{{ $blackout->service?->name ?? __( 'Unknown service' ) }}</span>
                            @endif
                        </td>
                        <td>
                            @if ( $blackout->starts_on->isSameDay( $blackout->ends_on ) )
                                {{ $blackout->starts_on->toFormattedDateString() }}
                            @else
                                {{ $blackout->starts_on->toFormattedDateString() }} &ndash; {{ $blackout->ends_on->toFormattedDateString() }}
                            @endif
                        </td>
                        <td>{{ $blackout->reason ?? '—' }}</td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="btn btn-sm" wire:click="edit( {{ $blackout->id }} )">
                                    {{ __( 'Edit' ) }}
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-sm btn-error"
                                    wire:click="delete( {{ $blackout->id }} )"
                                    wire:confirm="{{ __( 'Delete this blackout? The days it closes reopen for booking.' ) }}"
                                >
                                    {{ __( 'Delete' ) }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div>{{ $blackouts->links() }}</div>
    @endif
</div>
