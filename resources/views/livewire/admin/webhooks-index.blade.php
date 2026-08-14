{{--
    The admin webhooks list.

    The outbound endpoints a site delivers events to, with the failure count that
    decides whether one is healthy, a way in to each one's delivery ledger, and
    the enable that clears a disabled endpoint's failures for a clean restart. The
    markup is plain HTML and utility classes on purpose: this component is mounted
    inside whatever admin shell the host application already runs.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-webhooks-index space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">{{ __( 'Webhooks' ) }}</h2>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <label class="flex-1">
            <span class="sr-only">{{ __( 'Search webhooks' ) }}</span>
            <input
                type="search"
                class="input input-bordered w-full"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __( 'Search by name or URL…' ) }}"
            />
        </label>

        <label class="flex items-center gap-2">
            <input type="checkbox" class="checkbox" wire:model.live="disabledOnly" />
            <span>{{ __( 'Only disabled' ) }}</span>
        </label>
    </div>

    <div wire:loading.delay class="text-sm opacity-70" role="status">{{ __( 'Loading…' ) }}</div>

    @if ( 0 === $webhooks->total() )
        <p class="rounded-box border border-dashed p-6 text-center opacity-70">
            {{ $disabledOnly ? __( 'No disabled webhooks.' ) : __( 'No webhooks yet.' ) }}
        </p>
    @else
        <table class="table w-full">
            <thead>
                <tr>
                    <th>{{ __( 'Name' ) }}</th>
                    <th>{{ __( 'Events' ) }}</th>
                    <th>{{ __( 'Last success' ) }}</th>
                    <th>{{ __( 'Status' ) }}</th>
                    <th class="text-right">{{ __( 'Actions' ) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $webhooks as $webhook )
                    <tr wire:key="webhook-{{ $webhook->id }}">
                        <td>
                            <span class="font-medium">{{ $webhook->name }}</span>
                            <span class="block max-w-xs truncate text-xs opacity-60">{{ $webhook->url }}</span>
                        </td>
                        <td>
                            <span class="text-xs">{{ implode( ', ', $webhook->events ?? [] ) }}</span>
                        </td>
                        <td>
                            @if ( $webhook->last_success_at )
                                <span title="{{ $webhook->last_success_at }}">{{ $webhook->last_success_at->diffForHumans() }}</span>
                            @else
                                <span class="opacity-60">{{ __( 'Never' ) }}</span>
                            @endif
                        </td>
                        <td>
                            @if ( $webhook->isDisabled() )
                                <span class="badge badge-error">{{ __( 'Disabled' ) }}</span>
                            @elseif ( $webhook->consecutive_failure_count > 0 )
                                <span class="badge badge-warning">
                                    {{ trans_choice( ':count failure|:count failures', $webhook->consecutive_failure_count, [ 'count' => $webhook->consecutive_failure_count ] ) }}
                                </span>
                            @else
                                <span class="badge badge-success">{{ __( 'Active' ) }}</span>
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="btn btn-sm" wire:click="viewDeliveries( {{ $webhook->id }} )">
                                    {{ __( 'Deliveries' ) }}
                                    <span class="badge badge-sm">{{ $webhook->deliveries_count }}</span>
                                </button>

                                @if ( $webhook->isDisabled() )
                                    <button type="button" class="btn btn-sm btn-primary" wire:click="enable( {{ $webhook->id }} )">
                                        {{ __( 'Enable' ) }}
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        class="btn btn-sm"
                                        wire:click="disable( {{ $webhook->id }} )"
                                        wire:confirm="{{ __( 'Stop delivering to this endpoint?' ) }}"
                                    >
                                        {{ __( 'Disable' ) }}
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div>{{ $webhooks->links() }}</div>
    @endif
</div>
