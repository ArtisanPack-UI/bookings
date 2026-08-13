{{--
    The admin services list.

    The services a site owns, filtered and switchable, with the create and edit
    actions handed off to the host page as events rather than resolved here. The
    markup is plain HTML and utility classes on purpose: this component is mounted
    inside whatever admin shell the host application already runs, and pulling in
    a component library would fight that shell rather than fit it.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-services-index space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">{{ __( 'Services' ) }}</h2>

        <button type="button" class="btn btn-primary" wire:click="create">
            {{ __( 'New service' ) }}
        </button>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <label class="flex-1">
            <span class="sr-only">{{ __( 'Search services' ) }}</span>
            <input
                type="search"
                class="input input-bordered w-full"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __( 'Search by name or slug…' ) }}"
            />
        </label>

        <label class="flex items-center gap-2">
            <input type="checkbox" class="checkbox" wire:model.live="trashed" />
            <span>{{ __( 'Show retired' ) }}</span>
        </label>
    </div>

    <div wire:loading.delay class="text-sm opacity-70" role="status">{{ __( 'Loading…' ) }}</div>

    @if ( 0 === $services->total() )
        <p class="rounded-box border border-dashed p-6 text-center opacity-70">
            {{ $trashed ? __( 'No retired services.' ) : __( 'No services yet. Create your first one.' ) }}
        </p>
    @else
        <table class="table w-full">
            <thead>
                <tr>
                    <th>{{ __( 'Name' ) }}</th>
                    <th>{{ __( 'Duration' ) }}</th>
                    <th>{{ __( 'Price' ) }}</th>
                    <th>{{ __( 'Status' ) }}</th>
                    <th class="text-right">{{ __( 'Actions' ) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $services as $service )
                    <tr wire:key="service-{{ $service->id }}">
                        <td>
                            <span class="font-medium">{{ $service->name }}</span>
                            <span class="block text-xs opacity-60">{{ $service->slug }}</span>
                        </td>
                        <td>{{ trans_choice( ':count minute|:count minutes', $service->duration, [ 'count' => $service->duration ] ) }}</td>
                        <td>{{ $service->is_free ? __( 'Free' ) : ( null === $service->price ? '—' : $service->price ) }}</td>
                        <td>
                            @if ( $trashed )
                                <span class="badge">{{ __( 'Retired' ) }}</span>
                            @else
                                <span class="badge {{ $service->is_active ? 'badge-success' : 'badge-ghost' }}">
                                    {{ $service->is_active ? __( 'Active' ) : __( 'Inactive' ) }}
                                </span>
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                @if ( $trashed )
                                    <button type="button" class="btn btn-sm" wire:click="restore( {{ $service->id }} )">
                                        {{ __( 'Restore' ) }}
                                    </button>
                                @else
                                    <button type="button" class="btn btn-sm" wire:click="edit( {{ $service->id }} )">
                                        {{ __( 'Edit' ) }}
                                    </button>

                                    <button type="button" class="btn btn-sm" wire:click="toggleActive( {{ $service->id }} )">
                                        {{ $service->is_active ? __( 'Deactivate' ) : __( 'Activate' ) }}
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-error"
                                        wire:click="delete( {{ $service->id }} )"
                                        wire:confirm="{{ __( 'Retire this service? Its past bookings stay readable.' ) }}"
                                    >
                                        {{ __( 'Retire' ) }}
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div>{{ $services->links() }}</div>
    @endif
</div>
