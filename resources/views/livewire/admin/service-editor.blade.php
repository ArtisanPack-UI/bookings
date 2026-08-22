{{--
    The admin service editor.

    Creates a service or edits everything about one but its intake form, which
    lives in its own editor because it is versioned rather than overwritten. Plain
    HTML and utility classes, to sit inside the host application's admin shell.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-service-editor space-y-6">
    <h2 class="text-lg font-semibold">
        {{ null === $serviceId ? __( 'New service' ) : __( 'Edit service' ) }}
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

        <label class="block space-y-1">
            <span class="font-medium">{{ __( 'Description' ) }}</span>
            <textarea class="textarea textarea-bordered w-full" rows="3" wire:model="description"></textarea>
            @error( 'description' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
        </label>

        <div class="grid gap-4 md:grid-cols-3">
            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Duration (minutes)' ) }}</span>
                <input type="number" min="1" class="input input-bordered w-full" wire:model="duration" required />
                @error( 'duration' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Buffer before (minutes)' ) }}</span>
                <input type="number" min="0" class="input input-bordered w-full" wire:model="bufferBefore" />
                @error( 'bufferBefore' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Buffer after (minutes)' ) }}</span>
                <input type="number" min="0" class="input input-bordered w-full" wire:model="bufferAfter" />
                @error( 'bufferAfter' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <label class="flex items-center gap-2">
                <input type="checkbox" class="checkbox" wire:model.live="isFree" />
                <span class="font-medium">{{ __( 'Free service' ) }}</span>
            </label>

            <label class="space-y-1 {{ $isFree ? 'opacity-50' : '' }}">
                <span class="font-medium">{{ __( 'Price' ) }}</span>
                <input type="text" inputmode="decimal" class="input input-bordered w-full" wire:model="price" @disabled( $isFree ) />
                @error( 'price' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Max bookings per slot' ) }}</span>
                <input type="number" min="1" class="input input-bordered w-full" wire:model="maxBookingsPerSlot" required />
                @error( 'maxBookingsPerSlot' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Provider assignment' ) }}</span>
                <select class="select select-bordered w-full" wire:model="assignmentStrategy">
                    @foreach ( $strategies as $value => $label )
                        <option value="{{ $value }}" wire:key="strategy-{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error( 'assignmentStrategy' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Default provider' ) }}</span>
                <select class="select select-bordered w-full" wire:model="defaultProviderId">
                    <option value="">{{ __( 'None' ) }}</option>
                    @foreach ( $providers as $provider )
                        <option value="{{ $provider->id }}" wire:key="provider-{{ $provider->id }}">{{ $provider->name }}</option>
                    @endforeach
                </select>
                @error( 'defaultProviderId' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Colour' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model="color" placeholder="#2563eb" />
                @error( 'color' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="space-y-1">
                <span class="font-medium">{{ __( 'Timezone' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model="timezone" placeholder="{{ __( 'Inherits the app timezone if blank' ) }}" />
                @error( 'timezone' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="flex items-center gap-2">
                <input type="checkbox" class="checkbox" wire:model="isActive" />
                <span class="font-medium">{{ __( 'Active' ) }}</span>
            </label>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary">
                <span wire:loading.remove wire:target="save">{{ __( 'Save service' ) }}</span>
                <span wire:loading wire:target="save">{{ __( 'Saving…' ) }}</span>
            </button>

            <button type="button" class="btn" wire:click="cancel">{{ __( 'Cancel' ) }}</button>
        </div>
    </form>
</div>
