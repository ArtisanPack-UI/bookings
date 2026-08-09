<?php

/**
 * Calendar busy block model.
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

use ArtisanPackUI\Bookings\Database\Factories\CalendarBusyBlockFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A span of time an external calendar reports the provider as unavailable.
 *
 * The inbound half of two-way sync, projected down to the only thing
 * availability cares about. Nothing about the external event is stored beyond
 * its identity, its bounds, and its etag — a busy block is a fact about time,
 * not a copy of somebody's private calendar entry.
 *
 * Times are UTC, matching bookings, so the availability query can compare them
 * without converting anything. The span is half-open — `[starts_at_utc,
 * ends_at_utc)` — so a block ending at 10:00 leaves 10:00 bookable.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int $connection_id
 * @property string $external_event_id
 * @property Carbon $starts_at_utc
 * @property Carbon $ends_at_utc
 * @property string|null $etag
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CalendarBusyBlock extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'booking_calendar_busy_blocks';

    /**
     * The attributes that are mass assignable.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'connection_id',
        'external_event_id',
        'starts_at_utc',
        'ends_at_utc',
        'etag',
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
        'starts_at_utc' => 'datetime',
        'ends_at_utc'   => 'datetime',
    ];

    /**
     * Gets the calendar this block came from.
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
     * Scopes a query to the blocks overlapping a window on given connections.
     *
     * The clauses are ordered to match `busy_blocks_connection_range_index`:
     * the connection is an equality on the leading column, and the upper bound
     * on `starts_at_utc` follows it as a range on the second, which is as far
     * as a composite index can be used. The `ends_at_utc` predicate is the
     * residual filter.
     *
     * Both comparisons are strict, because the span is half-open. A block
     * ending exactly when the window opens does not overlap it, and neither
     * does one starting exactly when the window closes — treating either as a
     * clash would lose a bookable slot at every boundary in the day.
     *
     * @since 1.0.0
     *
     * @param  Builder<CalendarBusyBlock>  $query  The query being built.
     * @param  array<int, int>|int  $connectionIds  The connection or connections to search.
     * @param  DateTimeInterface|string  $start  The start of the window.
     * @param  DateTimeInterface|string  $end  The end of the window.
     *
     * @return Builder<CalendarBusyBlock> The scoped query.
     */
    public function scopeOverlapping(
        Builder $query,
        array|int $connectionIds,
        DateTimeInterface|string $start,
        DateTimeInterface|string $end,
    ): Builder {
        return $query
            ->whereIn( $this->qualifyColumn( 'connection_id' ), (array) $connectionIds )
            ->where( $this->qualifyColumn( 'starts_at_utc' ), '<', self::asUtc( $end ) )
            ->where( $this->qualifyColumn( 'ends_at_utc' ), '>', self::asUtc( $start ) );
    }

    /**
     * Determines whether this block clashes with a given window.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $start  The start of the window.
     * @param  DateTimeInterface|string  $end  The end of the window.
     *
     * @return bool True when the two spans overlap.
     */
    public function overlaps( DateTimeInterface|string $start, DateTimeInterface|string $end ): bool
    {
        return $this->starts_at_utc->lt( self::asUtc( $end ) )
            && $this->ends_at_utc->gt( self::asUtc( $start ) );
    }

    /**
     * Reads a window bound as the UTC instant the columns are stored in.
     *
     * The bare string `2026-06-01 09:00:00` is the shape a caller naturally
     * reaches for, and `Carbon::parse()` would read it in the application's
     * timezone — which is not what these columns hold. On an application
     * configured to anything but UTC that shifts the whole window, and an
     * availability lookup quietly answers about the wrong hours. A value that
     * already carries a zone is converted rather than reinterpreted.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $value  The bound to read.
     *
     * @return Carbon The same moment, in UTC.
     */
    protected static function asUtc( DateTimeInterface|string $value ): Carbon
    {
        return $value instanceof DateTimeInterface
            ? Carbon::instance( $value )->utc()
            : Carbon::parse( $value, 'UTC' );
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return CalendarBusyBlockFactory The factory instance.
     */
    protected static function newFactory(): CalendarBusyBlockFactory
    {
        return CalendarBusyBlockFactory::new();
    }
}
