<?php

/**
 * Webhook model.
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

use ArtisanPackUI\Bookings\Database\Factories\WebhookFactory;
use ArtisanPackUI\Bookings\Events\WebhookDisabled;
use ArtisanPackUI\Bookings\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

use function in_array;
use function is_array;

/**
 * An outbound webhook endpoint.
 *
 * `events` holds the booking events the endpoint has subscribed to, and
 * `secret` is the key its payload signatures are computed with.
 *
 * An endpoint that fails often enough is disabled rather than retried forever:
 * a dead endpoint should not keep a queue busy indefinitely.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int|string|null $site_id
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property array<int, string> $events
 * @property bool $is_active
 * @property int $consecutive_failure_count
 * @property \Illuminate\Support\Carbon|null $disabled_at
 * @property \Illuminate\Support\Carbon|null $last_success_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Webhook extends Model
{
    use BelongsToSite;
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'booking_webhooks';

    /**
     * The attributes that are mass assignable.
     *
     * `disabled_at` is absent: disabling is a transition with an event attached
     * to it, not an attribute somebody sets. See {@see self::disable()}.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'consecutive_failure_count',
        'last_success_at',
    ];

    /**
     * The attributes hidden from array and JSON output.
     *
     * The signing secret is the one thing on this row a consumer must not be
     * able to read back out of an admin API response — knowing it is what lets
     * somebody forge a payload the endpoint will trust.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $hidden = [ 'secret' ];

    /**
     * Gets the delivery attempts made to this endpoint.
     *
     * @since 1.0.0
     *
     * @return HasMany<WebhookDelivery, $this> The deliveries relationship.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany( WebhookDelivery::class, 'webhook_id' );
    }

    /**
     * Stops delivering to this endpoint.
     *
     * Idempotent against a row that already reads disabled: it is left alone and
     * no second event is fired, so a failure sweep that runs twice does not tell
     * the consumer about the same outage twice. See
     * {@see CalendarConnection::disable()} for the limit of that guard — it
     * settles a repeat, not two processes racing on one row.
     *
     * @since 1.0.0
     *
     * @param  string  $reason  Why the endpoint is being disabled.
     *
     * @return bool True when this call disabled the endpoint.
     */
    public function disable( string $reason ): bool
    {
        if ( $this->isDisabled() ) {
            return false;
        }

        // Kept so the instance can be put back exactly as it was found if the
        // write does not happen. forceFill() mutates in memory first, and a
        // caller retrying on the same object would otherwise hit the
        // already-disabled guard and read "nothing to do" from what was really
        // a failed write, with the row still active in the database.
        $unchanged = $this->getAttributes();

        $this->forceFill( [
            'disabled_at' => $this->freshTimestamp(),
            'is_active'   => false,
        ] );

        // The event only follows a write that actually happened. A `saving`
        // listener returning false leaves the row reading active, and
        // announcing an outage that did not get recorded would have the next
        // sweep disable and announce the very same one again.
        if ( ! $this->save() ) {
            $this->setRawAttributes( $unchanged );

            return false;
        }

        WebhookDisabled::dispatch( $this, $reason );

        return true;
    }

    /**
     * Determines whether this endpoint has been disabled.
     *
     * @since 1.0.0
     *
     * @return bool True when nothing further will be delivered to it.
     */
    public function isDisabled(): bool
    {
        return null !== $this->disabled_at;
    }

    /**
     * Determines whether this endpoint has subscribed to a given event.
     *
     * Matched in PHP rather than in SQL. A JSON containment query is spelled
     * three different ways across the engines this package supports, and the
     * subscription list on one endpoint is short enough that filtering a loaded
     * collection costs nothing worth having a portability problem over.
     *
     * @since 1.0.0
     *
     * @param  string  $event  The event name to check for.
     *
     * @return bool True when the endpoint wants that event.
     */
    public function subscribesTo( string $event ): bool
    {
        return is_array( $this->events ) && in_array( $event, $this->events, true );
    }

    /**
     * Scopes a query to the endpoints still being delivered to.
     *
     * @since 1.0.0
     *
     * @param  Builder<Webhook>  $query  The query being built.
     *
     * @return Builder<Webhook> The scoped query.
     */
    public function scopeActive( Builder $query ): Builder
    {
        return $query
            ->where( $this->qualifyColumn( 'is_active' ), true )
            ->whereNull( $this->qualifyColumn( 'disabled_at' ) );
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return WebhookFactory The factory instance.
     */
    protected static function newFactory(): WebhookFactory
    {
        return WebhookFactory::new();
    }

    /**
     * Gets the attributes that should be cast.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The cast definitions.
     */
    protected function casts(): array
    {
        return [
            'events'                    => 'array',
            'is_active'                 => 'boolean',
            'consecutive_failure_count' => 'integer',
            'disabled_at'               => 'datetime',
            'last_success_at'           => 'datetime',
        ];
    }
}
