{{--
    The admin settings surface.

    A read-only report of the package's effective configuration, grouped the way
    an operator reads it — the thresholds and windows behind the connections,
    webhooks, and notifications the other admin screens act on. It is not editable
    here: the values come from config and the environment, which are read as the
    framework boots, so this screen shows what is in force and names where it is
    set. The markup is plain HTML and utility classes on purpose.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
<div class="artisanpack-bookings-settings space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-semibold">{{ __( 'Settings' ) }}</h2>
    </div>

    <p class="rounded-box border border-dashed p-4 text-sm opacity-70">
        {{ __( 'These values are read from the package configuration and environment. Edit them in config/artisanpack/bookings.php or the matching environment variables; changes take effect on the next deploy.' ) }}
    </p>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ( $groups as $group )
            <section class="rounded-box border p-4" wire:key="settings-group-{{ $loop->index }}">
                <h3 class="mb-3 text-base font-semibold">{{ $group['title'] }}</h3>

                <dl class="space-y-2">
                    @foreach ( $group['rows'] as $row )
                        <div class="flex items-start justify-between gap-4" wire:key="settings-group-{{ $loop->parent->index }}-row-{{ $loop->index }}">
                            <dt class="text-sm opacity-70">{{ $row['label'] }}</dt>
                            <dd class="text-right text-sm font-medium">{{ $row['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
</div>
