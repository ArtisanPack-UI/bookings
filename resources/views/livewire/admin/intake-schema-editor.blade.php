{{--
    The admin intake schema editor.

    Edits a service's intake form by appending versions, never overwriting one, so
    that a booking taken against an old form still renders against the questions it
    was actually asked. The preview panel shows the form after the
    `ap.bookings.intakeSchema` filter has run — what a plugin will really validate
    against — while a save writes the unfiltered editor state.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-intake-schema-editor space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">{{ __( 'Intake form' ) }}</h2>

        <button type="button" class="btn" wire:click="addField">{{ __( 'Add field' ) }}</button>
    </div>

    <form wire:submit="save" class="space-y-4">
        @if ( [] === $fields )
            <p class="rounded-box border border-dashed p-6 text-center opacity-70">
                {{ __( 'This form asks nothing yet. Add a field to begin.' ) }}
            </p>
        @endif

        @foreach ( $fields as $index => $field )
            <fieldset class="rounded-box border p-4 space-y-3" wire:key="field-{{ $field['_key'] ?? 'pos-' . $index }}">
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="space-y-1">
                        <span class="text-sm font-medium">{{ __( 'Name' ) }}</span>
                        <input type="text" class="input input-bordered w-full" wire:model="fields.{{ $index }}.name" />
                        @error( "fields.$index.name" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                    </label>

                    <label class="space-y-1">
                        <span class="text-sm font-medium">{{ __( 'Type' ) }}</span>
                        <select class="select select-bordered w-full" wire:model="fields.{{ $index }}.type">
                            @foreach ( $this->fieldTypes() as $type )
                                <option value="{{ $type }}" wire:key="field-{{ $index }}-type-{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                        @error( "fields.$index.type" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                    </label>
                </div>

                <label class="block space-y-1">
                    <span class="text-sm font-medium">{{ __( 'Label' ) }}</span>
                    <input type="text" class="input input-bordered w-full" wire:model="fields.{{ $index }}.label" />
                    @error( "fields.$index.label" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                </label>

                <label class="block space-y-1">
                    <span class="text-sm font-medium">{{ __( 'Options (one per line)' ) }}</span>
                    <textarea class="textarea textarea-bordered w-full" rows="3" wire:model="fields.{{ $index }}.options"></textarea>
                    @error( "fields.$index.options" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                </label>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" class="checkbox" wire:model="fields.{{ $index }}.required" />
                        <span class="text-sm font-medium">{{ __( 'Required' ) }}</span>
                    </label>

                    <div class="flex gap-2">
                        <button type="button" class="btn btn-sm" wire:click="moveUp( {{ $index }} )" @disabled( 0 === $index )>{{ __( 'Up' ) }}</button>
                        <button type="button" class="btn btn-sm" wire:click="moveDown( {{ $index }} )" @disabled( $loop->last )>{{ __( 'Down' ) }}</button>
                        <button type="button" class="btn btn-sm btn-error" wire:click="removeField( {{ $index }} )">{{ __( 'Remove' ) }}</button>
                    </div>
                </div>
            </fieldset>
        @endforeach

        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary">
                <span wire:loading.remove wire:target="save">{{ __( 'Save as new version' ) }}</span>
                <span wire:loading wire:target="save">{{ __( 'Saving…' ) }}</span>
            </button>
        </div>
    </form>

    <section class="space-y-3" aria-label="{{ __( 'Form preview' ) }}">
        <h3 class="font-semibold">{{ __( 'What will be validated' ) }}</h3>
        <p class="text-sm opacity-70">{{ __( 'The form after extensions have added their fields. Injected fields are shown here but are not saved into your version history.' ) }}</p>

        @if ( [] === $filteredFields )
            <p class="opacity-70">{{ __( 'Nothing is asked.' ) }}</p>
        @else
            <ul class="space-y-1">
                @foreach ( $filteredFields as $field )
                    <li wire:key="preview-{{ $loop->index }}" class="flex items-center gap-2">
                        <span class="font-medium">{{ $field['label'] }}</span>
                        <span class="badge badge-ghost">{{ $field['type'] }}</span>
                        @if ( $field['required'] )
                            <span class="badge badge-warning">{{ __( 'Required' ) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="space-y-3" aria-label="{{ __( 'Version history' ) }}">
        <h3 class="font-semibold">{{ __( 'Version history' ) }}</h3>

        @if ( $versions->isEmpty() )
            <p class="opacity-70">{{ __( 'No versions recorded yet. The first save records version 1.' ) }}</p>
        @else
            <ul class="space-y-1">
                @foreach ( $versions as $version )
                    <li wire:key="version-{{ $version->version }}" class="flex items-center gap-3">
                        <span class="font-medium">{{ __( 'Version :number', [ 'number' => $version->version ] ) }}</span>
                        <span class="text-sm opacity-60">{{ $version->created_at?->translatedFormat( 'j M Y, g:i a' ) }}</span>
                        <span class="text-sm opacity-60">{{ trans_choice( ':count field|:count fields', count( $version->schema['fields'] ?? [] ), [ 'count' => count( $version->schema['fields'] ?? [] ) ] ) }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="grid gap-3 md:grid-cols-2">
                <label class="space-y-1">
                    <span class="text-sm font-medium">{{ __( 'Compare from' ) }}</span>
                    <select class="select select-bordered w-full" wire:model.live="diffLeft">
                        @foreach ( $versions as $version )
                            <option value="{{ $version->version }}" wire:key="left-{{ $version->version }}">{{ __( 'Version :number', [ 'number' => $version->version ] ) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium">{{ __( 'Compare to' ) }}</span>
                    <select class="select select-bordered w-full" wire:model.live="diffRight">
                        @foreach ( $versions as $version )
                            <option value="{{ $version->version }}" wire:key="right-{{ $version->version }}">{{ __( 'Version :number', [ 'number' => $version->version ] ) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="rounded-box border p-4 space-y-2" aria-live="polite">
                @if ( [] === $differences['added'] && [] === $differences['removed'] && [] === $differences['changed'] )
                    <p class="opacity-70">{{ __( 'These versions ask the same questions.' ) }}</p>
                @else
                    @if ( [] !== $differences['added'] )
                        <p><span class="badge badge-success">{{ __( 'Added' ) }}</span> {{ implode( ', ', $differences['added'] ) }}</p>
                    @endif
                    @if ( [] !== $differences['removed'] )
                        <p><span class="badge badge-error">{{ __( 'Removed' ) }}</span> {{ implode( ', ', $differences['removed'] ) }}</p>
                    @endif
                    @if ( [] !== $differences['changed'] )
                        <p><span class="badge badge-warning">{{ __( 'Changed' ) }}</span> {{ implode( ', ', $differences['changed'] ) }}</p>
                    @endif
                @endif
            </div>
        @endif
    </section>
</div>
