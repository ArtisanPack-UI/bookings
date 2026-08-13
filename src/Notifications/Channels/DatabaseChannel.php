<?php

/**
 * Database notification channel.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Notifications\Channels;

use ArtisanPackUI\Bookings\Contracts\NotificationChannel;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Notifications\Channels\Concerns\FitsRecipientColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * Writes a lifecycle message to the admin notifications table.
 *
 * This is the staff-facing half of a send: the customer gets an email, the
 * people who have to act on it get a row in Laravel's `notifications` table for
 * an admin screen to read back.
 *
 * **Who counts as an admin is the application's answer, not the package's.**
 * There is no user table this package can assume, no role system it can query,
 * and iterating every user to test a gate is not something a notification path
 * can afford. So the recipients come from configuration —
 * `artisanpack.bookings.notifications.database.notifiable` and `.ids` — and the
 * channel reports itself unsupported until they are set. An unconfigured
 * installation therefore sends the customer their email and writes no admin
 * rows, which is the right default: silence beats notifying the wrong people.
 *
 * An application whose admin list is dynamic rebinds this class in the
 * container; {@see self::recipients()} is the one method it needs to replace.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class DatabaseChannel implements NotificationChannel
{
    use FitsRecipientColumn;

    /**
     * Gets the identifier this channel is configured and logged under.
     *
     * @since 1.0.0
     *
     * @return string The channel key.
     */
    public function key(): string
    {
        return 'database';
    }

    /**
     * Determines whether this channel can carry a message for a booking.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return bool True when the application has named admins to notify.
     */
    public function supports( NotificationType $type, Booking $booking ): bool
    {
        return $this->recipients()->isNotEmpty();
    }

    /**
     * Gets what the notification log should record as the recipient.
     *
     * An internal reference — the notifiable class and the keys — rather than
     * staff email addresses. Two reasons, both load-bearing. The log's erasure
     * routine redacts `recipient` for every row of an erased booking, and staff
     * are not the subject of that erasure, so their addresses have no business
     * being caught by it. And a staff address here would be a second copy of
     * something the application already holds, sitting in a table whose whole
     * documented purpose is customer contact details.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return string The notifiable class and keys, within the column's limit.
     */
    public function recipient( NotificationType $type, Booking $booking ): string
    {
        $recipients = $this->recipients();

        /** @var class-string|null $class */
        $class = $this->notifiableClass();
        $keys  = $recipients
            ->map( static fn ( Model $notifiable ): string => (string) $notifiable->getKey() )
            ->all();

        return $this->fitRecipient(
            sprintf( '%s:%s', (string) $class, implode( ',', $keys ) ),
            (string) $class,
            count( $keys ),
        );
    }

    /**
     * Sends the message.
     *
     * Sent through Laravel's notification system with the `database` channel
     * forced, rather than through the notification's own `via()`. These
     * notifications declare `mail` there — that is what they are for — and
     * routing the admin copy by the same list would email the customer's
     * appointment to every administrator.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     * @param  Notification  $notification  The filtered notification to deliver.
     *
     * @return void
     */
    public function send( NotificationType $type, Booking $booking, Notification $notification ): void
    {
        Notifier::sendNow( $this->recipients(), $notification, [ 'database' ] );
    }

    /**
     * Gets the admin notifiables configured to receive booking notifications.
     *
     * @since 1.0.0
     *
     * @return Collection<int, Model> The notifiables, empty when unconfigured.
     */
    protected function recipients(): Collection
    {
        /** @var class-string<Model>|null $class */
        $class = $this->notifiableClass();

        if ( null === $class ) {
            return new Collection();
        }

        $ids = array_values( array_filter(
            array_map(
                static fn ( mixed $id ): int|string => is_numeric( $id ) ? (int) $id : (string) $id,
                (array) config( 'artisanpack.bookings.notifications.database.ids', [] ),
            ),
            static fn ( int|string $id ): bool => '' !== $id && 0 !== $id,
        ) );

        if ( [] === $ids ) {
            return new Collection();
        }

        return $class::query()->whereKey( $ids )->get();
    }

    /**
     * Gets the configured notifiable class, when it is usable.
     *
     * A class named in configuration but absent from the application — a model
     * renamed, a config copied between projects — returns null rather than
     * fataling inside a notification send. The cost of being wrong here is an
     * admin row that does not appear; the cost of throwing is a booking
     * confirmation that does not either.
     *
     * @since 1.0.0
     *
     * @return class-string<Model>|null The notifiable class, or null.
     */
    protected function notifiableClass(): ?string
    {
        $class = config( 'artisanpack.bookings.notifications.database.notifiable' );

        if ( ! is_string( $class ) || '' === $class ) {
            return null;
        }

        if ( ! class_exists( $class ) || ! is_a( $class, Model::class, true ) ) {
            return null;
        }

        /** @var class-string<Model> $class */
        return $class;
    }
}
