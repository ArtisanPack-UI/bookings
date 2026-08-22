<?php

/**
 * Webhook delivery model.
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

use ArtisanPackUI\Bookings\Database\Factories\WebhookDeliveryFactory;
use ArtisanPackUI\Bookings\Enums\WebhookDeliveryStatus;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to deliver an event to a webhook endpoint.
 *
 * Kept so a failed webhook can be retried on a backoff schedule, and so an
 * operator can see what was actually sent when a consumer claims it never
 * arrived.
 *
 * `payload` is a copy of the event body, which means it contains the customer's
 * name and email. Redacting inside stored JSON is guesswork, so this table is
 * retention-bound rather than erasure-bound: the payload is deleted, not
 * rewritten.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int $webhook_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property int $attempt_number
 * @property WebhookDeliveryStatus $status
 * @property int|null $response_status
 * @property string|null $response_body
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $attempted_at
 * @property Carbon|null $succeeded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookDelivery extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'booking_webhook_deliveries';

    /**
     * The attributes that are mass assignable.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'webhook_id',
        'event_type',
        'payload',
        'attempt_number',
        'status',
        'response_status',
        'response_body',
        'next_attempt_at',
        'attempted_at',
        'succeeded_at',
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
        'payload'         => 'array',
        'attempt_number'  => 'integer',
        'status'          => WebhookDeliveryStatus::class,
        'response_status' => 'integer',
        'next_attempt_at' => 'datetime',
        'attempted_at'    => 'datetime',
        'succeeded_at'    => 'datetime',
    ];

    /**
     * Gets the endpoint this delivery was addressed to.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Webhook, $this> The webhook relationship.
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo( Webhook::class, 'webhook_id' );
    }

    /**
     * Scopes a query to the deliveries the retry sweep should pick up now.
     *
     * A null `next_attempt_at` counts as due only while the delivery is still
     * pending. That is what one looks like between being queued and being
     * attempted for the first time, and a sweep that matched only a scheduled
     * time would leave every brand new delivery sitting there until something
     * else touched it.
     *
     * A failed delivery with no next attempt is a different thing: a row whose
     * backoff was never written. Treating that as due would have the sweep pick
     * it up on every pass with no delay between attempts — a retry storm aimed
     * at an endpoint that is already failing, which is the opposite of what a
     * backoff schedule is for. It waits until something sets a time.
     *
     * The status stays a top-level predicate rather than living inside both
     * branches of the OR. Written the other way the generated SQL has no
     * leading-column condition at all, and MySQL and Postgres stop being able to
     * scan `(status, next_attempt_at)` — the sweep asks one question, what is due
     * now, so the index has to be reachable the way the question is asked.
     *
     * @since 1.0.0
     *
     * @param  Builder<WebhookDelivery>  $query  The query being built.
     * @param  DateTimeInterface|string|null  $at  The moment to judge due-ness
     *                                             at. Defaults to now.
     *
     * @return Builder<WebhookDelivery> The scoped query.
     */
    public function scopeDue( Builder $query, DateTimeInterface|string|null $at = null ): Builder
    {
        $moment = Carbon::parse( $at ?? Carbon::now() );

        return $query
            ->whereIn( $this->qualifyColumn( 'status' ), [
                WebhookDeliveryStatus::Pending->value,
                WebhookDeliveryStatus::Failed->value,
            ] )
            ->where( function ( Builder $due ) use ( $moment ): void {
                $due
                    ->where( function ( Builder $queued ): void {
                        $queued
                            ->whereNull( $this->qualifyColumn( 'next_attempt_at' ) )
                            ->where(
                                $this->qualifyColumn( 'status' ),
                                WebhookDeliveryStatus::Pending->value,
                            );
                    } )
                    ->orWhere( $this->qualifyColumn( 'next_attempt_at' ), '<=', $moment );
            } );
    }

    /**
     * Determines whether this delivery will be attempted again.
     *
     * @since 1.0.0
     *
     * @return bool True when the retry sweep can still pick it up.
     */
    public function isRetryable(): bool
    {
        return $this->status->isRetryable();
    }

    /**
     * Claims this delivery for an attempt, leasing it against other producers.
     *
     * Two producers can hold the same still-due delivery — the retry sweep and
     * the in-flight job it re-dispatched, or two sweep passes — and without a
     * claim both would send the consumer the same event. This is the
     * compare-and-set that stops them: it moves `next_attempt_at` forward to a
     * lease only while the row is still due (the same predicate {@see scopeDue()}
     * matches), so exactly one caller sees the update affect a row. Whoever wins
     * attempts the delivery; a loser finds nothing updated and steps aside.
     *
     * The lease is a visibility timeout, not a lock: if the winner crashes mid
     * attempt the row simply falls due again once the lease elapses, and the next
     * sweep picks it up — which is the crash-safety the retry chain lacked when
     * the failing attempt was the only thing that re-queued the next one.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface  $leaseUntil  When the claim expires if the
     *                                         attempt does not complete.
     *
     * @return bool True when this caller won the claim.
     */
    public function claim( DateTimeInterface $leaseUntil ): bool
    {
        $claimed = 1 === static::query()
            ->whereKey( $this->getKey() )
            ->due()
            ->update( [ 'next_attempt_at' => $leaseUntil ] );

        if ( $claimed ) {
            $this->setAttribute( 'next_attempt_at', $leaseUntil )->syncOriginalAttribute( 'next_attempt_at' );
        }

        return $claimed;
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return WebhookDeliveryFactory The factory instance.
     */
    protected static function newFactory(): WebhookDeliveryFactory
    {
        return WebhookDeliveryFactory::new();
    }
}
