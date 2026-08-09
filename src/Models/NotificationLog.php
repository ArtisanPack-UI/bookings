<?php

/**
 * Notification log model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Models;

use ArtisanPackUI\Bookings\Database\Factories\NotificationLogFactory;
use ArtisanPackUI\Bookings\Enums\NotificationStatus;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

use function date_default_timezone_get;

/**
 * A record of a notification sent about a booking.
 *
 * The unique key on `(booking_id, type, scheduled_for)` is doing real work: it
 * makes "send the 24-hour reminder for this booking" a claim on a row rather
 * than a decision made by reading. A cron that overlaps itself, a queue that
 * delivers a job twice, a retry after a timeout — all of them race to insert the
 * same key, exactly one wins, and the losers fail cleanly instead of sending a
 * customer the same reminder twice. {@see self::logSend()} is the front door to
 * that claim.
 *
 * **Erasure.** `recipient` is an email address or a phone number, so this table
 * holds customer PII without an erasure marker of its own. It does not need one:
 * every row is keyed to a booking, so erasing a booking means redacting
 * `recipient` on `booking_id = ?` in the same routine that redacts the booking.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int $booking_id
 * @property string $channel
 * @property NotificationType $type
 * @property string $recipient
 * @property Carbon|null $scheduled_for
 * @property NotificationStatus $status
 * @property string|null $error
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationLog extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'booking_notification_log';

    /**
     * The attributes that are mass assignable.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'booking_id',
        'channel',
        'type',
        'recipient',
        'scheduled_for',
        'status',
        'error',
        'sent_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * Declared as a property rather than through the `casts()` method Laravel 11
     * introduced. The method does not exist on Laravel 10, where it is not
     * overriding anything and is simply never called — so every cast on every
     * model would quietly do nothing, and a JSON column would come back as a
     * string with no error to notice. The property is read by every version the
     * package's constraints allow.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    protected $casts = [
        'type'          => NotificationType::class,
        'scheduled_for' => 'datetime',
        'status'        => NotificationStatus::class,
        'sent_at'       => 'datetime',
    ];

    /**
     * Claims the right to send one notification, or reports that somebody else has.
     *
     * The claim is the insert. Checking for an existing row and then writing one
     * is a race with a window in it; letting the unique index arbitrate has no
     * window at all, because the database decides. The loser gets null back and
     * is expected to do nothing — a duplicate reminder is a worse outcome than a
     * missed log line, and the winner has already sent it.
     *
     * A null `scheduled_for` is never deduplicated, because NULLs are distinct
     * in a unique index on every engine this package supports. That is the right
     * behaviour rather than an accident of the schema: two reschedules genuinely
     * warrant two emails.
     *
     * **The claim is per booking, type, and schedule — not per channel.** That is
     * the unique key the schema has, so claiming the 24-hour reminder for a
     * booking claims it once, and a second call for the same moment on a
     * different channel loses and returns null. A caller meaning to send the same
     * reminder by mail *and* SMS cannot express that through this method; doing
     * so needs `channel` in the unique key. Sending one reminder twice is the
     * failure this exists to prevent, so dropping the second channel is the safe
     * side to err on — but it is a real limit rather than a subtlety, and the
     * notifications work has to decide whether multi-channel sends are in scope
     * before anything depends on it.
     *
     * `$scheduledFor` is normalised to the application's own timezone before it
     * is written. Eloquent formats a date-time in whatever zone the Carbon it is
     * handed happens to carry, without converting, so two callers naming the same
     * instant in different zones — one working from `startTimeForCustomer()`, one
     * from the stored UTC `start_time` — would otherwise write two different
     * strings, collide with nothing, and send the customer two reminders. That is
     * the exact failure the unique index is here to stop, so the key has to be
     * the instant rather than however the caller happened to spell it.
     *
     * The insert runs in its own transaction so that a violation rolls back to a
     * savepoint rather than poisoning a transaction the caller opened. Postgres
     * aborts an entire transaction on any failed statement, so without this a
     * caught duplicate would take every query after it down too.
     *
     * @since 1.0.0
     *
     * @param  Booking|int  $booking  The booking being notified about.
     * @param  NotificationType|string  $type  Which lifecycle message this is.
     * @param  string  $channel  The channel it goes out on, such as `mail`.
     * @param  string  $recipient  The address or number it goes to.
     * @param  DateTimeInterface|string|null  $scheduledFor  When the send was
     *                                                       scheduled for, or
     *                                                       null if it was not.
     *
     * @return static|null The claimed log row, or null when another process
     *                     already claimed this send.
     */
    public static function logSend(
        Booking|int $booking,
        NotificationType|string $type,
        string $channel,
        string $recipient,
        DateTimeInterface|string|null $scheduledFor = null,
    ): ?static {
        $attributes = [
            'booking_id'    => $booking instanceof Booking ? $booking->getKey() : $booking,
            'channel'       => $channel,
            'type'          => $type instanceof NotificationType ? $type->value : $type,
            'recipient'     => $recipient,
            'scheduled_for' => null === $scheduledFor
                ? null
                : Carbon::parse( $scheduledFor )->setTimezone( date_default_timezone_get() ),
            'status'        => NotificationStatus::Pending->value,
        ];

        try {
            return static::query()->getConnection()->transaction(
                static fn () => static::query()->create( $attributes ),
            );
        } catch ( UniqueConstraintViolationException ) {
            // Losing is the normal case — a cron overlapping itself, a job
            // delivered twice — and it is not worth a warning. It is worth a
            // trace, because it is also what a caller sending the same
            // notification on two channels sees, and that failure would
            // otherwise be a silent no-op with nothing to search for.
            //
            // The recipient is deliberately absent: it is an email address or a
            // phone number, and this line goes to a log with a different
            // retention policy from the table it describes.
            Log::debug( 'A booking notification was already claimed.', [
                'booking_id'    => $attributes['booking_id'],
                'type'          => $attributes['type'],
                'channel'       => $attributes['channel'],
                'scheduled_for' => $attributes['scheduled_for']?->toIso8601String(),
            ] );

            return null;
        }
    }

    /**
     * Gets the booking this notification was about.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Booking, $this> The booking relationship.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo( Booking::class );
    }

    /**
     * Records that this notification went out.
     *
     * @since 1.0.0
     *
     * @return bool True when the row was updated.
     */
    public function markSent(): bool
    {
        return $this->forceFill( [
            'status'  => NotificationStatus::Sent,
            'sent_at' => $this->freshTimestamp(),
            'error'   => null,
        ] )->save();
    }

    /**
     * Records that this notification could not be sent.
     *
     * @since 1.0.0
     *
     * @param  string  $error  What went wrong.
     *
     * @return bool True when the row was updated.
     */
    public function markFailed( string $error ): bool
    {
        return $this->forceFill( [
            'status' => NotificationStatus::Failed,
            'error'  => $error,
        ] )->save();
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return NotificationLogFactory The factory instance.
     */
    protected static function newFactory(): NotificationLogFactory
    {
        return NotificationLogFactory::new();
    }
}
