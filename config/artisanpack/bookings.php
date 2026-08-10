<?php

/**
 * Bookings package configuration.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

return [

    /*
    |--------------------------------------------------------------------------
    | Default Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone availability is authored in when a service or provider does
    | not declare one of its own. Availability windows are stored as wall-clock
    | time and resolved against this zone, so changing it after bookings exist
    | shifts the meaning of every unqualified schedule.
    |
    */
    'timezone' => env( 'BOOKING_DEFAULT_TIMEZONE', config( 'app.timezone' ) ),

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The application's user model, used to resolve the optional account behind
    | a service provider. Providers do not have to be users at all — the link is
    | nullable and carries no foreign key — so this is only consulted when a
    | provider actually has one.
    |
    */
    'user_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | Slot Interval
    |--------------------------------------------------------------------------
    |
    | The granularity, in minutes, at which bookable slots are generated inside
    | an availability window. A service duration that is not a multiple of this
    | interval still books correctly; the interval only controls where a slot is
    | allowed to start.
    |
    */
    'slot_interval' => env( 'BOOKING_SLOT_INTERVAL', 15 ),

    /*
    |--------------------------------------------------------------------------
    | Availability Cache
    |--------------------------------------------------------------------------
    |
    | Computed availability is cached per service, provider, and date range.
    | The cache is invalidated on every write that can change availability, so
    | the TTL is a backstop rather than the primary correctness mechanism.
    |
    */
    'availability_cache' => [
        'enabled'     => true,
        'ttl_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Slot Lock
    |--------------------------------------------------------------------------
    |
    | Reading availability and writing the booking that takes it happen behind
    | an advisory lock on a single (provider, start time) pair, so two customers
    | racing for the last slot are decided before either reaches the database.
    |
    | "wait_seconds" bounds how long a request queues for that lock; a waiter
    | that outlives the customer's patience has already failed. Postgres and
    | MySQL use their own advisory locks and ignore "store". Every other engine
    | — sqlite, chiefly — has none, so the cache store stands in; leave "store"
    | null to use the default cache store, and point it at a shared one if you
    | run more than one application server on such an engine.
    |
    */
    'lock' => [
        'wait_seconds' => 5,
        'store'        => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Confirmation
    |--------------------------------------------------------------------------
    |
    | Whether a new booking confirms itself. A "requested" booking already holds
    | its slot, so switching this off does not risk losing the appointment while
    | somebody approves it — it only delays the confirmation email, the calendar
    | push, and anything else hanging off the BookingConfirmed event.
    |
    */
    'auto_confirm' => true,

    /*
    |--------------------------------------------------------------------------
    | Booking Window
    |--------------------------------------------------------------------------
    |
    | How far ahead of a slot a customer must book, and how far into the future
    | they may book at all. Both are measured in minutes from the moment of the
    | request. The default maximum is 90 days.
    |
    */
    'booking_window' => [
        'min_advance_minutes' => 60,
        'max_advance_minutes' => 60 * 24 * 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cancellation
    |--------------------------------------------------------------------------
    |
    | Whether customers may cancel their own bookings, and how long before the
    | start time the self-serve cancellation link stops working. Staff-side
    | cancellation is governed by the admin gate, not by this setting.
    |
    */
    'cancellation' => [
        'allowed'             => true,
        'min_advance_minutes' => 60 * 24,
        'refund_policy_url'   => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recurring Series
    |--------------------------------------------------------------------------
    |
    | Bounds on recurring bookings. The maximum occurrence count is a hard cap
    | applied when a recurrence rule is expanded, which keeps an unbounded RRULE
    | from generating an unbounded number of rows.
    |
    */
    'series' => [
        'max_occurrences' => 52,
        'default_rules'   => [ 'weekly', 'biweekly', 'monthly' ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Channels used for booking notifications and which lifecycle messages are
    | enabled. Reminders are sent the listed number of hours before the booking
    | start; add more entries to send more than one reminder.
    |
    */
    'notifications' => [
        'channels'     => [ 'mail', 'database', 'webhook' ],
        'confirmation' => [ 'enabled' => true ],
        'reminder'     => [ 'enabled' => true, 'hours_before' => [ 24 ] ],
        'cancellation' => [ 'enabled' => true ],
        'sms_driver'   => env( 'BOOKING_SMS_DRIVER', 'null' ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Calendar Sync
    |--------------------------------------------------------------------------
    |
    | Outbound sync pushes bookings to a connected calendar. Two-way sync also
    | reads busy blocks back and is opt-in per connection, because it grants the
    | external calendar the power to suppress availability.
    |
    | A connection that fails "connection_failure_threshold" times in a row is
    | marked unhealthy and stops being retried until an operator reconnects it.
    | Each driver is additionally gated on its own package being installed.
    |
    */
    'calendar' => [
        'default_sync_mode'            => 'outbound',
        'two_way_grace_hours'          => 6,
        'two_way_lookahead_days'       => 60,
        'connection_failure_threshold' => 5,
        'ical_feed'                    => [ 'enabled' => true, 'signing_ttl_days' => 365 ],
        'drivers'                      => [
            'google'    => [ 'enabled' => env( 'BOOKING_GOOGLE_ENABLED', false ) ],
            'microsoft' => [ 'enabled' => env( 'BOOKING_MICROSOFT_ENABLED', false ) ],
            'apple'     => [ 'enabled' => env( 'BOOKING_APPLE_ENABLED', false ) ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Outbound webhook delivery. Failed deliveries are retried on the listed
    | backoff schedule, in minutes; an endpoint that fails "failure_threshold"
    | times in a row is disabled rather than retried forever.
    |
    */
    'webhooks' => [
        'failure_threshold'        => 10,
        'delivery_backoff_minutes' => [ 1, 5, 30, 120, 720 ],
        'delivery_retention_days'  => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Surface
    |--------------------------------------------------------------------------
    |
    | The route prefix and authorization gate for the staff-facing screens. The
    | CMS navigation entry is registered only when artisanpack-ui/cms-framework
    | is installed and "auto_register_cms_nav" is true.
    |
    */
    'admin' => [
        'route_prefix'          => 'bookings-admin',
        'gate'                  => 'bookings.manage',
        'auto_register_cms_nav' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Surface
    |--------------------------------------------------------------------------
    |
    | The route prefix for the customer-facing booking widget and the rate
    | limits applied to it. The "post" and "manage_get" limits are per IP per
    | minute, "manage_token" is per manage token, and "ical" is per IP.
    |
    */
    'public' => [
        'route_prefix' => 'bookings',
        'rate_limits'  => [
            'post'         => 5,
            'manage_get'   => 20,
            'manage_token' => 60,
            'ical'         => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long booking data is kept before the prune command removes it. The
    | default of three years is a starting point, not legal advice — set it to
    | whatever the retention policy you actually operate under requires.
    |
    */
    'retention' => [
        'prune_after_days'         => env( 'BOOKING_PRUNE_DAYS', 365 * 3 ),
        'notification_log_days'    => 90,
        'calendar_events_ttl_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | Site scoping is configured in "artisanpack.core.multi_tenant", not here.
    | Every owned table carries a nullable site_id, and queries are scoped to
    | whatever artisanpack-ui/core's SiteContext reports — so set
    | "artisanpack.core.multi_tenant.enabled" (or ARTISANPACK_MULTI_TENANT_ENABLED)
    | to switch scoping on, and "artisanpack.core.multi_tenant.resolvers" to say
    | how the site is resolved. One setting, one answer: an application cannot
    | be site 2 for analytics while being site 1 for bookings.
    |
    | While scoping is disabled the site scope is inert and every row is
    | visible, so a single-tenant application never has to configure anything.
    |
    | Turning it on later is not free. Rows written while it was off carry a
    | null site_id, and the scope matches on equality — so the moment a site
    | resolves, every one of those rows drops out of every site-scoped query at
    | once — only acrossAllSites() still sees them.
    | Backfill site_id before enabling this on an installation that already has
    | bookings in it.
    |
    */

];
