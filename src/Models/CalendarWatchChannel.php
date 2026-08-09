<?php

/**
 * Calendar watch channel model.
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

use ArtisanPackUI\Bookings\Database\Factories\CalendarWatchChannelFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A push notification registration for two-way calendar sync.
 *
 * A Google watch channel or a Microsoft 365 subscription. Both expire, and an
 * expired one fails silently — the calendar simply stops telling us anything —
 * which is why `expires_at` is indexed and swept by the renewal command rather
 * than being noticed when something breaks.
 *
 * Apple has no push mechanism, so an Apple connection has no row here and is
 * polled instead.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int $connection_id
 * @property string|null $channel_id
 * @property string|null $resource_id
 * @property string|null $subscription_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CalendarWatchChannel extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'booking_calendar_watch_channels';

    /**
     * The attributes that are mass assignable.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'connection_id',
        'channel_id',
        'resource_id',
        'subscription_id',
        'expires_at',
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
        'expires_at' => 'datetime',
    ];

    /**
     * Gets the calendar connection this registration watches.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<CalendarConnection, $this> The connection relationship.
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo( CalendarConnection::class, 'connection_id' );
    }

    /**
     * Determines whether this registration has lapsed.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string|null  $at  The moment to judge it at.
     *                                             Defaults to now.
     *
     * @return bool True when the channel is no longer delivering notifications.
     */
    public function hasExpired( DateTimeInterface|string|null $at = null ): bool
    {
        return $this->expires_at->lte( Carbon::parse( $at ?? Carbon::now() ) );
    }

    /**
     * Determines whether this registration lapses within a given number of minutes.
     *
     * @since 1.0.0
     *
     * @param  int  $minutes  How far ahead to look.
     *
     * @return bool True when the channel needs renewing that soon.
     */
    public function expiresWithin( int $minutes ): bool
    {
        return $this->expires_at->lte( Carbon::now()->addMinutes( $minutes ) );
    }

    /**
     * Scopes a query to the registrations due for renewal.
     *
     * Already-expired channels are included. A lapsed channel is not something
     * to skip past — it is the one most urgently in need of replacing, because
     * the calendar behind it has already stopped reporting changes.
     *
     * @since 1.0.0
     *
     * @param  Builder<CalendarWatchChannel>  $query  The query being built.
     * @param  DateTimeInterface|string|null  $before  The horizon to renew up
     *                                                 to. Defaults to now.
     *
     * @return Builder<CalendarWatchChannel> The scoped query.
     */
    public function scopeExpiringBefore( Builder $query, DateTimeInterface|string|null $before = null ): Builder
    {
        return $query->where(
            $this->qualifyColumn( 'expires_at' ),
            '<=',
            Carbon::parse( $before ?? Carbon::now() ),
        );
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return CalendarWatchChannelFactory The factory instance.
     */
    protected static function newFactory(): CalendarWatchChannelFactory
    {
        return CalendarWatchChannelFactory::new();
    }
}
