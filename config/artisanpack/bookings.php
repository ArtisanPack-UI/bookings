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
        'reminder'     => [
            'enabled'      => true,
            'hours_before' => [ 24 ],

            /*
            | How far ahead "bookings:send-reminders" looks for bookings that
            | might have a reminder due. Defaults to the longest window in
            | "hours_before". Raise it when an "ap.bookings.reminderScheduling"
            | subscriber adds a window longer than anything configured here: the
            | filter takes a booking, so it cannot be consulted before there is
            | one to look for, and a reminder outside this range never comes due.
            */
            'max_lookahead_hours' => 0,
        ],
        'cancellation' => [ 'enabled' => true ],

        /*
        | Who receives the staff-facing copy on the "database" channel, and how
        | it is delivered. Two implementations answer to this channel and the
        | package picks between them automatically:
        |
        | - With artisanpack-ui/cms-framework installed, the notice goes to its
        |   notification centre, so it lands in the same inbox as everything else
        |   the CMS raises and honours each user's notification preferences.
        |   Set "role" to the role that should receive booking notices.
        |
        | - Without it, the notice is written as an ordinary Laravel database
        |   notification. Set "notifiable" to the model class and "ids" to its
        |   primary keys.
        |
        | "role" wins where both are set and cms-framework is installed, because
        | a role stays right as staff join and leave while a list of ids goes
        | stale the moment somebody does. "ids" is the fallback on both paths.
        |
        | Left entirely unset, the channel reports itself unsupported and only
        | the customer is notified — silence being the better default over
        | notifying the wrong people.
        |
        | "driver" decides which of the two answers: "auto" (the default) picks
        | the CMS centre when cms-framework is installed, while "cms" and
        | "laravel" force one regardless — for an installation that runs the CMS
        | admin shell but wants booking notices kept in Laravel's own table.
        |
        | An application needing something more dynamic should rebind
        | Contracts\NotificationChannel, which is the seam the two implementations
        | are chosen behind.
        */
        'database' => [
            'driver'     => 'auto',
            'role'       => null,
            'notifiable' => null,
            'ids'        => [],
        ],

        /*
        | The gateway the "sms" channel sends through.
        |
        | "null" — the default — logs the message at info level and sends
        | nothing, so an installation that switches "sms" on before it has a
        | gateway can see what would have gone out without paying for it. That
        | log line holds the customer's number and their appointment details,
        | in a file erasing a booking does not reach, so leaving it on in
        | production is a disclosure you have to be willing to make. Any
        | other value is a class name implementing Contracts\SmsDriver, so an
        | application can point this at its own gateway from .env; a name that
        | does not resolve throws rather than quietly falling back to "null",
        | which would leave every customer unreachable and nothing looking
        | wrong. Twilio and Vonage drivers ship in v1.1.
        |
        | Note that "sms" is not in "channels" above and is not added by
        | installing a driver. Texts cost money and reach a real phone, so the
        | channel is opted into — by listing it here, or from a subscriber to
        | "ap.bookings.notification.channels", which is how an installation
        | texts cancellations only, or only customers who asked to be texted.
        */
        'sms_driver' => env( 'BOOKING_SMS_DRIVER', 'null' ),
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
    | The backoff list is the retry schedule, not the attempt count: an endpoint
    | gets one attempt plus one per entry, so the default is six attempts spread
    | over about fourteen hours before the delivery is marked dead.
    |
    | "timeout_seconds" and "connect_timeout_seconds" bound one attempt. They are
    | short on purpose — a delivery is a notification, not a transaction, and a
    | consumer that takes half a minute to answer would otherwise hold a queue
    | worker for every endpoint it is slow for.
    |
    | "queue" names the queue the delivery jobs are pushed onto. Null uses the
    | connection's default. Give webhooks their own queue on an installation
    | where a slow endpoint must not delay anything else that is queued.
    |
    */
    'webhooks' => [
        'failure_threshold'        => 10,
        'delivery_backoff_minutes' => [ 1, 5, 30, 120, 720 ],
        'delivery_retention_days'  => 30,
        'timeout_seconds'          => 10,
        'connect_timeout_seconds'  => 5,
        'queue'                    => null,
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
    | limits applied to it. The "post", "manage_get", and "ical" limits are per
    | IP per minute; "manage_token" is per manage token and "ical_token" is per
    | calendar feed token.
    |
    | "ical" configures the subscribable calendars at "{route_prefix}/ical/…".
    |
    | "past_days" and "future_days" bound what one feed carries, measured from
    | now. A calendar client has no use for last year's appointments, and a
    | subscription is refetched every fifteen minutes to an hour for as long as
    | it exists — so an unbounded feed is the whole archive, serialised, on that
    | timer, once per subscriber. Zero or less on either side reads as "not
    | configured" and falls back to the shipped window.
    |
    | "max_age" is how long a client may reuse a feed before revalidating, in
    | seconds. Short enough that a booking made now appears in the next poll;
    | long enough to absorb a client fetching the same URL from two places.
    |
    | A provider feed is addressed by a token rather than by the provider's slug,
    | so the URL is unguessable and the feed carries the customer's name and
    | email — it is the provider's own diary, behind an address only they have.
    | Mint one with "php artisan bookings:ical-token {provider}"; there is no
    | setting for it, because a token is not configuration.
    |
    | "manage_url" is the address of your self-serve page, with "{token}" where
    | the customer's manage token goes — for example
    | "https://example.com/bookings/manage/{token}". It is what the confirmation
    | email links to, and the package cannot work it out on its own: the
    | self-serve screen is the ManageBooking Livewire component, which you mount
    | on a route of your own choosing, and this package's own "manage/{token}"
    | endpoint answers JSON, which is a fine API and a useless thing to put in
    | an email. Left unset, the confirmation carries no link at all rather than
    | one that goes nowhere.
    |
    */
    'public' => [
        'route_prefix' => 'bookings',
        'manage_url'   => env( 'ARTISANPACK_BOOKINGS_MANAGE_URL' ),
        'rate_limits'  => [
            'post'         => 5,
            'manage_get'   => 20,
            'manage_token' => 60,
            'ical'         => 30,
            'ical_token'   => 30,
        ],
        'ical'         => [
            'past_days'   => 30,
            'future_days' => 365,
            'max_age'     => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long booking data is kept before the prune commands remove it. The
    | default of three years is a starting point, not legal advice — set it to
    | whatever the retention policy you actually operate under requires.
    |
    | "prune_after_days" is the window for the bookings themselves, and nothing
    | reads it yet — booking retention is its own ticket. Setting it (or
    | BOOKING_PRUNE_DAYS) today prunes nothing; the three windows below are the
    | ones with a command behind them.
    |
    | Each of those is read by one scheduled command:
    |
    | - "notification_log_days" by "bookings:prune-notification-log". Keep it
    |   comfortably longer than the longest reminder in "hours_before": the log
    |   row is what stops a reminder being sent twice, so pruning one inside its
    |   own reminder window un-claims a send that already happened.
    | - "webhook_delivery_days" by "bookings:prune-webhook-deliveries". Null
    |   defers to "webhooks.delivery_retention_days", which is the same window
    |   under the settings it belongs to.
    | - "calendar_events_ttl_days" by "bookings:prune-calendar-events", measured
    |   from the booking's end time rather than from the row's own age.
    |
    | A window of zero or less is read as "not configured" and prunes nothing,
    | rather than as "keep nothing" — a blank environment variable is a likelier
    | way to reach zero than a retention policy is.
    |
    */
    'retention' => [
        'prune_after_days'         => env( 'BOOKING_PRUNE_DAYS', 365 * 3 ),
        'notification_log_days'    => 90,
        'webhook_delivery_days'    => null,
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
