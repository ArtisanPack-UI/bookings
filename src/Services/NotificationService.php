<?php

/**
 * Notification service.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Services;

use ArtisanPackUI\Bookings\Contracts\NotificationChannel;
use ArtisanPackUI\Bookings\Enums\NotificationAudience;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Notifications\BookingCancellation;
use ArtisanPackUI\Bookings\Notifications\BookingConfirmation;
use ArtisanPackUI\Bookings\Notifications\BookingNoShow;
use ArtisanPackUI\Bookings\Notifications\BookingNotification;
use ArtisanPackUI\Bookings\Notifications\BookingProviderAssigned;
use ArtisanPackUI\Bookings\Notifications\BookingProviderUnassigned;
use ArtisanPackUI\Bookings\Notifications\BookingReminder;
use ArtisanPackUI\Bookings\Notifications\BookingReschedule;
use DateTimeInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifier;
use Throwable;
use UnexpectedValueException;

/**
 * Sends one lifecycle message about a booking, over every channel that applies.
 *
 * The order of operations is the whole design, and each step is there because
 * the one before it cannot be trusted on its own:
 *
 * 1. Resolve the channels from configuration, then through the
 *    `ap.bookings.notification.channels` filter.
 * 2. Ask the channel whether it has anywhere to deliver.
 * 3. Build the notification and run it through
 *    `ap.bookings.notification.sending`, which may replace it or suppress it.
 * 4. **Claim the log row**, and stop if somebody else already has.
 * 5. Send, then record the outcome.
 *
 * The claim sits at step 4 rather than step 1 because a suppressed or
 * unsupported send should leave no trace to block a later, real one — a channel
 * that was switched off when the reminder cron first ran must still be able to
 * send when it is switched back on. It sits *before* the send rather than after
 * because a claim taken afterwards is not a claim at all: two workers would both
 * find nothing, both send, and both write a row.
 *
 * A channel that throws is recorded as failed and does not stop the others. One
 * dead SMTP host should not cost the admin their database notification.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class NotificationService
{
    /**
     * The notification class that carries each lifecycle message.
     *
     * @since 1.0.0
     *
     * @var array<string, class-string<BookingNotification>>
     */
    protected const NOTIFICATIONS = [
        'confirmation' => BookingConfirmation::class,
        'reminder'     => BookingReminder::class,
        'cancellation' => BookingCancellation::class,
        'reschedule'   => BookingReschedule::class,
        'no_show'      => BookingNoShow::class,
    ];

    /**
     * The notification class that carries each provider-facing message.
     *
     * Kept apart from {@see self::NOTIFICATIONS} because these are addressed to a
     * provider rather than to the booking's customer, and go out through
     * {@see self::sendToProvider()} rather than the customer channel pipeline.
     *
     * @since 1.0.0
     *
     * @var array<string, class-string<BookingNotification>>
     */
    protected const PROVIDER_NOTIFICATIONS = [
        'provider_assigned'   => BookingProviderAssigned::class,
        'provider_unassigned' => BookingProviderUnassigned::class,
    ];

    /**
     * Constructs the service.
     *
     * @since 1.0.0
     *
     * @param  array<int, NotificationChannel>  $channels  The registered channels.
     */
    public function __construct( protected array $channels = [] )
    {
    }

    /**
     * Sends a lifecycle message about a booking.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  Which lifecycle message to send.
     * @param  Booking  $booking  The booking it concerns.
     * @param  DateTimeInterface|null  $scheduledFor  The moment this send was
     *                                                scheduled for, for a
     *                                                reminder; null otherwise.
     *
     * @return array<int, NotificationLog> The rows claimed and sent, in channel order.
     */
    public function send(
        NotificationType $type,
        Booking $booking,
        ?DateTimeInterface $scheduledFor = null,
    ): array {
        if ( ! $this->isEnabled( $type ) ) {
            return [];
        }

        $sent = [];

        foreach ( $this->channelsFor( $type, $booking ) as $channel ) {
            $log = $this->sendVia( $channel, $type, $booking, $scheduledFor );

            if ( null !== $log ) {
                $sent[] = $log;
            }
        }

        return $sent;
    }

    /**
     * Sends a provider-facing message to one provider by email.
     *
     * The customer pipeline in {@see self::send()} cannot carry this: it resolves
     * its recipient from the booking, and the provider a reassignment concerns is
     * either the one now on the booking or — for the "removed" notice — the one
     * that has just left it and is no longer on the row at all. So the provider
     * is passed in, and the message goes out over mail directly rather than
     * through the customer channels.
     *
     * The safety rails the customer path has are kept: the type's enabled flag is
     * honoured, a booking whose personal data has been erased is refused because
     * the staff copy carries the customer's details, and the
     * `ap.bookings.notification.sending` filter can still replace or suppress the
     * message. The log row is claimed before the send and recorded after it, the
     * way the customer path logs its own sends — keyed by booking, type, channel,
     * and a null schedule. Null schedules are not deduplicated
     * ({@see NotificationLog::logSend()}), so the two provider notices one
     * reassignment raises are recorded as distinct rows and both go out; the flip
     * side is that a replayed or re-dispatched `BookingReassigned` would send the
     * same provider a second email rather than being suppressed. That is
     * acceptable here for the reason a lifecycle notice is: the event is raised
     * once, synchronously, by a transition that has already happened.
     *
     * The log records an internal provider reference rather than the address,
     * following {@see Channels\DatabaseChannel::recipient()}: a provider is staff,
     * not the subject of a booking's erasure, so their address has no business
     * being caught by the sweep that redacts `recipient` for an erased booking.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  Which provider message to send.
     * @param  Booking  $booking  The booking it concerns.
     * @param  ServiceProvider  $provider  The provider to notify.
     *
     * @return NotificationLog|null The claimed row, or null when nothing was sent.
     */
    public function sendToProvider( NotificationType $type, Booking $booking, ServiceProvider $provider ): ?NotificationLog
    {
        if ( ! $this->isEnabled( $type ) ) {
            return null;
        }

        if ( $booking->isPiiErased() ) {
            return null;
        }

        $email = trim( (string) $provider->email );

        if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
            return null;
        }

        $notification = $this->filterNotification(
            $this->notificationForProvider( $type, $booking )->forProvider( $provider ),
            $booking,
        );

        if ( null === $notification ) {
            return null;
        }

        $log = NotificationLog::logSend( $booking, $type, 'mail', $this->providerRecipient( $provider ) );

        if ( null === $log ) {
            return null;
        }

        try {
            Notifier::route( 'mail', [ $email => $provider->name ] )->notify( $notification );
        } catch ( Throwable $e ) {
            $log->markFailed( $e->getMessage() );

            Log::warning( 'A booking provider notification could not be sent.', [
                'booking_id'  => $booking->getKey(),
                'type'        => $type->value,
                'provider_id' => $provider->getKey(),
                'exception'   => $e->getMessage(),
            ] );

            return null;
        }

        $log->markSent();

        return $log;
    }

    /**
     * Builds the notification carrying a lifecycle message.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  Which lifecycle message to build.
     * @param  Booking  $booking  The booking it concerns.
     *
     * @return BookingNotification The notification, unfiltered.
     */
    public function notificationFor( NotificationType $type, Booking $booking ): BookingNotification
    {
        $class = self::NOTIFICATIONS[ $type->value ];

        return new $class( $booking );
    }

    /**
     * Builds a provider-facing notification, addressed to the provider audience.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  Which provider message to build.
     * @param  Booking  $booking  The booking it concerns.
     *
     * @throws UnexpectedValueException When the type has no provider notification.
     *
     * @return BookingNotification The notification, unfiltered.
     */
    public function notificationForProvider( NotificationType $type, Booking $booking ): BookingNotification
    {
        $class = self::PROVIDER_NOTIFICATIONS[ $type->value ] ?? null;

        if ( null === $class ) {
            throw new UnexpectedValueException( sprintf(
                'There is no provider notification for the %s message.',
                $type->value,
            ) );
        }

        return ( new $class( $booking ) )->for( NotificationAudience::Provider );
    }

    /**
     * Sends one message over one channel, claiming its log row first.
     *
     * @since 1.0.0
     *
     * @param  NotificationChannel  $channel  The channel to send over.
     * @param  NotificationType  $type  Which lifecycle message to send.
     * @param  Booking  $booking  The booking it concerns.
     * @param  DateTimeInterface|null  $scheduledFor  When the send was scheduled.
     *
     * @return NotificationLog|null The claimed row, or null when nothing was sent.
     */
    protected function sendVia(
        NotificationChannel $channel,
        NotificationType $type,
        Booking $booking,
        ?DateTimeInterface $scheduledFor,
    ): ?NotificationLog {
        if ( ! $channel->supports( $type, $booking ) ) {
            return null;
        }

        $notification = $this->filterNotification( $this->notificationFor( $type, $booking ), $booking );

        if ( null === $notification ) {
            return null;
        }

        $log = NotificationLog::logSend(
            $booking,
            $type,
            $channel->key(),
            $channel->recipient( $type, $booking ),
            $scheduledFor,
        );

        if ( null === $log ) {
            return null;
        }

        try {
            $channel->send( $type, $booking, $notification );
        } catch ( Throwable $e ) {
            // Recorded rather than rethrown, and the loop carries on: the caller
            // is a lifecycle transition that has already happened, or a cron
            // walking hundreds of bookings, and neither should be undone or
            // abandoned because one channel is down. The row keeps the claim, so
            // a retry does not double-send; an operator reading `failed` rows is
            // how a dead channel gets noticed.
            $log->markFailed( $e->getMessage() );

            Log::warning( 'A booking notification could not be sent.', [
                'booking_id' => $booking->getKey(),
                'type'       => $type->value,
                'channel'    => $channel->key(),
                'exception'  => $e->getMessage(),
            ] );

            return null;
        }

        $log->markSent();

        return $log;
    }

    /**
     * Gets what the notification log should record for a provider recipient.
     *
     * An internal reference — the provider model and its key — rather than the
     * address, so that erasing a booking's customer data does not blank the
     * record of which provider was told about it. The reasoning is the same one
     * {@see Channels\DatabaseChannel::recipient()} spells out for staff.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $provider  The provider being notified.
     *
     * @return string The provider reference, as the log should record it.
     */
    protected function providerRecipient( ServiceProvider $provider ): string
    {
        return sprintf( '%s:%s', ServiceProvider::class, (string) $provider->getKey() );
    }

    /**
     * Runs a notification through the sending filter.
     *
     * A subscriber returning null suppresses the send entirely — the hook is
     * where an application says "not this one", whether because the customer
     * opted out or because it has already told them another way. Returning
     * something that is not a notification is a programming error rather than a
     * decision, so it throws: silently sending the unfiltered original would
     * hide the mistake behind a message the subscriber thought it had replaced.
     *
     * @since 1.0.0
     *
     * @param  BookingNotification  $notification  The notification to filter.
     * @param  Booking  $booking  The booking it concerns.
     *
     * @throws UnexpectedValueException When a subscriber returns a non-notification.
     *
     * @return Notification|null The notification to send, or null to suppress.
     */
    protected function filterNotification( BookingNotification $notification, Booking $booking ): ?Notification
    {
        $filtered = applyFilters( 'ap.bookings.notification.sending', $notification, $booking );

        if ( null === $filtered ) {
            return null;
        }

        if ( ! $filtered instanceof Notification ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.notification.sending must return a Notification or null, got %s.',
                get_debug_type( $filtered ),
            ) );
        }

        return $filtered;
    }

    /**
     * Resolves which channels carry a message, after the channels filter.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  Which lifecycle message is being sent.
     * @param  Booking  $booking  The booking it concerns.
     *
     * @throws UnexpectedValueException When a subscriber returns a non-array.
     *
     * @return array<int, NotificationChannel> The channels to send over.
     */
    protected function channelsFor( NotificationType $type, Booking $booking ): array
    {
        /** @var array<int, string> $configured */
        $configured = (array) config( 'artisanpack.bookings.notifications.channels', [] );

        $filtered = applyFilters(
            'ap.bookings.notification.channels',
            array_values( $configured ),
            $type->value,
            $booking,
        );

        if ( ! is_array( $filtered ) ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.notification.channels must return an array, got %s.',
                get_debug_type( $filtered ),
            ) );
        }

        $registered = $this->registeredChannels();
        $resolved   = [];

        foreach ( array_unique( $filtered ) as $key ) {
            if ( ! is_string( $key ) ) {
                continue;
            }

            if ( ! array_key_exists( $key, $registered ) ) {
                // A channel named in configuration with nothing implementing it
                // is skipped rather than fatal. `webhook` ships in the default
                // config ahead of the ticket that implements it, and an install
                // that has not caught up should still send its email.
                Log::debug( 'A configured booking notification channel is not registered.', [
                    'channel' => $key,
                ] );

                continue;
            }

            $resolved[] = $registered[ $key ];
        }

        return $resolved;
    }

    /**
     * Gets the registered channels, keyed by their own channel key.
     *
     * @since 1.0.0
     *
     * @return array<string, NotificationChannel> The channels, keyed by key.
     */
    protected function registeredChannels(): array
    {
        $keyed = [];

        foreach ( $this->channels as $channel ) {
            $keyed[ $channel->key() ] = $channel;
        }

        return $keyed;
    }

    /**
     * Determines whether configuration has this message switched on.
     *
     * Only the three types with a configuration block are switchable. A
     * reschedule or a no-show notice is a consequence of an action somebody
     * deliberately took, and there is no setting to suppress it.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     *
     * @return bool True when the message should be sent.
     */
    protected function isEnabled( NotificationType $type ): bool
    {
        return (bool) config(
            sprintf( 'artisanpack.bookings.notifications.%s.enabled', $type->value ),
            true,
        );
    }
}
