{{--
    The admin recurring-series list.

    The recurring booking series a site owns, filtered by customer and switchable
    between live and cancelled, with the edit and cancel actions handed off to the
    host page as events rather than resolved here. A series somebody has moved an
    appointment out of is flagged, so a detached occurrence is visible from the
    list without opening the editor. The markup is plain HTML and utility classes
    on purpose: this component is mounted inside whatever admin shell the host
    application already runs, and pulling in a component library would fight that
    shell rather than fit it.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-series-index space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">{{ __( 'Recurring series' ) }}</h2>
    </div>

    @error( 'series' )
        <p class="rounded-box border border-error/50 bg-error/10 p-3 text-sm text-error" role="alert">
            {{ $message }}
        </p>
    @enderror

    <div class="flex flex-wrap items-center gap-4">
        <label class="flex-1">
            <span class="sr-only">{{ __( 'Search series' ) }}</span>
            <input
                type="search"
                class="input input-bordered w-full"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __( 'Search by customer name or email…' ) }}"
            />
        </label>

        <label class="flex items-center gap-2">
            <input type="checkbox" class="checkbox" wire:model.live="cancelled" />
            <span>{{ __( 'Show cancelled' ) }}</span>
        </label>
    </div>

    <div wire:loading.delay class="text-sm opacity-70" role="status">{{ __( 'Loading…' ) }}</div>

    @if ( 0 === $series->total() )
        <p class="rounded-box border border-dashed p-6 text-center opacity-70">
            {{ $cancelled ? __( 'No cancelled series.' ) : __( 'No recurring series yet.' ) }}
        </p>
    @else
        <table class="table w-full">
            <thead>
                <tr>
                    <th>{{ __( 'Customer' ) }}</th>
                    <th>{{ __( 'Service' ) }}</th>
                    <th>{{ __( 'Provider' ) }}</th>
                    <th>{{ __( 'Schedule' ) }}</th>
                    <th>{{ __( 'Occurrences' ) }}</th>
                    <th>{{ __( 'Status' ) }}</th>
                    <th class="text-right">{{ __( 'Actions' ) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $series as $item )
                    <tr wire:key="series-{{ $item->id }}">
                        <td>
                            <span class="font-medium">{{ $item->customer_name }}</span>
                            <span class="block text-xs opacity-60">{{ $item->customer_email }}</span>
                        </td>
                        <td>{{ $item->service?->name }}</td>
                        <td>{{ $item->provider?->name ?? __( 'Unassigned' ) }}</td>
                        <td>
                            <span class="font-mono text-xs">{{ $item->rrule }}</span>
                            <span class="block text-xs opacity-60">
                                {{ $item->dtstart_local->format( 'M j, Y g:i A' ) }} ({{ $item->dtstart_timezone }})
                            </span>
                        </td>
                        <td>
                            <span>{{ $item->occurrences_count }}</span>
                            @if ( 0 < $item->detached_occurrences_count )
                                <span
                                    class="badge badge-warning badge-sm"
                                    title="{{ __( 'Some occurrences have been moved away from the rule.' ) }}"
                                >
                                    {{ trans_choice( ':count detached|:count detached', $item->detached_occurrences_count, [ 'count' => $item->detached_occurrences_count ] ) }}
                                </span>
                            @endif
                        </td>
                        <td>
                            @if ( $item->isCancelled() )
                                <span class="badge badge-ghost">{{ __( 'Cancelled' ) }}</span>
                            @else
                                <span class="badge badge-success">{{ __( 'Active' ) }}</span>
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                @unless ( $item->isCancelled() )
                                    <button type="button" class="btn btn-sm" wire:click="edit( {{ $item->id }} )">
                                        {{ __( 'Edit' ) }}
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-error"
                                        wire:click="cancel( {{ $item->id }} )"
                                        wire:confirm="{{ __( 'Cancel this series and everything still to come?' ) }}"
                                    >
                                        {{ __( 'Cancel' ) }}
                                    </button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div>{{ $series->links() }}</div>
    @endif
</div>
