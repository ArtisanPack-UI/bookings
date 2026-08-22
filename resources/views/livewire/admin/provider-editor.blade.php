{{--
    The admin provider editor.

    Creates a provider or edits everything about one but their availability, which
    lives in its own editor because it is a different shape of work. Plain HTML
    and utility classes, to sit inside the host application's admin shell. The
    image field offers a media-library picker when that package is installed and
    falls back to a plain URL when it is not.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-provider-editor space-y-6">
    <h2 class="text-lg font-semibold">
        {{ null === $providerId ? __( 'New provider' ) : __( 'Edit provider' ) }}
    </h2>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Name' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model="name" required />
                @error( 'name' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Slug' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model="slug" placeholder="{{ __( 'Derived from the name if left blank' ) }}" />
                @error( 'slug' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Email' ) }}</span>
                <input type="email" class="input input-bordered w-full" wire:model="email" />
                @error( 'email' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Phone' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model="phone" />
                @error( 'phone' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>
        </div>

        <label class="block space-y-1">
            <span class="font-medium">{{ __( 'Bio' ) }}</span>
            <textarea class="textarea textarea-bordered w-full" rows="3" wire:model="bio"></textarea>
            @error( 'bio' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
        </label>

        <div class="grid gap-4 md:grid-cols-3">
            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Timezone' ) }}</span>
                <select class="select select-bordered w-full" wire:model="timezone" required>
                    @foreach ( $timezones as $zone )
                        <option value="{{ $zone }}" wire:key="tz-{{ $zone }}">{{ $zone }}</option>
                    @endforeach
                </select>
                @error( 'timezone' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Round-robin weight' ) }}</span>
                <input type="number" min="1" class="input input-bordered w-full" wire:model="roundRobinWeight" required />
                @error( 'roundRobinWeight' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Sort order' ) }}</span>
                <input type="number" min="0" class="input input-bordered w-full" wire:model="sortOrder" required />
                @error( 'sortOrder' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="space-y-2">
            <span class="font-medium">{{ __( 'Image' ) }}</span>

            <div class="flex flex-wrap items-end gap-4">
                <label class="flex-1 space-y-1">
                    <span class="text-sm opacity-70">{{ __( 'Image URL' ) }}</span>
                    <input type="text" class="input input-bordered w-full" wire:model="imageUrl" placeholder="{{ __( 'https://…' ) }}" />
                    @error( 'imageUrl' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                </label>

                @if ( $mediaLibraryAvailable )
                    <button type="button" class="btn" wire:click="chooseImage">
                        {{ __( 'Choose from media library' ) }}
                    </button>
                @endif

                @if ( '' !== trim( $imageUrl ) || null !== $imageMediaId )
                    <button type="button" class="btn btn-ghost" wire:click="clearImage">
                        {{ __( 'Remove image' ) }}
                    </button>
                @endif
            </div>

            @if ( null !== $imageMediaId )
                <p class="text-xs opacity-60">{{ __( 'Media library image #:id', [ 'id' => $imageMediaId ] ) }}</p>
            @endif
            @error( 'imageMediaId' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
        </div>

        <label class="block space-y-1">
            <span class="font-medium">{{ __( 'Metadata (JSON)' ) }}</span>
            <textarea class="textarea textarea-bordered w-full font-mono text-sm" rows="4" wire:model="metadataJson" placeholder="{{ __( 'Optional. A JSON object of extra fields.' ) }}"></textarea>
            @error( 'metadataJson' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
        </label>

        <label class="flex items-center gap-2">
            <input type="checkbox" class="checkbox" wire:model="isActive" />
            <span class="font-medium">{{ __( 'Active' ) }}</span>
        </label>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary">
                <span wire:loading.remove wire:target="save">{{ __( 'Save provider' ) }}</span>
                <span wire:loading wire:target="save">{{ __( 'Saving…' ) }}</span>
            </button>

            <button type="button" class="btn" wire:click="cancel">{{ __( 'Cancel' ) }}</button>
        </div>
    </form>
</div>
