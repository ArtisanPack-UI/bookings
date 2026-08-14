{{--
    The admin calendar connections list.

    Every calendar a site's providers have connected, with the health an operator
    watches — last sync, last error, and how many failures a connection has piled
    up — and the reconnect that clears that failure state so the next sweep tries
    again. The markup is plain HTML and utility classes on purpose: this component
    is mounted inside whatever admin shell the host application already runs.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-calendar-connections-index space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">{{ __( 'Calendar connections' ) }}</h2>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <label class="flex-1">
            <span class="sr-only">{{ __( 'Search calendar connections' ) }}</span>
            <input
                type="search"
                class="input input-bordered w-full"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __( 'Search by provider or calendar…' ) }}"
            />
        </label>

        <label class="flex items-center gap-2">
            <input type="checkbox" class="checkbox" wire:model.live="unhealthy" />
            <span>{{ __( 'Only needs attention' ) }}</span>
        </label>
    </div>

    <div wire:loading.delay class="text-sm opacity-70" role="status">{{ __( 'Loading…' ) }}</div>

    @if ( 0 === $connections->total() )
        <p class="rounded-box border border-dashed p-6 text-center opacity-70">
            {{ $unhealthy ? __( 'No connections need attention.' ) : __( 'No calendar connections yet.' ) }}
        </p>
    @else
        <table class="table w-full">
            <thead>
                <tr>
                    <th>{{ __( 'Provider' ) }}</th>
                    <th>{{ __( 'Calendar' ) }}</th>
                    <th>{{ __( 'Sync mode' ) }}</th>
                    <th>{{ __( 'Last sync' ) }}</th>
                    <th>{{ __( 'Status' ) }}</th>
                    <th class="text-right">{{ __( 'Actions' ) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $connections as $connection )
                    <tr wire:key="calendar-connection-{{ $connection->id }}">
                        <td>
                            <span class="font-medium">{{ $connection->provider?->name ?? __( 'Unknown provider' ) }}</span>
                            <span class="block text-xs opacity-60">{{ $connection->driver->value }}</span>
                        </td>
                        <td>
                            <span class="block max-w-xs truncate">{{ $connection->external_calendar_id }}</span>
                            @if ( $connection->last_sync_error )
                                <span class="block text-xs text-error">{{ $connection->last_sync_error }}</span>
                            @endif
                        </td>
                        <td>{{ $connection->sync_mode->value }}</td>
                        <td>
                            @if ( $connection->last_sync_at )
                                <span title="{{ $connection->last_sync_at }}">{{ $connection->last_sync_at->diffForHumans() }}</span>
                            @else
                                <span class="opacity-60">{{ __( 'Never' ) }}</span>
                            @endif
                        </td>
                        <td>
                            @if ( $connection->isDisabled() )
                                <span class="badge badge-error">{{ __( 'Disabled' ) }}</span>
                            @elseif ( $connection->consecutive_failure_count > 0 )
                                <span class="badge badge-warning">
                                    {{ trans_choice( ':count failure|:count failures', $connection->consecutive_failure_count, [ 'count' => $connection->consecutive_failure_count ] ) }}
                                </span>
                            @else
                                <span class="badge badge-success">{{ __( 'Healthy' ) }}</span>
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                @if ( $connection->isDisabled() || $connection->consecutive_failure_count > 0 )
                                    <button type="button" class="btn btn-sm btn-primary" wire:click="reconnect( {{ $connection->id }} )">
                                        {{ __( 'Reconnect' ) }}
                                    </button>
                                @endif

                                @unless ( $connection->isDisabled() )
                                    <button
                                        type="button"
                                        class="btn btn-sm"
                                        wire:click="disable( {{ $connection->id }} )"
                                        wire:confirm="{{ __( 'Stop syncing this calendar? Its bookings stay put.' ) }}"
                                    >
                                        {{ __( 'Disable' ) }}
                                    </button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div>{{ $connections->links() }}</div>
    @endif
</div>
