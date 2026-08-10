<?php

/**
 * Base booking notification.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Notifications;

use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use UnexpectedValueException;

use function __;
use function applyFilters;
use function get_debug_type;
use function is_string;
use function sprintf;

/**
 * What every lifecycle message about a booking has in common.
 *
 * The five concrete notifications differ only in their subject, their opening
 * line, and whether they show the appointment as upcoming or as called off.
 * Everything else — the times rendered in the customer's own zone, the service
 * and provider lines, the database payload, the subject filter — is the same
 * message wearing a different hat, so it lives here.
 *
 * **Times are rendered in `customer_timezone`, never the application's.** The
 * booking stores UTC; a customer in Auckland reading "09:00" that means 09:00 in
 * the server's zone will miss their appointment, and will have been told the
 * wrong thing by the confirmation, the reminder, and the reschedule notice
 * alike. {@see Booking::startTimeForCustomer()} is the only reading of the start
 * these messages use.
 *
 * Mail is built with {@see MailMessage}'s markdown default rather than package
 * Blade views. Per #19 the themed Blade emails ship with the widget work, and a
 * notification that renders through a published view an application has not got
 * yet fails at send time — which for a confirmation means the booking succeeds
 * and the customer hears nothing.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
abstract class BookingNotification extends Notification
{
    use Queueable;
    use SerializesModels;

    /**
     * Constructs the notification.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking the message concerns.
     */
    public function __construct( public readonly Booking $booking )
    {
    }

    /**
     * Gets which lifecycle message this is.
     *
     * @since 1.0.0
     *
     * @return NotificationType The notification type.
     */
    abstract public function type(): NotificationType;

    /**
     * Gets the delivery channels Laravel should use for this notifiable.
     *
     * Always `mail` here, and deliberately not read from configuration: the
     * package decides *which* of its own channels run before it ever reaches
     * Laravel's notification system, and a second, disagreeing list at this
     * level would send the database copy twice or not at all.
     *
     * @since 1.0.0
     *
     * @param  mixed  $notifiable  The recipient.
     *
     * @return array<int, string> The Laravel channel names.
     */
    public function via( mixed $notifiable ): array
    {
        return [ 'mail' ];
    }

    /**
     * Builds the mail message.
     *
     * @since 1.0.0
     *
     * @param  mixed  $notifiable  The recipient.
     *
     * @return MailMessage The message to send.
     */
    public function toMail( mixed $notifiable ): MailMessage
    {
        $message = ( new MailMessage() )
            ->subject( $this->subject() )
            ->greeting( __( 'Hello :name,', [ 'name' => $this->booking->customer_name ] ) )
            ->line( $this->openingLine() );

        foreach ( $this->detailLines() as $line ) {
            $message->line( $line );
        }

        return $message;
    }

    /**
     * Builds the database payload.
     *
     * Identifiers and timestamps only — deliberately no customer name, and no
     * rendered prose. Two reasons that point the same way.
     *
     * This payload is written into storage the package does not own and cannot
     * sweep: Laravel's `notifications` table, or cms-framework's. Erasing a
     * booking redacts the booking row and the notification log, both of which
     * are keyed to it, but a staff notification carrying the customer's name
     * would keep that name readable afterwards in a table nothing in the erasure
     * routine walks. The way to keep a name out of an erasure sweep's blind spot
     * is not to write it there.
     *
     * The name is not lost, only not duplicated. A staff screen holds
     * `booking_id` and reads the booking for anything it wants to display —
     * which means it shows the redaction placeholder after an erasure, and the
     * current name after a correction, rather than whatever was true on the day
     * the notification was written.
     *
     * @since 1.0.0
     *
     * @param  mixed  $notifiable  The recipient.
     *
     * @return array<string, mixed> The stored payload.
     */
    public function toArray( mixed $notifiable ): array
    {
        return [
            'type'              => $this->type()->value,
            'booking_id'        => $this->booking->getKey(),
            'booking_number'    => $this->booking->booking_number,
            'service_id'        => $this->booking->service_id,
            'provider_id'       => $this->booking->provider_id,
            'starts_at'         => $this->booking->start_time->toIso8601String(),
            'customer_timezone' => $this->booking->customer_timezone,
        ];
    }

    /**
     * Gets the subject line, after the subject filter has had it.
     *
     * The filter runs here rather than in the notification service so that it
     * applies however the notification is sent — including an application
     * dispatching one of these through Laravel's own `Notification` facade,
     * which never touches this package's service at all.
     *
     * @since 1.0.0
     *
     * @throws UnexpectedValueException When a subscriber returns a non-string.
     *
     * @return string The filtered subject.
     */
    public function subject(): string
    {
        $filtered = applyFilters(
            'ap.bookings.notification.subject',
            $this->defaultSubject(),
            $this,
            $this->booking,
        );

        if ( ! is_string( $filtered ) ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.notification.subject must return a string, got %s.',
                get_debug_type( $filtered ),
            ) );
        }

        return $filtered;
    }

    /**
     * Gets the subject line this notification would use unfiltered.
     *
     * @since 1.0.0
     *
     * @return string The default subject.
     */
    abstract protected function defaultSubject(): string;

    /**
     * Gets the first line of the message body.
     *
     * @since 1.0.0
     *
     * @return string The opening line.
     */
    abstract protected function openingLine(): string;

    /**
     * Gets the appointment detail lines shown under the opening line.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The detail lines.
     */
    protected function detailLines(): array
    {
        $lines = [
            __( 'Reference: :number', [ 'number' => $this->booking->booking_number ] ),
            __( 'When: :when', [ 'when' => $this->formattedStart() ] ),
        ];

        $service = $this->booking->service;

        if ( null !== $service ) {
            $lines[] = __( 'Service: :service', [ 'service' => $service->name ] );
        }

        $provider = $this->booking->provider;

        if ( null !== $provider ) {
            $lines[] = __( 'With: :provider', [ 'provider' => $provider->name ] );
        }

        return $lines;
    }

    /**
     * Renders the start time in the customer's own timezone.
     *
     * @since 1.0.0
     *
     * @return string The formatted local start, with its zone named.
     */
    protected function formattedStart(): string
    {
        $start = $this->booking->startTimeForCustomer();

        return $start->format( 'l, j F Y \a\t H:i' ) . ' (' . $start->format( 'T' ) . ')';
    }
}
