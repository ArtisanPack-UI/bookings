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

use ArtisanPackUI\Bookings\Enums\NotificationAudience;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use UnexpectedValueException;

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
 * Mail renders through the package's own Blade views under `bookings::emails`,
 * which are loaded from the package and only *optionally* published: an
 * installation that has never run `vendor:publish --tag=bookings-views` still
 * has a template to render, so a confirmation cannot fail at send time and
 * leave a booking made and a customer unaware of it. An installation that has
 * published wins, because a published view shadows the packaged one — which is
 * the override path for markup, the same way
 * `ap.bookings.notification.subject` is the override path for the subject.
 *
 * Which view is picked comes from {@see NotificationAudience}: the customer's
 * copy and the staff copy are the same event described to two different
 * readers, in two different zones, and only one of them gets the manage link.
 * The audience is carried on the notification rather than read off the
 * notifiable, because Laravel's on-demand notifiable is an address and an
 * address does not know whose it is.
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
     * Who this copy of the message is being written for.
     *
     * @since 1.0.0
     *
     * @var NotificationAudience
     */
    protected NotificationAudience $audience = NotificationAudience::Customer;

    /**
     * The plain manage token this message was built with, once taken.
     *
     * {@see Booking::pullPlainManageToken()} answers once and then forgets, so
     * the answer is kept here. Without it, rendering the same message twice —
     * a preview beside a send, a test asserting on the body it just built —
     * would produce a link the first time and nothing the second.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    protected ?string $manageToken = null;

    /**
     * Whether the plain manage token has already been asked for.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $manageTokenTaken = false;

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
     * Serialises the notification without the plain manage token.
     *
     * {@see Booking} keeps the plain token off its own serialised form for a
     * reason — it is the customer's whole credential — and a copy of it cached
     * here would put it straight back on, in the `jobs` table, in
     * `failed_jobs`, and in whatever a queue driver keeps after a crash. Those
     * outlive the message and are not swept by erasing a booking.
     *
     * What a restored notification loses is the manage link, not the message: a
     * booking read back from the database has no plain token either, so a
     * queued confirmation was already going to render without one. The link
     * belongs to the send that minted the token, which is a synchronous one.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The serialisable state.
     */
    public function __serialize(): array
    {
        $values = parent::__serialize();

        // A protected property is keyed the way PHP mangles one — "\0*\0name" —
        // and not by its bare name, which is the mistake that makes a guard like
        // this quietly do nothing. Both are removed so that a subclass promoting
        // the property to public is covered too.
        unset( $values[ "\0*\0manageToken" ], $values['manageToken'] );

        return $values;
    }

    /**
     * Gets a copy of this notification written for a different audience.
     *
     * Returns a new instance rather than mutating this one. The same
     * notification object is handed to every channel a send fans out over, and
     * one that flipped audience in place would leave whichever channel ran next
     * rendering staff wording to the customer — including the manage link's
     * absence, which is the failure that looks like nothing is wrong.
     *
     * @since 1.0.0
     *
     * @param  NotificationAudience  $audience  Who the copy is for.
     *
     * @return static The notification, addressed to that audience.
     */
    public function for( NotificationAudience $audience ): static
    {
        $copy           = clone $this;
        $copy->audience = $audience;

        return $copy;
    }

    /**
     * Gets who this copy of the message is written for.
     *
     * @since 1.0.0
     *
     * @return NotificationAudience The audience.
     */
    public function audience(): NotificationAudience
    {
        return $this->audience;
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
        // The heading the body prints is the subject, taken from the one
        // viewData() already resolved rather than filtered a second time here.
        // A subscriber to `ap.bookings.notification.subject` is entitled to be
        // asked once per message: called twice, one that appends — a site name,
        // a ticket reference — would put a different string in the header from
        // the one printed at the top of the email.
        $data = $this->viewData();

        return ( new MailMessage() )
            ->subject( $data['heading'] )
            ->view( $this->view(), $data );
    }

    /**
     * Gets the Blade view this copy of the message renders through.
     *
     * @since 1.0.0
     *
     * @return string The fully-qualified view name.
     */
    public function view(): string
    {
        return 'bookings::emails.' . $this->audience->value . '.' . $this->type()->value;
    }

    /**
     * Gets everything the email template needs to render.
     *
     * Composed here rather than read off the booking in Blade so that a
     * published template cannot drift from the wording the text message uses,
     * and so that the one decision a template must not get wrong — which zone
     * the time is shown in — is made in PHP where it can be tested.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The view data.
     */
    public function viewData(): array
    {
        return [
            'audience'     => $this->audience,
            'booking'      => $this->booking,
            'details'      => $this->detailRows(),
            'greeting'     => $this->greeting(),
            'heading'      => $this->subject(),
            'manageUrl'    => $this->manageUrl(),
            'notification' => $this,
            'openingLine'  => $this->currentOpeningLine(),
            'signature'    => __( 'Thanks,' ) . ' ' . (string) config( 'app.name', 'Laravel' ),
            'startsAt'     => $this->localStart(),
        ];
    }

    /**
     * Builds the text of a message.
     *
     * The same opening line and appointment details the email carries, without
     * the greeting and without the subject: a text arrives already addressed to
     * one phone, and a subject repeated as a first line is a wasted segment.
     * Written from the same two methods the mail body uses rather than as a
     * second copy of the wording, so a correction to a lifecycle message reaches
     * both.
     *
     * Takes no notifiable, unlike Laravel's `toMail()` and `toArray()`. There is
     * none to take — the customer has no account, the channel already has the
     * number from the booking, and a parameter that could only ever be null is
     * one a subscriber's own `toSms()` would have to declare and ignore.
     *
     * @since 1.0.0
     *
     * @return string The message body.
     */
    public function toSms(): string
    {
        return implode( "\n", [ $this->currentOpeningLine(), ...$this->detailLines() ] );
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
            $this->unfilteredSubject(),
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
     * Gets the subject line for this audience, before the filter runs.
     *
     * @since 1.0.0
     *
     * @return string The unfiltered subject.
     */
    protected function unfilteredSubject(): string
    {
        return NotificationAudience::Admin === $this->audience
            ? $this->adminSubject()
            : $this->defaultSubject();
    }

    /**
     * Gets the first line of the body for this audience.
     *
     * @since 1.0.0
     *
     * @return string The opening line.
     */
    protected function currentOpeningLine(): string
    {
        return NotificationAudience::Admin === $this->audience
            ? $this->adminOpeningLine()
            : $this->openingLine();
    }

    /**
     * Gets the subject line the customer's copy would use unfiltered.
     *
     * @since 1.0.0
     *
     * @return string The default subject.
     */
    abstract protected function defaultSubject(): string;

    /**
     * Gets the first line of the customer's copy.
     *
     * @since 1.0.0
     *
     * @return string The opening line.
     */
    abstract protected function openingLine(): string;

    /**
     * Gets the subject line the staff copy would use unfiltered.
     *
     * Overridden by each lifecycle message. The base falls through to the
     * customer's wording rather than throwing, so a notification a consuming
     * application has subclassed keeps working when a later version of this
     * package starts asking for a staff variant it was written before.
     *
     * @since 1.0.0
     *
     * @return string The staff subject.
     */
    protected function adminSubject(): string
    {
        return $this->defaultSubject();
    }

    /**
     * Gets the first line of the staff copy.
     *
     * @since 1.0.0
     *
     * @return string The staff opening line.
     */
    protected function adminOpeningLine(): string
    {
        return $this->openingLine();
    }

    /**
     * Gets the salutation this copy opens with.
     *
     * @since 1.0.0
     *
     * @return string The greeting.
     */
    protected function greeting(): string
    {
        if ( NotificationAudience::Admin === $this->audience ) {
            return __( 'Hello,' );
        }

        return __( 'Hello :name,', [ 'name' => $this->booking->customer_name ] );
    }

    /**
     * Gets the appointment details as labelled rows.
     *
     * The staff copy carries the customer's name and contact details, because
     * the whole point of it is that somebody can act on the booking, and it
     * names the customer's zone alongside the provider's so that a phone call
     * at what the diary calls nine in the morning is not made to somebody
     * asleep.
     *
     * That makes the staff copy the first message in the package to compose a
     * customer's email address and phone number into a body, which is a note
     * for whoever builds the channel that delivers it: the erasure guard lives
     * on the channel, not here. {@see Channels\MailChannel::supports()} and
     * {@see Channels\SmsChannel::supports()} both refuse a booking whose
     * personal data has been erased, and a staff mail channel has to do the
     * same — this method will render whatever the columns hold.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The details, keyed by label.
     */
    protected function detailRows(): array
    {
        $rows = [
            (string) __( 'Reference' ) => (string) $this->booking->booking_number,
            (string) __( 'When' )      => $this->formattedStart(),
        ];

        $service = $this->booking->service;

        if ( null !== $service ) {
            $rows[ (string) __( 'Service' ) ] = (string) $service->name;
        }

        $provider = $this->booking->provider;

        if ( null !== $provider ) {
            $rows[ (string) __( 'With' ) ] = (string) $provider->name;
        }

        if ( NotificationAudience::Admin !== $this->audience ) {
            return $rows;
        }

        $rows[ (string) __( 'Customer' ) ] = (string) $this->booking->customer_name;

        $email = trim( (string) $this->booking->customer_email );

        if ( '' !== $email ) {
            $rows[ (string) __( 'Email' ) ] = $email;
        }

        $phone = trim( (string) $this->booking->customer_phone );

        if ( '' !== $phone ) {
            $rows[ (string) __( 'Phone' ) ] = $phone;
        }

        $rows[ (string) __( 'Customer time' ) ] = $this->format( $this->booking->startTimeForCustomer() );

        return $rows;
    }

    /**
     * Gets the appointment detail lines shown under the opening line.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The detail lines.
     */
    protected function detailLines(): array
    {
        $lines = [];

        foreach ( $this->detailRows() as $label => $value ) {
            $lines[] = $label . ': ' . $value;
        }

        return $lines;
    }

    /**
     * Gets the start time in whichever zone this audience reads it in.
     *
     * @since 1.0.0
     *
     * @return Carbon The start, localised for this audience.
     */
    protected function localStart(): Carbon
    {
        return NotificationAudience::Admin === $this->audience
            ? $this->booking->startTimeForProvider()
            : $this->booking->startTimeForCustomer();
    }

    /**
     * Renders the start time in whichever zone this audience reads it in.
     *
     * @since 1.0.0
     *
     * @return string The formatted local start, with its zone named.
     */
    protected function formattedStart(): string
    {
        return $this->format( $this->localStart() );
    }

    /**
     * Renders one moment the way every message in the package renders one.
     *
     * @since 1.0.0
     *
     * @param  Carbon  $moment  The moment to render, already in its display zone.
     *
     * @return string The formatted moment, with its zone named.
     */
    protected function format( Carbon $moment ): string
    {
        return $moment->format( 'l, j F Y \a\t H:i' ) . ' (' . $moment->format( 'T' ) . ')';
    }

    /**
     * Builds the self-serve link the customer manages their booking with.
     *
     * Only ever built for the customer, and only for the message that carries
     * the token they were issued with. The token *is* the credential — anybody
     * holding it can cancel or move the appointment — so it does not go into a
     * staff copy, where it would sit in a mailbox the customer never agreed to
     * put it in, and it is not manufactured for a later message: the plain value
     * exists once, on the instance that minted it, and a link built by reissuing
     * would break the one already in the customer's inbox.
     *
     * The address itself comes from `artisanpack.bookings.public.manage_url`,
     * because the package cannot know it. The self-serve page is a Livewire
     * component the application mounts on a route of its own choosing, and the
     * package's own `manage/{token}` endpoint answers JSON — a fine API and a
     * useless thing to put in an email. Left unset, no link is rendered, which
     * is the honest outcome: a link to a page that does not exist is worse than
     * a message that tells the customer to get in touch.
     *
     * @since 1.0.0
     *
     * @return string|null The manage URL, or null when there is none to give.
     */
    protected function manageUrl(): ?string
    {
        if ( NotificationAudience::Customer !== $this->audience ) {
            return null;
        }

        $template = trim( (string) config( 'artisanpack.bookings.public.manage_url', '' ) );

        if ( '' === $template || ! str_contains( $template, '{token}' ) ) {
            return null;
        }

        $token = $this->manageToken();

        if ( null === $token ) {
            return null;
        }

        return str_replace( '{token}', $token, $template );
    }

    /**
     * Takes the plain manage token off the booking, once.
     *
     * @since 1.0.0
     *
     * @return string|null The plain token, or null when the booking has none.
     */
    protected function manageToken(): ?string
    {
        if ( ! $this->manageTokenTaken ) {
            $this->manageTokenTaken = true;
            $this->manageToken      = $this->booking->pullPlainManageToken();
        }

        return $this->manageToken;
    }
}
