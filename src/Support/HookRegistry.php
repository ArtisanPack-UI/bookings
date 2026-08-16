<?php

/**
 * Canonical hook registry.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Support;

/**
 * The one list of every hook this package owns.
 *
 * Hook names stay as literals at their call sites — that is how the rest of the
 * ArtisanPack UI ecosystem writes them, and a constant read at the call site
 * hides the name from the person reading the code that fires it. What that
 * costs is a single place to look, so this class is that place: a catalogue
 * rather than a source of names.
 *
 * Nothing in `src/` reads this registry. It exists to be read by extenders, and
 * to be asserted against by `tests/Feature/Support/HookRegistryTest.php`, which
 * holds the two halves of the contract together:
 *
 * - Every name declared here that is not `pending` is fired somewhere in `src/`.
 * - Every `ap.bookings.*` literal in `src/` is declared here.
 *
 * So a surface that ships without its hook fails the suite, and a hook that
 * ships without its entry fails it too.
 *
 * Each entry carries its payload as a plain comment rather than a docblock tag.
 * A docblock can only describe the thing it is attached to, and php-cs-fixer
 * enforces that by reordering and dropping tags it cannot place — thirty hook
 * signatures in one docblock came back from the formatter with every `@return`
 * removed.
 *
 * ## Naming
 *
 * `ap.bookings.{camelCase}`, `.`-separated segments, camelCase within a
 * segment. Never snake_case. The convention is ecosystem-wide and locked; the
 * pre-`ap.` names listed in {@see self::retired()} are gone and asserted gone.
 *
 * ## Pending hooks
 *
 * A hook is `pending` when the surface that fires it has not been built yet.
 * The test asserts pending names are *absent* from `src/`, so the ticket that
 * builds the surface has to come back here and drop the flag — which is the
 * moment somebody is looking at the payload comment anyway.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class HookRegistry
{
    /**
     * Every hook the package owns, keyed by name.
     *
     * The `issues` value names the ticket that fires the hook, for tracing a
     * name back to the surface it belongs to. `pending` is absent on a shipped
     * hook rather than set to false, so the shipped entries stay readable.
     *
     * @since 1.0.0
     *
     * @var array<string, array{type: string, issues: string, pending?: true}>
     */
    private const HOOKS = [

        /*
        | Booking lifecycle — #13.
        |
        | creating     ( array $attributes, ?Authenticatable $customer )
        | created      ( Booking $booking )
        | confirmed    ( Booking $booking )
        | rescheduling ( Booking $booking, CarbonImmutable $newStart )
        | rescheduled  ( Booking $booking, CarbonImmutable $oldStart )
        | cancelling   ( Booking $booking, string $reason )
        | cancelled    ( Booking $booking )
        | completed    ( Booking $booking )
        | noShow       ( Booking $booking )
        | reassigned   ( Booking $booking, ?int $previousProviderId )
        */
        'ap.bookings.creating'     => [ 'type' => 'action', 'issues' => '#13' ],
        'ap.bookings.created'      => [ 'type' => 'action', 'issues' => '#13' ],
        'ap.bookings.confirmed'    => [ 'type' => 'action', 'issues' => '#13' ],
        'ap.bookings.rescheduling' => [ 'type' => 'action', 'issues' => '#13' ],
        'ap.bookings.rescheduled'  => [ 'type' => 'action', 'issues' => '#13' ],
        'ap.bookings.cancelling'   => [ 'type' => 'action', 'issues' => '#13' ],
        'ap.bookings.cancelled'    => [ 'type' => 'action', 'issues' => '#13' ],
        'ap.bookings.completed'    => [ 'type' => 'action', 'issues' => '#13, #22' ],
        'ap.bookings.noShow'       => [ 'type' => 'action', 'issues' => '#13, #33' ],
        'ap.bookings.reassigned'   => [ 'type' => 'action', 'issues' => '#13, #37' ],

        /*
        | Availability — #12, #42.
        |
        | availabilityQuery ( Builder $query, array $criteria ): Builder
        |                   $criteria: service_id, provider_id, date, timezone.
        | availableSlots    ( Slot[] $slots, ServiceProvider $provider, CarbonPeriod $window ): Slot[]
        | slotBookable      ( bool $bookable, Slot $slot, ?Authenticatable $customer ): bool
        | slotDuration      ( int $minutes, Service $service, ServiceProvider $provider ): int
        */
        'ap.bookings.availabilityQuery' => [ 'type' => 'filter', 'issues' => '#12, #42' ],
        'ap.bookings.availableSlots'    => [ 'type' => 'filter', 'issues' => '#12, #42' ],
        'ap.bookings.slotBookable'      => [ 'type' => 'filter', 'issues' => '#12' ],
        'ap.bookings.slotDuration'      => [ 'type' => 'filter', 'issues' => '#12' ],

        /*
        | Provider assignment — #13.
        |
        | availableProviders        ( ServiceProvider[] $providers, Service $service, CarbonImmutable $start ): ServiceProvider[]
        | roundRobin.selectProvider ( ?ServiceProvider $selected, ServiceProvider[] $candidates, Booking $draft ): ?ServiceProvider
        */
        'ap.bookings.availableProviders'        => [ 'type' => 'filter', 'issues' => '#13' ],
        'ap.bookings.roundRobin.selectProvider' => [ 'type' => 'filter', 'issues' => '#13' ],

        /*
        | Meeting types and intake — #11, #15, #30.
        |
        | registeredMeetingTypes ( array<string, MeetingType> $types ): array<string, MeetingType>
        | intakeSchema           ( array $schema, Service $service, int $version ): array
        |                        $version is the schema version the booking was captured with,
        |                        not the service's current one.
        */
        'ap.bookings.registeredMeetingTypes' => [ 'type' => 'filter', 'issues' => '#11' ],
        'ap.bookings.intakeSchema'           => [ 'type' => 'filter', 'issues' => '#15, #30' ],

        /*
        | Recurring series — #14.
        |
        | series.editApplying ( BookingSeries $series, string $scope, array $changes )
        |                     $scope is a SeriesEditScope value.
        */
        'ap.bookings.series.editApplying' => [ 'type' => 'action', 'issues' => '#14' ],

        /*
        | Manage tokens — #16. Absent from the #18 table, which predates the
        | service; declared here because the registry is the whole list, and a
        | hook missing from it fails the registry test.
        |
        | manageTokenReissued  ( Booking $booking, string $token )
        |                      $token is a LIVE SECRET — the plain manage token, at
        |                      the only moment it is readable, and the whole
        |                      credential behind a public booking. The hook exists so
        |                      new links can be sent after an emergency rotation. Do
        |                      not log it, and do not put it anywhere the hash was
        |                      deliberately kept out of.
        | manageTokensReissued ( int $count )
        */
        'ap.bookings.manageTokenReissued'  => [ 'type' => 'action', 'issues' => '#16' ],
        'ap.bookings.manageTokensReissued' => [ 'type' => 'action', 'issues' => '#16' ],

        /*
        | Calendar feed tokens — #82.
        |
        | icalTokenIssued  ( ServiceProvider $provider, string $token )
        |                  $token is a LIVE SECRET — the plain feed token, at the only
        |                  moment it is readable, and the whole credential behind a
        |                  provider's calendar feed. The hook exists so an application
        |                  can deliver the new subscription URL to the provider. Do not
        |                  log it, and do not put it anywhere the hash was deliberately
        |                  kept out of. Fires on a rotation as well as a first issue,
        |                  and the previous token is already dead by the time it does.
        | icalTokenRevoked ( ServiceProvider $provider )
        |                  The feed 404s from here on. Nothing is minted to replace it.
        */
        'ap.bookings.icalTokenIssued'      => [ 'type' => 'action', 'issues' => '#82' ],
        'ap.bookings.icalTokenRevoked'     => [ 'type' => 'action', 'issues' => '#82' ],

        /*
        | Calendar sync — #39, #40, #41, #43, #44.
        |
        | calendarSync.providers          ( array<string, CalendarSyncDriver> $drivers ): array<string, CalendarSyncDriver>
        | calendarSync.pushing            ( Booking $booking, string $providerSlug )
        | calendarSync.pushed             ( Booking $booking, string $providerSlug, string $externalEventId )
        |                                 Every driver fires both with the booking and its own
        |                                 provider slug — 'ical' (#39), 'google' (#40), and so on
        |                                 — so one consumer callback reads the same arguments
        |                                 whichever calendar the push went to. pushed carries the
        |                                 external identifier the write landed under as well.
        | calendarSync.pullReceived       ( array $payload, string $providerSlug )
        |                                 $payload is the external calendar's raw change
        |                                 feed, PERSONAL DATA that is not this package's —
        |                                 event titles and descriptions, organiser and
        |                                 attendee email addresses, the provider's private
        |                                 diary. It is handed over before normalisation so a
        |                                 subscriber can see what the calendar actually sent.
        |                                 Do not log it, and treat anything drawn from it as
        |                                 third-party data.
        | calendarSync.eventPayload       ( array $payload, Booking $booking, string $providerSlug ): array
        | calendarSync.connectionDisabled ( CalendarConnection $connection, string $reason )
        */
        'ap.bookings.calendarSync.providers' => [
            'type'   => 'filter',
            'issues' => '#39',
        ],
        'ap.bookings.calendarSync.pushing' => [
            'type'   => 'action',
            'issues' => '#39, #40, #41, #43, #44',
        ],
        'ap.bookings.calendarSync.pushed' => [
            'type'   => 'action',
            'issues' => '#39, #40, #41, #43, #44',
        ],
        'ap.bookings.calendarSync.pullReceived' => [
            'type'   => 'action',
            'issues' => '#40, #43, #44',
        ],
        'ap.bookings.calendarSync.eventPayload' => [
            'type'   => 'filter',
            'issues' => '#39, #40, #43, #44',
        ],
        'ap.bookings.calendarSync.connectionDisabled' => [
            'type'    => 'action',
            'issues'  => '#41',
            'pending' => true,
        ],

        /*
        | Notifications — #19, #21, #22, #28, #34.
        |
        | notification.sending  ( BookingNotification $notification, Booking $booking ): ?Notification
        |                       Return null to suppress the send entirely. Returning
        |                       anything that is not a Notification throws — a
        |                       subscriber meaning to veto says so with null.
        |                       Runs per channel, before the log row is claimed, so a
        |                       suppressed send leaves nothing behind to block a later one.
        | notification.channels ( string[] $channels, string $event, Booking $booking ): string[]
        |                       $event is a NotificationType value: 'confirmation',
        |                       'reminder', 'cancellation', 'reschedule', 'no_show'.
        |                       A channel key nothing is registered for is skipped.
        | notification.subject  ( string $subject, BookingNotification $notification, Booking $booking ): string
        |                       Covers every notification type; discriminate on
        |                       $notification->type(). Applied by the notification
        |                       itself, so it holds even when an application dispatches
        |                       one through Laravel's own Notification facade.
        | reminderScheduling    ( int[] $hoursBefore, Booking $booking ): int[]
        |                       The cadence, in whole hours before the start. Runs
        |                       before the log row is claimed, so the unique key covers
        |                       filtered cadences too; duplicates are also collapsed,
        |                       so an added window that config already has cannot
        |                       produce a duplicate send. A window longer than anything
        |                       in config needs reminder.max_lookahead_hours raised to
        |                       match, or the booking is never looked at.
        */
        'ap.bookings.notification.sending'  => [ 'type' => 'filter', 'issues' => '#19' ],
        'ap.bookings.notification.channels' => [ 'type' => 'filter', 'issues' => '#19, #21, #34' ],
        'ap.bookings.notification.subject'  => [ 'type' => 'filter', 'issues' => '#19, #28' ],
        'ap.bookings.reminderScheduling'    => [ 'type' => 'filter', 'issues' => '#19, #22' ],
    ];

    /**
     * Hook names the package used to plan on, and must never use again.
     *
     * These are the plan §5.5 names, retired when the ecosystem settled on the
     * `ap.` prefix and camelCase segments. They are listed rather than merely
     * deleted because the failure they cause is silent: a subscriber written
     * against one of them binds cleanly and simply never fires.
     *
     * The registry test asserts none of them appear in `src/` or `tests/`, and
     * separately asserts the *shape* of every hook literal, which catches a
     * retired name nobody thought to list here.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    private const RETIRED = [
        'bookings.slot.calculating',
        'bookings.available.providers',
        'bookings.intake.schema',
        'bookings.customer.confirmation.subject',
        'bookings.booking.creating',
        'bookings.booking.created',
        'bookings.booking.cancelled',
        'bookings.meeting.types',
        'bookings.calendar.sync',
    ];

    /**
     * Gets every hook the package owns, shipped and pending alike.
     *
     * @since 1.0.0
     *
     * @return array<string, array{type: string, issues: string, pending?: true}> The registry, keyed by hook name.
     */
    public static function all(): array
    {
        return self::HOOKS;
    }

    /**
     * Gets the hooks that are fired by code that exists today.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The shipped hook names.
     */
    public static function shipped(): array
    {
        return array_keys( array_filter(
            self::HOOKS,
            static fn ( array $hook ): bool => ! ( $hook['pending'] ?? false ),
        ) );
    }

    /**
     * Gets the hooks whose surfaces have not been built yet.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The pending hook names.
     */
    public static function pending(): array
    {
        return array_keys( array_filter(
            self::HOOKS,
            static fn ( array $hook ): bool => (bool) ( $hook['pending'] ?? false ),
        ) );
    }

    /**
     * Gets the pre-`ap.` hook names that must not come back.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The retired hook names.
     */
    public static function retired(): array
    {
        return self::RETIRED;
    }
}
