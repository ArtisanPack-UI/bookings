{{--
    The admin availability editor.

    A provider's recurring weekly hours and their single-date exceptions, edited
    together because they answer the same question — when can this provider be
    booked. Times are local wall-clock in the provider's own timezone, shown at
    the top so the administrator reads them the way a customer would. Plain HTML
    and utility classes, to sit inside the host application's admin shell.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-availability-editor space-y-8">
    <div class="space-y-1">
        <h2 class="text-lg font-semibold">
            {{ __( 'Availability — :name', [ 'name' => $providerName ] ) }}
        </h2>
        <p class="text-sm opacity-70">
            {{ __( 'Times are local to :timezone.', [ 'timezone' => $timezone ] ) }}
        </p>
    </div>

    <form wire:submit="save" class="space-y-8">
        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <h3 class="font-semibold">{{ __( 'Weekly hours' ) }}</h3>

                <button type="button" class="btn btn-sm" wire:click="addSchedule">
                    {{ __( 'Add window' ) }}
                </button>
            </div>

            @if ( [] === $schedules )
                <p class="rounded-box border border-dashed p-4 text-center text-sm opacity-70">
                    {{ __( 'No weekly hours yet. Add a window to make this provider bookable.' ) }}
                </p>
            @else
                <div class="space-y-4">
                    @foreach ( $schedules as $index => $schedule )
                        <div class="rounded-box border p-4" wire:key="schedule-{{ $index }}">
                            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-6">
                                <label class="space-y-1">
                                    <span class="text-sm font-medium">{{ __( 'Day' ) }}</span>
                                    <select class="select select-bordered w-full" wire:model="schedules.{{ $index }}.day_of_week">
                                        @foreach ( $weekdays as $value => $label )
                                            <option value="{{ $value }}" wire:key="schedule-{{ $index }}-day-{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error( "schedules.{$index}.day_of_week" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                                </label>

                                <label class="space-y-1">
                                    <span class="text-sm font-medium">{{ __( 'Start' ) }}</span>
                                    <input type="time" class="input input-bordered w-full" wire:model="schedules.{{ $index }}.start_time_local" />
                                    @error( "schedules.{$index}.start_time_local" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                                </label>

                                <label class="space-y-1">
                                    <span class="text-sm font-medium">{{ __( 'End' ) }}</span>
                                    <input type="time" class="input input-bordered w-full" wire:model="schedules.{{ $index }}.end_time_local" />
                                    @error( "schedules.{$index}.end_time_local" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                                </label>

                                <label class="space-y-1">
                                    <span class="text-sm font-medium">{{ __( 'From (optional)' ) }}</span>
                                    <input type="date" class="input input-bordered w-full" wire:model="schedules.{{ $index }}.effective_from" />
                                    @error( "schedules.{$index}.effective_from" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                                </label>

                                <label class="space-y-1">
                                    <span class="text-sm font-medium">{{ __( 'Until (optional)' ) }}</span>
                                    <input type="date" class="input input-bordered w-full" wire:model="schedules.{{ $index }}.effective_until" />
                                    @error( "schedules.{$index}.effective_until" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                                </label>

                                <div class="flex items-end justify-between gap-2">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" class="checkbox" wire:model="schedules.{{ $index }}.is_available" />
                                        <span class="text-sm font-medium">{{ __( 'Available' ) }}</span>
                                    </label>

                                    <button type="button" class="btn btn-sm btn-error" wire:click="removeSchedule( {{ $index }} )">
                                        {{ __( 'Remove' ) }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <h3 class="font-semibold">{{ __( 'Date exceptions' ) }}</h3>

                <button type="button" class="btn btn-sm" wire:click="addOverride">
                    {{ __( 'Add exception' ) }}
                </button>
            </div>

            @if ( [] === $overrides )
                <p class="rounded-box border border-dashed p-4 text-center text-sm opacity-70">
                    {{ __( 'No exceptions. The weekly hours apply on every date.' ) }}
                </p>
            @else
                <div class="space-y-4">
                    @foreach ( $overrides as $index => $override )
                        <div class="rounded-box border p-4" wire:key="override-{{ $index }}">
                            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                                <label class="space-y-1">
                                    <span class="text-sm font-medium">{{ __( 'Date' ) }}</span>
                                    <input type="date" class="input input-bordered w-full" wire:model="overrides.{{ $index }}.date" />
                                    @error( "overrides.{$index}.date" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                                </label>

                                <label class="space-y-1">
                                    <span class="text-sm font-medium">{{ __( 'Type' ) }}</span>
                                    <select class="select select-bordered w-full" wire:model.live="overrides.{{ $index }}.type">
                                        @foreach ( $overrideTypes as $value => $label )
                                            <option value="{{ $value }}" wire:key="override-{{ $index }}-type-{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error( "overrides.{$index}.type" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                                </label>

                                @if ( $customHoursType === $override['type'] )
                                    <label class="space-y-1">
                                        <span class="text-sm font-medium">{{ __( 'Start' ) }}</span>
                                        <input type="time" class="input input-bordered w-full" wire:model="overrides.{{ $index }}.start_time_local" />
                                        @error( "overrides.{$index}.start_time_local" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                                    </label>

                                    <label class="space-y-1">
                                        <span class="text-sm font-medium">{{ __( 'End' ) }}</span>
                                        <input type="time" class="input input-bordered w-full" wire:model="overrides.{{ $index }}.end_time_local" />
                                        @error( "overrides.{$index}.end_time_local" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                                    </label>
                                @else
                                    <div class="hidden lg:block"></div>
                                    <div class="hidden lg:block"></div>
                                @endif

                                <div class="flex items-end justify-end">
                                    <button type="button" class="btn btn-sm btn-error" wire:click="removeOverride( {{ $index }} )">
                                        {{ __( 'Remove' ) }}
                                    </button>
                                </div>

                                <label class="space-y-1 md:col-span-2 lg:col-span-5">
                                    <span class="text-sm font-medium">{{ __( 'Reason (optional)' ) }}</span>
                                    <input type="text" class="input input-bordered w-full" wire:model="overrides.{{ $index }}.reason" placeholder="{{ __( 'Holiday, leave, appointment…' ) }}" />
                                    @error( "overrides.{$index}.reason" ) <span class="text-sm text-error">{{ $message }}</span> @enderror
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary">
                <span wire:loading.remove wire:target="save">{{ __( 'Save availability' ) }}</span>
                <span wire:loading wire:target="save">{{ __( 'Saving…' ) }}</span>
            </button>

            <button type="button" class="btn" wire:click="cancel">{{ __( 'Cancel' ) }}</button>
        </div>
    </form>
</div>
