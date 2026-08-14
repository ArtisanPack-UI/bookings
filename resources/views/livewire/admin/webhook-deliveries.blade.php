{{--
    The admin webhook delivery ledger.

    Every attempt to deliver an event to a site's endpoints — what was sent, what
    came back, and whether the retry sweep will return — with the stored payload
    folded away until asked for, and a replay that queues a fresh delivery of a
    past attempt's event. The markup is plain HTML and utility classes on purpose:
    this component is mounted inside whatever admin shell the host already runs.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-webhook-deliveries space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">{{ __( 'Webhook deliveries' ) }}</h2>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <label class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ __( 'Endpoint' ) }}</span>
            <select class="select select-bordered" wire:model.live="webhookId">
                <option value="">{{ __( 'All endpoints' ) }}</option>
                @foreach ( $webhookOptions as $option )
                    <option value="{{ $option->id }}" wire:key="webhook-option-{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ __( 'Status' ) }}</span>
            <select class="select select-bordered" wire:model.live="status">
                <option value="">{{ __( 'All statuses' ) }}</option>
                <option value="pending">{{ __( 'Pending' ) }}</option>
                <option value="success">{{ __( 'Success' ) }}</option>
                <option value="failed">{{ __( 'Failed' ) }}</option>
                <option value="dead">{{ __( 'Dead' ) }}</option>
            </select>
        </label>
    </div>

    <div wire:loading.delay class="text-sm opacity-70" role="status">{{ __( 'Loading…' ) }}</div>

    @if ( 0 === $deliveries->total() )
        <p class="rounded-box border border-dashed p-6 text-center opacity-70">
            {{ __( 'No delivery attempts match these filters.' ) }}
        </p>
    @else
        <table class="table w-full">
            <thead>
                <tr>
                    <th>{{ __( 'Endpoint' ) }}</th>
                    <th>{{ __( 'Event' ) }}</th>
                    <th>{{ __( 'Attempt' ) }}</th>
                    <th>{{ __( 'Response' ) }}</th>
                    <th>{{ __( 'Next attempt' ) }}</th>
                    <th>{{ __( 'Status' ) }}</th>
                    <th class="text-right">{{ __( 'Actions' ) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $deliveries as $delivery )
                    <tr wire:key="delivery-{{ $delivery->id }}">
                        <td>{{ $delivery->webhook?->name ?? __( 'Unknown endpoint' ) }}</td>
                        <td>{{ $delivery->event_type }}</td>
                        <td>{{ $delivery->attempt_number }}</td>
                        <td>
                            @if ( null !== $delivery->response_status )
                                <span>{{ $delivery->response_status }}</span>
                            @else
                                <span class="opacity-60">{{ __( 'No response' ) }}</span>
                            @endif
                        </td>
                        <td>
                            @if ( $delivery->next_attempt_at )
                                <span title="{{ $delivery->next_attempt_at }}">{{ $delivery->next_attempt_at->diffForHumans() }}</span>
                            @else
                                <span class="opacity-60">{{ __( '—' ) }}</span>
                            @endif
                        </td>
                        <td>
                            @switch ( $delivery->status->value )
                                @case ( 'success' )
                                    <span class="badge badge-success">{{ __( 'Success' ) }}</span>
                                    @break
                                @case ( 'failed' )
                                    <span class="badge badge-warning">{{ __( 'Failed' ) }}</span>
                                    @break
                                @case ( 'dead' )
                                    <span class="badge badge-error">{{ __( 'Dead' ) }}</span>
                                    @break
                                @default
                                    <span class="badge badge-ghost">{{ __( 'Pending' ) }}</span>
                            @endswitch
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="btn btn-sm" wire:click="togglePayload( {{ $delivery->id }} )">
                                    {{ $expandedId === $delivery->id ? __( 'Hide payload' ) : __( 'Payload' ) }}
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-sm btn-primary"
                                    wire:click="replay( {{ $delivery->id }} )"
                                    wire:confirm="{{ __( 'Send this event to the endpoint again?' ) }}"
                                >
                                    {{ __( 'Replay' ) }}
                                </button>
                            </div>
                        </td>
                    </tr>

                    @if ( $expandedId === $delivery->id )
                        <tr wire:key="delivery-payload-{{ $delivery->id }}">
                            <td colspan="7">
                                @if ( $delivery->response_body )
                                    <p class="mb-1 text-xs font-medium opacity-70">{{ __( 'Response body' ) }}</p>
                                    <pre class="mb-3 overflow-x-auto rounded-box bg-base-200 p-3 text-xs">{{ $delivery->response_body }}</pre>
                                @endif

                                <p class="mb-1 text-xs font-medium opacity-70">{{ __( 'Payload sent' ) }}</p>
                                <pre class="overflow-x-auto rounded-box bg-base-200 p-3 text-xs">{{ json_encode( $delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) }}</pre>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>

        <div>{{ $deliveries->links() }}</div>
    @endif
</div>
