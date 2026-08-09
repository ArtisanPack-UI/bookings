<?php

/**
 * Calendar event model.
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

use ArtisanPackUI\Bookings\Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The outbound sync ledger: which booking became which event on which calendar.
 *
 * Without this row there is no way to update or delete the external copy when
 * the booking changes. `etag` is the external system's version marker, kept so
 * an update can be sent conditionally and a concurrent edit made in the
 * calendar itself is not silently overwritten.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int $booking_id
 * @property int $connection_id
 * @property string $external_event_id
 * @property string|null $etag
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property string|null $sync_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CalendarEvent extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'booking_calendar_events';

    /**
     * The attributes that are mass assignable.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'booking_id',
        'connection_id',
        'external_event_id',
        'etag',
        'last_synced_at',
        'sync_error',
    ];

    /**
     * Gets the booking this event mirrors.
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
     * Gets the calendar this event was written to.
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
     * Determines whether the last attempt to sync this event failed.
     *
     * @since 1.0.0
     *
     * @return bool True when an error is recorded against the event.
     */
    public function hasSyncError(): bool
    {
        return null !== $this->sync_error;
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return CalendarEventFactory The factory instance.
     */
    protected static function newFactory(): CalendarEventFactory
    {
        return CalendarEventFactory::new();
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
            'last_synced_at' => 'datetime',
        ];
    }
}
