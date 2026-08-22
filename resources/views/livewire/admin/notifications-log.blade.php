{{--
    The admin notifications log.

    Every notification the package tried to send about a site's bookings — the
    channel, who it was addressed to, whether it was sent, is pending, or failed,
    and the reason a failure gave. It is read-only: what went wrong is a thing to
    read, not a row to retry. The markup is plain HTML and utility classes on
    purpose: this component is mounted inside whatever admin shell the host runs.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-notifications-log space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">{{ __( 'Notifications log' ) }}</h2>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <label class="flex-1">
            <span class="sr-only">{{ __( 'Search notifications' ) }}</span>
            <input
                type="search"
                class="input input-bordered w-full"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __( 'Search by recipient or booking…' ) }}"
            />
        </label>

        <label class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ __( 'Status' ) }}</span>
            <select class="select select-bordered" wire:model.live="status">
                <option value="">{{ __( 'All' ) }}</option>
                <option value="pending">{{ __( 'Pending' ) }}</option>
                <option value="sent">{{ __( 'Sent' ) }}</option>
                <option value="failed">{{ __( 'Failed' ) }}</option>
            </select>
        </label>

        <label class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ __( 'Type' ) }}</span>
            <select class="select select-bordered" wire:model.live="type">
                <option value="">{{ __( 'All' ) }}</option>
                <option value="confirmation">{{ __( 'Confirmation' ) }}</option>
                <option value="reminder">{{ __( 'Reminder' ) }}</option>
                <option value="cancellation">{{ __( 'Cancellation' ) }}</option>
                <option value="reschedule">{{ __( 'Reschedule' ) }}</option>
                <option value="no_show">{{ __( 'No-show' ) }}</option>
            </select>
        </label>

        <label class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ __( 'Channel' ) }}</span>
            <select class="select select-bordered" wire:model.live="channel">
                <option value="">{{ __( 'All' ) }}</option>
                @foreach ( $channels as $channelOption )
                    <option value="{{ $channelOption }}" wire:key="channel-option-{{ $channelOption }}">{{ $channelOption }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <div wire:loading.delay class="text-sm opacity-70" role="status">{{ __( 'Loading…' ) }}</div>

    @if ( 0 === $notifications->total() )
        <p class="rounded-box border border-dashed p-6 text-center opacity-70">
            {{ __( 'No notifications match these filters.' ) }}
        </p>
    @else
        <table class="table w-full">
            <thead>
                <tr>
                    <th>{{ __( 'Booking' ) }}</th>
                    <th>{{ __( 'Type' ) }}</th>
                    <th>{{ __( 'Channel' ) }}</th>
                    <th>{{ __( 'Recipient' ) }}</th>
                    <th>{{ __( 'Sent' ) }}</th>
                    <th>{{ __( 'Status' ) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $notifications as $notification )
                    <tr wire:key="notification-{{ $notification->id }}">
                        <td>{{ $notification->booking?->booking_number ?? __( 'Unknown' ) }}</td>
                        <td>{{ $notification->type->value }}</td>
                        <td>{{ $notification->channel }}</td>
                        <td>
                            <span class="block max-w-xs truncate">{{ $notification->recipient }}</span>
                        </td>
                        <td>
                            @if ( $notification->sent_at )
                                <span title="{{ $notification->sent_at }}">{{ $notification->sent_at->diffForHumans() }}</span>
                            @else
                                <span class="opacity-60">{{ __( 'Not sent' ) }}</span>
                            @endif
                        </td>
                        <td>
                            @switch ( $notification->status->value )
                                @case ( 'sent' )
                                    <span class="badge badge-success">{{ __( 'Sent' ) }}</span>
                                    @break
                                @case ( 'failed' )
                                    <span class="badge badge-error">{{ __( 'Failed' ) }}</span>
                                    <span class="block text-xs text-error">{{ $notification->error }}</span>
                                    @break
                                @default
                                    <span class="badge badge-ghost">{{ __( 'Pending' ) }}</span>
                            @endswitch
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div>{{ $notifications->links() }}</div>
    @endif
</div>
