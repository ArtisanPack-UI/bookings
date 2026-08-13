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
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Notifications\BookingCancellation;
use ArtisanPackUI\Bookings\Notifications\BookingConfirmation;
use ArtisanPackUI\Bookings\Notifications\BookingNoShow;
use ArtisanPackUI\Bookings\Notifications\BookingNotification;
use ArtisanPackUI\Bookings\Notifications\BookingReminder;
use ArtisanPackUI\Bookings\Notifications\BookingReschedule;
use DateTimeInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
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
