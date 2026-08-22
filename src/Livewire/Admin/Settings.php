<?php

/**
 * Admin settings surface.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Livewire\Admin;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The screen an administrator reads the package's effective configuration from.
 *
 * The other four admin surfaces make asynchronous work recoverable; this one
 * explains the rules that work runs under — how far ahead a booking may be made,
 * which channels a notification goes out on, when a webhook is given up on, how
 * long each log is kept. An operator looking at a disabled connection or a failed
 * reminder comes here to see the thresholds that produced it.
 *
 * It is deliberately read-only. The package is configured from
 * `config/artisanpack/bookings.php` and the environment, not from a settings
 * table — a value like the user model or the lock store is read while the
 * framework boots, long before any Livewire request, so a value written here
 * would not take effect until the next deploy and would disagree with the file
 * that is still the source of truth in between. Showing the effective values and
 * naming where they are set is honest; offering to edit them from here would not
 * be. An installation that wants operator-editable settings binds its own store
 * and mounts its own form; this surface reports what is actually in force.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class Settings extends Component
{
    /**
     * Reads the package's effective configuration into display groups.
     *
     * Each group is a heading and a list of label/value pairs, resolved from
     * config at render time so the screen always shows what is actually in
     * force rather than a copy that could drift from it. Values are stringified
     * for display here so the view stays free of formatting logic.
     *
     * @since 1.0.0
     *
     * @return array<int, array{title: string, rows: array<int, array{label: string, value: string}>}>
     *                                                                                                 The configuration groups to render.
     */
    public function groups(): array
    {
        return [
            [
                'title' => __( 'General' ),
                'rows'  => [
                    [ 'label' => __( 'Default timezone' ), 'value' => $this->text( config( 'artisanpack.bookings.timezone' ) ) ],
                    [ 'label' => __( 'Slot interval (minutes)' ), 'value' => $this->text( config( 'artisanpack.bookings.slot_interval' ) ) ],
                    [ 'label' => __( 'Confirm bookings automatically' ), 'value' => $this->bool( config( 'artisanpack.bookings.auto_confirm' ) ) ],
                ],
            ],
            [
                'title' => __( 'Booking window' ),
                'rows'  => [
                    [ 'label' => __( 'Minimum advance (minutes)' ), 'value' => $this->text( config( 'artisanpack.bookings.booking_window.min_advance_minutes' ) ) ],
                    [ 'label' => __( 'Maximum advance (minutes)' ), 'value' => $this->text( config( 'artisanpack.bookings.booking_window.max_advance_minutes' ) ) ],
                    [ 'label' => __( 'Customers may self-cancel' ), 'value' => $this->bool( config( 'artisanpack.bookings.cancellation.allowed' ) ) ],
                ],
            ],
            [
                'title' => __( 'Notifications' ),
                'rows'  => [
                    [ 'label' => __( 'Channels' ), 'value' => $this->list( config( 'artisanpack.bookings.notifications.channels' ) ) ],
                    [ 'label' => __( 'Reminders enabled' ), 'value' => $this->bool( config( 'artisanpack.bookings.notifications.reminder.enabled' ) ) ],
                    [ 'label' => __( 'Reminder hours before' ), 'value' => $this->list( config( 'artisanpack.bookings.notifications.reminder.hours_before' ) ) ],
                    [ 'label' => __( 'SMS driver' ), 'value' => $this->text( config( 'artisanpack.bookings.notifications.sms_driver' ) ) ],
                ],
            ],
            [
                'title' => __( 'Calendar sync' ),
                'rows'  => [
                    [ 'label' => __( 'Default sync mode' ), 'value' => $this->text( config( 'artisanpack.bookings.calendar.default_sync_mode' ) ) ],
                    [ 'label' => __( 'Failure threshold' ), 'value' => $this->text( config( 'artisanpack.bookings.calendar.connection_failure_threshold' ) ) ],
                    [ 'label' => __( 'Google driver enabled' ), 'value' => $this->bool( config( 'artisanpack.bookings.calendar.drivers.google.enabled' ) ) ],
                    [ 'label' => __( 'Microsoft driver enabled' ), 'value' => $this->bool( config( 'artisanpack.bookings.calendar.drivers.microsoft.enabled' ) ) ],
                    [ 'label' => __( 'Apple driver enabled' ), 'value' => $this->bool( config( 'artisanpack.bookings.calendar.drivers.apple.enabled' ) ) ],
                ],
            ],
            [
                'title' => __( 'Webhooks' ),
                'rows'  => [
                    [ 'label' => __( 'Failure threshold' ), 'value' => $this->text( config( 'artisanpack.bookings.webhooks.failure_threshold' ) ) ],
                    [ 'label' => __( 'Retry backoff (minutes)' ), 'value' => $this->list( config( 'artisanpack.bookings.webhooks.delivery_backoff_minutes' ) ) ],
                    [ 'label' => __( 'Delivery retention (days)' ), 'value' => $this->text( config( 'artisanpack.bookings.webhooks.delivery_retention_days' ) ) ],
                    [ 'label' => __( 'Delivery queue' ), 'value' => $this->text( config( 'artisanpack.bookings.webhooks.queue' ) ) ],
                ],
            ],
            [
                'title' => __( 'Retention' ),
                'rows'  => [
                    [ 'label' => __( 'Notification log (days)' ), 'value' => $this->text( config( 'artisanpack.bookings.retention.notification_log_days' ) ) ],
                    [ 'label' => __( 'Webhook deliveries (days)' ), 'value' => $this->text( config( 'artisanpack.bookings.retention.webhook_delivery_days' ) ?? config( 'artisanpack.bookings.webhooks.delivery_retention_days' ) ) ],
                    [ 'label' => __( 'Calendar events (days)' ), 'value' => $this->text( config( 'artisanpack.bookings.retention.calendar_events_ttl_days' ) ) ],
                ],
            ],
            [
                'title' => __( 'Surfaces' ),
                'rows'  => [
                    [ 'label' => __( 'Admin route prefix' ), 'value' => $this->text( config( 'artisanpack.bookings.admin.route_prefix' ) ) ],
                    [ 'label' => __( 'Admin gate' ), 'value' => $this->text( config( 'artisanpack.bookings.admin.gate' ) ) ],
                    [ 'label' => __( 'Public route prefix' ), 'value' => $this->text( config( 'artisanpack.bookings.public.route_prefix' ) ) ],
                    [ 'label' => __( 'Manage URL' ), 'value' => $this->text( config( 'artisanpack.bookings.public.manage_url' ) ) ],
                ],
            ],
        ];
    }

    /**
     * Renders the settings surface.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'bookings::livewire.admin.settings', [
            'groups' => $this->groups(),
        ] );
    }

    /**
     * Renders one scalar config value for display.
     *
     * A value that is not set reads as an em dash rather than a blank cell, so
     * "unset" is legible instead of looking like a rendering fault.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The config value to render.
     *
     * @return string The value as display text.
     */
    protected function text( mixed $value ): string
    {
        if ( null === $value || '' === $value ) {
            return '—';
        }

        if ( is_bool( $value ) ) {
            return $this->bool( $value );
        }

        return (string) $value;
    }

    /**
     * Renders a boolean config value as a word rather than a bare 0 or 1.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The config value to render.
     *
     * @return string The value as "Yes" or "No".
     */
    protected function bool( mixed $value ): string
    {
        return $value ? __( 'Yes' ) : __( 'No' );
    }

    /**
     * Renders a list config value as a comma-separated string.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The config value to render.
     *
     * @return string The list joined for display, or an em dash when empty.
     */
    protected function list( mixed $value ): string
    {
        if ( ! is_array( $value ) || [] === $value ) {
            return '—';
        }

        return implode( ', ', array_map( static fn ( mixed $item ): string => (string) $item, $value ) );
    }
}
