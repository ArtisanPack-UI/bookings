<?php

/**
 * Calendar connection model.
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

use ArtisanPackUI\Bookings\Database\Factories\CalendarConnectionFactory;
use ArtisanPackUI\Bookings\Enums\CalendarDriver;
use ArtisanPackUI\Bookings\Enums\CalendarSyncMode;
use ArtisanPackUI\Bookings\Events\CalendarConnectionDisabled;
use ArtisanPackUI\Bookings\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One external calendar a provider has connected.
 *
 * A provider can have several — a work Google calendar and a personal iCal
 * feed, say — which is why this is a table rather than a column on
 * {@see ServiceProvider}.
 *
 * `oauth_connection_id` points at a sibling OAuth package and carries no foreign
 * key: the driver packages are suggested, not required, so the table they own
 * may not exist.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int|string|null $site_id
 * @property int $provider_id
 * @property CalendarDriver $driver
 * @property string $external_calendar_id
 * @property int|null $oauth_connection_id
 * @property CalendarSyncMode $sync_mode
 * @property string|null $sync_token
 * @property \Illuminate\Support\Carbon|null $last_sync_at
 * @property string|null $last_sync_error
 * @property int $consecutive_failure_count
 * @property \Illuminate\Support\Carbon|null $disabled_at
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CalendarConnection extends Model
{
    use BelongsToSite;
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'booking_calendar_connections';

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
        'provider_id',
        'driver',
        'external_calendar_id',
        'oauth_connection_id',
        'sync_mode',
        'sync_token',
        'last_sync_at',
        'last_sync_error',
        'consecutive_failure_count',
        'is_active',
    ];

    /**
     * The attributes hidden from array and JSON output.
     *
     * The sync token is a credential the external system issued. It is not the
     * OAuth token, but it is still enough to resume somebody else's sync
     * cursor, and an admin API serialising a connection wholesale would hand it
     * to whoever could read the response.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $hidden = [ 'sync_token' ];

    /**
     * Gets the provider whose calendar this is.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<ServiceProvider, $this> The provider relationship.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo( ServiceProvider::class, 'provider_id' );
    }

    /**
     * Gets the outbound events written to this calendar.
     *
     * @since 1.0.0
     *
     * @return HasMany<CalendarEvent, $this> The events relationship.
     */
    public function events(): HasMany
    {
        return $this->hasMany( CalendarEvent::class, 'connection_id' );
    }

    /**
     * Gets the push notification registrations for this calendar.
     *
     * @since 1.0.0
     *
     * @return HasMany<CalendarWatchChannel, $this> The watch channels relationship.
     */
    public function watchChannels(): HasMany
    {
        return $this->hasMany( CalendarWatchChannel::class, 'connection_id' );
    }

    /**
     * Gets the spans of time this calendar reports the provider as busy.
     *
     * @since 1.0.0
     *
     * @return HasMany<CalendarBusyBlock, $this> The busy blocks relationship.
     */
    public function busyBlocks(): HasMany
    {
        return $this->hasMany( CalendarBusyBlock::class, 'connection_id' );
    }

    /**
     * Stops syncing this connection.
     *
     * The sync token goes with it. A token is a cursor into a change feed, and
     * resuming from a stale one after an unknown gap silently skips whatever
     * changed while the connection was off — a null token forces the next
     * enable to do a full sync, which is slower and correct.
     *
     * Idempotent against a row that already reads disabled: it is left alone and
     * no second event is fired, so a failure sweep that runs twice does not send
     * the operator two notifications about the same outage. That guard reads
     * `disabled_at` off this instance, so it settles a sweep repeating against
     * state it can see, not two processes racing on the same row — the second
     * would need `where disabled_at is null` in the update itself. Worth doing
     * if the sweep ever runs concurrently; today it does not.
     *
     * @since 1.0.0
     *
     * @param  string  $reason  Why the connection is being disabled.
     *
     * @return bool True when this call disabled the connection.
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
            'disabled_at'     => $this->freshTimestamp(),
            'is_active'       => false,
            'sync_token'      => null,
            'last_sync_error' => $reason,
        ] );

        // The event only follows a write that actually happened. A `saving`
        // listener returning false leaves the row reading active, and
        // announcing an outage that did not get recorded would have the next
        // sweep disable and announce the very same one again.
        if ( ! $this->save() ) {
            $this->setRawAttributes( $unchanged );

            return false;
        }

        CalendarConnectionDisabled::dispatch( $this, $reason );

        return true;
    }

    /**
     * Determines whether this connection has been disabled.
     *
     * @since 1.0.0
     *
     * @return bool True when the connection is no longer synced.
     */
    public function isDisabled(): bool
    {
        return null !== $this->disabled_at;
    }

    /**
     * Determines whether this connection can suppress availability.
     *
     * @since 1.0.0
     *
     * @return bool True when busy blocks are read back from the calendar.
     */
    public function readsBusyBlocks(): bool
    {
        return ! $this->isDisabled() && $this->is_active && $this->sync_mode->readsBusyBlocks();
    }

    /**
     * Scopes a query to the connections still being synced.
     *
     * @since 1.0.0
     *
     * @param  Builder<CalendarConnection>  $query  The query being built.
     *
     * @return Builder<CalendarConnection> The scoped query.
     */
    public function scopeActive( Builder $query ): Builder
    {
        return $query
            ->where( $this->qualifyColumn( 'is_active' ), true )
            ->whereNull( $this->qualifyColumn( 'disabled_at' ) );
    }

    /**
     * Scopes a query to the connections that read busy blocks back.
     *
     * @since 1.0.0
     *
     * @param  Builder<CalendarConnection>  $query  The query being built.
     *
     * @return Builder<CalendarConnection> The scoped query.
     */
    public function scopeTwoWay( Builder $query ): Builder
    {
        return $query
            ->active()
            ->where( $this->qualifyColumn( 'sync_mode' ), CalendarSyncMode::TwoWay->value );
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return CalendarConnectionFactory The factory instance.
     */
    protected static function newFactory(): CalendarConnectionFactory
    {
        return CalendarConnectionFactory::new();
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
            'driver'                    => CalendarDriver::class,
            'sync_mode'                 => CalendarSyncMode::class,
            'last_sync_at'              => 'datetime',
            'consecutive_failure_count' => 'integer',
            'disabled_at'               => 'datetime',
            'is_active'                 => 'boolean',
        ];
    }
}
