<?php

/**
 * CMS framework notification channel.
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
use ArtisanPackUI\Bookings\Notifications\BookingNotification;
use ArtisanPackUI\Bookings\Notifications\Channels\Concerns\FitsRecipientColumn;
use Illuminate\Notifications\Notification;
use RuntimeException;

use function apSendNotification;
use function apSendNotificationByRole;
use function array_filter;
use function array_map;
use function array_values;
use function config;
use function count;
use function function_exists;
use function implode;
use function is_numeric;
use function is_string;
use function sprintf;

/**
 * Hands the staff-facing copy to cms-framework's notification centre.
 *
 * The alternative — writing a Laravel database notification — does not work
 * alongside `artisanpack-ui/cms-framework` at all: it ships its own
 * `notifications` table, keyed by an auto-increment id and carrying
 * `title`/`content` prose, where Laravel's database channel wants a UUID key and
 * a JSON `data` column. The two cannot share a table, and the failure is the
 * quiet kind: every insert errors, the log row is marked failed, and the admin
 * simply never hears about a booking.
 *
 * Delegating rather than working around it is also the better outcome on its own
 * merits. A booking notice arrives in the same notification centre as everything
 * else the CMS raises, it honours the per-user preferences that centre already
 * has, and cms-framework's own queued email is available without this package
 * growing a second opinion about when staff want to be emailed.
 *
 * {@see DatabaseChannel} is the standalone equivalent, and the service provider
 * picks between them on whether cms-framework is installed.
 *
 * ## What is registered
 *
 * The five lifecycle messages are registered as notification types at boot —
 * `bookings.confirmation` and friends — so they appear in the preferences UI as
 * things a member of staff can turn off, rather than arriving as untyped rows
 * nobody can opt out of. Registration lives in the service provider, because it
 * has to happen whether or not a notification is ever sent.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class CmsFrameworkChannel implements NotificationChannel
{
    use FitsRecipientColumn;

    /**
     * The notification key each lifecycle message is registered under.
     *
     * Prefixed, because the key is global to the CMS notification registry and
     * `confirmation` on its own belongs to nobody.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY_PREFIX = 'bookings.';

    /**
     * Gets the identifier this channel is configured and logged under.
     *
     * The same key as the standalone channel. Which implementation answers to it
     * is an installation detail, and configuration naming `database` should not
     * have to know which one it got.
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
     * @return bool True when there is somebody to notify.
     */
    public function supports( NotificationType $type, Booking $booking ): bool
    {
        if ( ! function_exists( 'apSendNotification' ) ) {
            return false;
        }

        return null !== $this->role() || [] !== $this->ids();
    }

    /**
     * Gets what the notification log should record as the recipient.
     *
     * The role, or the ids it fell back to — an internal reference either way
     * rather than a staff address, so the erasure sweep that redacts
     * `recipient` for a booking leaves the record of who was told intact.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return string The audience, as the log should record it.
     */
    public function recipient( NotificationType $type, Booking $booking ): string
    {
        $role = $this->role();

        if ( null !== $role ) {
            return $this->fitRecipient( 'role:' . $role, 'role', 1 );
        }

        $ids = $this->ids();

        return $this->fitRecipient( 'users:' . implode( ',', $ids ), 'users', count( $ids ) );
    }

    /**
     * Sends the message.
     *
     * By role when one is configured, because a role stays right as staff join
     * and leave while a list of ids goes stale the moment somebody does — and
     * nothing notices, because the notification that should have gone to the new
     * administrator simply does not exist.
     *
     * cms-framework returns null when every candidate has the notification
     * switched off in their preferences. That is a delivery that correctly did
     * not happen rather than a failure, so it is not thrown: the log row records
     * the send as made, which is also what stops the reminder cron retrying it on
     * every pass for staff who have said they do not want it.
     *
     * @since 1.0.0
     *
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     * @param  Notification  $notification  The filtered notification to deliver.
     *
     * @throws RuntimeException When cms-framework's helpers are unavailable.
     *
     * @return void
     */
    public function send( NotificationType $type, Booking $booking, Notification $notification ): void
    {
        if ( ! function_exists( 'apSendNotification' ) ) {
            throw new RuntimeException(
                'The cms-framework notification helpers are unavailable. Is artisanpack-ui/cms-framework installed?',
            );
        }

        $overrides = [
            'title'    => $this->titleFor( $notification, $type, $booking ),
            'content'  => $this->contentFor( $booking ),
            'metadata' => $this->metadataFor( $notification, $booking ),
        ];

        $role = $this->role();

        if ( null !== $role ) {
            apSendNotificationByRole( self::KEY_PREFIX . $type->value, $role, $overrides );

            return;
        }

        apSendNotification( self::KEY_PREFIX . $type->value, $this->ids(), $overrides );
    }

    /**
     * Builds the title staff see for a lifecycle message.
     *
     * Reuses the notification's own filtered subject, so an application that
     * rewrote it through `ap.bookings.notification.subject` gets the rewrite in
     * the notification centre too rather than only in the customer's inbox.
     *
     * @since 1.0.0
     *
     * @param  Notification  $notification  The notification being delivered.
     * @param  NotificationType  $type  The lifecycle message being sent.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return string The notification title.
     */
    protected function titleFor( Notification $notification, NotificationType $type, Booking $booking ): string
    {
        if ( $notification instanceof BookingNotification ) {
            return $notification->subject();
        }

        return sprintf( '%s: %s', $type->value, $booking->booking_number );
    }

    /**
     * Builds the body staff see for a lifecycle message.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return string The notification content.
     */
    protected function contentFor( Booking $booking ): string
    {
        return sprintf(
            '%s — %s',
            $booking->customer_name,
            $booking->startTimeForCustomer()->format( 'j M Y H:i T' ),
        );
    }

    /**
     * Builds the machine-readable half of the notification.
     *
     * The identifiers go here rather than into the prose, so an admin screen
     * linking a notification back to its booking reads a column rather than
     * parsing a sentence that changes with the reader's locale.
     *
     * @since 1.0.0
     *
     * @param  Notification  $notification  The notification being delivered.
     * @param  Booking  $booking  The booking the message concerns.
     *
     * @return array<string, mixed> The notification metadata.
     */
    protected function metadataFor( Notification $notification, Booking $booking ): array
    {
        if ( $notification instanceof BookingNotification ) {
            return $notification->toArray( null );
        }

        return [ 'booking_id' => $booking->getKey() ];
    }

    /**
     * Gets the configured role to notify, when there is one.
     *
     * @since 1.0.0
     *
     * @return string|null The role name, or null when none is set.
     */
    protected function role(): ?string
    {
        $role = config( 'artisanpack.bookings.notifications.database.role' );

        if ( ! is_string( $role ) || '' === $role ) {
            return null;
        }

        return $role;
    }

    /**
     * Gets the explicitly configured user ids to notify.
     *
     * @since 1.0.0
     *
     * @return array<int, int> The user ids.
     */
    protected function ids(): array
    {
        return array_values( array_map(
            static fn ( mixed $id ): int => (int) $id,
            array_filter(
                (array) config( 'artisanpack.bookings.notifications.database.ids', [] ),
                static fn ( mixed $id ): bool => is_numeric( $id ) && (int) $id > 0,
            ),
        ) );
    }
}
