{{--
    The admin providers list.

    The service providers a site owns, filtered and switchable, with the create,
    edit, and availability actions handed off to the host page as events rather
    than resolved here. The markup is plain HTML and utility classes on purpose:
    this component is mounted inside whatever admin shell the host application
    already runs, and pulling in a component library would fight that shell
    rather than fit it.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-providers-index space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">{{ __( 'Providers' ) }}</h2>

        <button type="button" class="btn btn-primary" wire:click="create">
            {{ __( 'New provider' ) }}
        </button>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <label class="flex-1">
            <span class="sr-only">{{ __( 'Search providers' ) }}</span>
            <input
                type="search"
                class="input input-bordered w-full"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __( 'Search by name, slug, or email…' ) }}"
            />
        </label>

        <label class="flex items-center gap-2">
            <input type="checkbox" class="checkbox" wire:model.live="trashed" />
            <span>{{ __( 'Show retired' ) }}</span>
        </label>
    </div>

    <div wire:loading.delay class="text-sm opacity-70" role="status">{{ __( 'Loading…' ) }}</div>

    @if ( 0 === $providers->total() )
        <p class="rounded-box border border-dashed p-6 text-center opacity-70">
            {{ $trashed ? __( 'No retired providers.' ) : __( 'No providers yet. Create your first one.' ) }}
        </p>
    @else
        <table class="table w-full">
            <thead>
                <tr>
                    <th>{{ __( 'Name' ) }}</th>
                    <th>{{ __( 'Timezone' ) }}</th>
                    <th>{{ __( 'Weight' ) }}</th>
                    <th>{{ __( 'Status' ) }}</th>
                    <th class="text-right">{{ __( 'Actions' ) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $providers as $provider )
                    <tr wire:key="provider-{{ $provider->id }}">
                        <td>
                            <span class="font-medium">{{ $provider->name }}</span>
                            <span class="block text-xs opacity-60">{{ $provider->slug }}</span>
                        </td>
                        <td>{{ $provider->timezone }}</td>
                        <td>{{ $provider->round_robin_weight }}</td>
                        <td>
                            @if ( $trashed )
                                <span class="badge">{{ __( 'Retired' ) }}</span>
                            @else
                                <span class="badge {{ $provider->is_active ? 'badge-success' : 'badge-ghost' }}">
                                    {{ $provider->is_active ? __( 'Active' ) : __( 'Inactive' ) }}
                                </span>
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                @if ( $trashed )
                                    <button type="button" class="btn btn-sm" wire:click="restore( {{ $provider->id }} )">
                                        {{ __( 'Restore' ) }}
                                    </button>
                                @else
                                    <button type="button" class="btn btn-sm" wire:click="edit( {{ $provider->id }} )">
                                        {{ __( 'Edit' ) }}
                                    </button>

                                    <button type="button" class="btn btn-sm" wire:click="editAvailability( {{ $provider->id }} )">
                                        {{ __( 'Availability' ) }}
                                    </button>

                                    <button type="button" class="btn btn-sm" wire:click="toggleActive( {{ $provider->id }} )">
                                        {{ $provider->is_active ? __( 'Deactivate' ) : __( 'Activate' ) }}
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-error"
                                        wire:click="delete( {{ $provider->id }} )"
                                        wire:confirm="{{ __( 'Retire this provider? Their past bookings stay readable.' ) }}"
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

        <div>{{ $providers->links() }}</div>
    @endif
</div>
