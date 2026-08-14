{{--
    The admin recurring-series editor.

    The three-radio scope picker every calendar application offers — this
    occurrence, this and following, or the whole series — with the form changing
    shape to match: the rule-touching scopes edit the series' provider, customer,
    rule, and bounds; the single-occurrence scope edits one appointment and
    detaches it. The occurrence picker appears for the two scopes that need a
    point to work from. The markup is plain HTML and utility classes on purpose,
    so the editor fits whatever admin shell the host application mounts it in.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-series-editor space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold">{{ __( 'Edit series' ) }}</h2>
            <p class="text-sm opacity-60">{{ $series->customer_name }} — {{ $series->service?->name }}</p>
        </div>
    </div>

    @error( 'scope' )
        <p class="rounded-box border border-error/50 bg-error/10 p-3 text-sm text-error" role="alert">
            {{ $message }}
        </p>
    @enderror

    <fieldset class="space-y-2">
        <legend class="text-sm font-medium">{{ __( 'How much of the series should this change?' ) }}</legend>

        <label class="flex items-center gap-2">
            <input type="radio" class="radio" wire:model.live="scope" value="this" />
            <span>{{ __( 'This occurrence only' ) }}</span>
        </label>

        <label class="flex items-center gap-2">
            <input type="radio" class="radio" wire:model.live="scope" value="this_and_following" />
            <span>{{ __( 'This and following occurrences' ) }}</span>
        </label>

        <label class="flex items-center gap-2">
            <input type="radio" class="radio" wire:model.live="scope" value="all" />
            <span>{{ __( 'All occurrences' ) }}</span>
        </label>
    </fieldset>

    @if ( 'all' !== $scope )
        <label class="block space-y-1">
            <span class="text-sm font-medium">{{ __( 'Starting from occurrence' ) }}</span>
            <select class="select select-bordered w-full" wire:model.live="occurrenceId">
                <option value="">{{ __( 'Choose an occurrence…' ) }}</option>
                @foreach ( $occurrences as $occurrence )
                    <option value="{{ $occurrence->id }}" wire:key="occurrence-{{ $occurrence->id }}">
                        #{{ $occurrence->series_index }} — {{ $occurrence->start_time->format( 'M j, Y g:i A' ) }}
                        @if ( $occurrence->isDetachedFromSeries() ){{ __( ' (detached)' ) }}@endif
                    </option>
                @endforeach
            </select>
            @error( 'occurrenceId' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
        </label>
    @endif

    @if ( 'this' === $scope )
        <div class="space-y-4">
            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __( 'New time' ) }}</span>
                <input type="datetime-local" class="input input-bordered w-full" wire:model="occurrenceStart" />
                <span class="block text-xs opacity-60">{{ __( 'Read in the series timezone:' ) }} {{ $series->dtstart_timezone }}</span>
                @error( 'occurrenceStart' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __( 'Customer name' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model="occurrenceCustomerName" />
                @error( 'occurrenceCustomerName' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __( 'Customer email' ) }}</span>
                <input type="email" class="input input-bordered w-full" wire:model="occurrenceCustomerEmail" />
                @error( 'occurrenceCustomerEmail' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __( 'Customer phone' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model="occurrenceCustomerPhone" />
                @error( 'occurrenceCustomerPhone' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __( 'Notes' ) }}</span>
                <textarea class="textarea textarea-bordered w-full" wire:model="occurrenceNotes"></textarea>
                @error( 'occurrenceNotes' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>
        </div>
    @else
        <div class="space-y-4">
            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __( 'Provider' ) }}</span>
                <select class="select select-bordered w-full" wire:model="providerId">
                    <option value="">{{ __( 'Unassigned' ) }}</option>
                    @foreach ( $providers as $provider )
                        <option value="{{ $provider->id }}" wire:key="provider-{{ $provider->id }}">{{ $provider->name }}</option>
                    @endforeach
                </select>
                @error( 'providerId' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __( 'Customer name' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model="customerName" />
                @error( 'customerName' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __( 'Customer email' ) }}</span>
                <input type="email" class="input input-bordered w-full" wire:model="customerEmail" />
                @error( 'customerEmail' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __( 'Customer phone' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model="customerPhone" />
                @error( 'customerPhone' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __( 'Recurrence rule' ) }}</span>
                <input type="text" class="input input-bordered w-full font-mono" wire:model="rrule" />
                @error( 'rrule' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block space-y-1">
                    <span class="text-sm font-medium">{{ __( 'Starts' ) }}</span>
                    <input type="datetime-local" class="input input-bordered w-full" wire:model="dtstartLocal" />
                    @error( 'dtstartLocal' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                </label>

                <label class="block space-y-1">
                    <span class="text-sm font-medium">{{ __( 'Ends (optional)' ) }}</span>
                    <input type="datetime-local" class="input input-bordered w-full" wire:model="untilLocal" />
                    @error( 'untilLocal' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                </label>
            </div>

            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __( 'Timezone' ) }}</span>
                <input type="text" class="input input-bordered w-full" wire:model="dtstartTimezone" />
                @error( 'dtstartTimezone' ) <span class="text-sm text-error">{{ $message }}</span> @enderror
            </label>
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4 border-t pt-4">
        <button
            type="button"
            class="btn btn-error btn-outline"
            wire:click="cancelSeries"
            wire:confirm="{{ __( 'Cancel this series and everything still to come?' ) }}"
        >
            {{ __( 'Cancel whole series' ) }}
        </button>

        <div class="flex gap-2">
            <button type="button" class="btn" wire:click="cancel">{{ __( 'Close' ) }}</button>
            <button type="button" class="btn btn-primary" wire:click="save">{{ __( 'Save changes' ) }}</button>
        </div>
    </div>
</div>
