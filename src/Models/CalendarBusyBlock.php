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
            ->where( $this->qualifyColumn( 'starts_at_utc' ), '<', Carbon::parse( $end ) )
            ->where( $this->qualifyColumn( 'ends_at_utc' ), '>', Carbon::parse( $start ) );
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
        return $this->starts_at_utc->lt( Carbon::parse( $end ) )
            && $this->ends_at_utc->gt( Carbon::parse( $start ) );
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
            'starts_at_utc' => 'datetime',
            'ends_at_utc'   => 'datetime',
        ];
    }
}
