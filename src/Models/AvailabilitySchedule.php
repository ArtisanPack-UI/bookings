<?php

/**
 * Availability schedule model.
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

use ArtisanPackUI\Bookings\Casts\WallClockTime;
use ArtisanPackUI\Bookings\Database\Factories\AvailabilityScheduleFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

use function sprintf;

/**
 * A provider's recurring weekly hours.
 *
 * `day_of_week` is Sunday-indexed (0–6) to match Carbon's `dayOfWeek`.
 *
 * The times are local wall-clock in the provider's own timezone and are never
 * stored as UTC. That is what lets a row survive a daylight-saving changeover:
 * "09:00 local, whatever the offset happens to be that day" keeps meaning the
 * same thing, where a UTC-normalised 13:00 would silently become 08:00 local
 * every spring. {@see self::startsAtOn()} is where the clock face and a date
 * finally become an instant.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int $provider_id
 * @property int $day_of_week
 * @property string $start_time_local
 * @property string $end_time_local
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_until
 * @property bool $is_available
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AvailabilitySchedule extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'provider_id',
        'day_of_week',
        'start_time_local',
        'end_time_local',
        'effective_from',
        'effective_until',
        'is_available',
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
        'day_of_week'      => 'integer',
        'start_time_local' => WallClockTime::class,
        'end_time_local'   => WallClockTime::class,
        'effective_from'   => 'date',
        'effective_until'  => 'date',
        'is_available'     => 'boolean',
    ];

    /**
     * Gets the provider these hours belong to.
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
     * Scopes a query to the rows in force for a provider on a given weekday.
     *
     * The effective window is what makes this more than a two-column filter. A
     * schedule row can be dated — "these are my hours from September" — and a
     * query that ignored `effective_from` and `effective_until` would happily
     * return next term's timetable to somebody booking today.
     *
     * A null bound is open-ended in that direction, which is the normal case:
     * most providers have hours that simply apply.
     *
     * Both bounds are compared against the ends of the day rather than against
     * a bare date string. Eloquent writes a `date` cast through the connection's
     * date format, so the stored value is `2026-09-01 00:00:00` — and on SQLite,
     * where the column is text, `'2026-09-01 00:00:00' <= '2026-09-01'` is false.
     * A schedule would silently not apply on the very day it came into force.
     *
     * @since 1.0.0
     *
     * @param  Builder<AvailabilitySchedule>  $query  The query being built.
     * @param  int|ServiceProvider  $provider  The provider whose hours are wanted.
     * @param  int  $dayOfWeek  The Sunday-indexed weekday, 0–6.
     * @param  DateTimeInterface|string|null  $on  The date the hours must be in
     *                                             force on. Defaults to today.
     *
     * @return Builder<AvailabilitySchedule> The scoped query.
     */
    public function scopeFor(
        Builder $query,
        ServiceProvider|int $provider,
        int $dayOfWeek,
        DateTimeInterface|string|null $on = null,
    ): Builder {
        // "Today" belongs to the provider, not to the server. At 00:30 UTC a
        // provider in Los Angeles is still on yesterday, and answering with the
        // application's date would consult the wrong day's effective window.
        // Only a model can say which zone that is; given a bare id there is
        // nothing to ask, so the application default stands and the caller who
        // cares passes `$on` explicitly.
        $day = null !== $on
            ? Carbon::parse( $on )
            : Carbon::now( $provider instanceof ServiceProvider ? $provider->timezone : null );
        $dayStart = $day->copy()->startOfDay();
        $dayEnd   = $day->copy()->endOfDay();

        return $query
            ->where(
                $this->qualifyColumn( 'provider_id' ),
                $provider instanceof ServiceProvider ? $provider->getKey() : $provider,
            )
            ->where( $this->qualifyColumn( 'day_of_week' ), $dayOfWeek )
            ->where( function ( Builder $window ) use ( $dayEnd ): void {
                $window
                    ->whereNull( $this->qualifyColumn( 'effective_from' ) )
                    ->orWhere( $this->qualifyColumn( 'effective_from' ), '<=', $dayEnd );
            } )
            ->where( function ( Builder $window ) use ( $dayStart ): void {
                $window
                    ->whereNull( $this->qualifyColumn( 'effective_until' ) )
                    ->orWhere( $this->qualifyColumn( 'effective_until' ), '>=', $dayStart );
            } );
    }

    /**
     * Scopes a query to the rows that actually make a provider available.
     *
     * @since 1.0.0
     *
     * @param  Builder<AvailabilitySchedule>  $query  The query being built.
     *
     * @return Builder<AvailabilitySchedule> The scoped query.
     */
    public function scopeAvailable( Builder $query ): Builder
    {
        return $query->where( $this->qualifyColumn( 'is_available' ), true );
    }

    /**
     * Gets the instant this window opens on a given date.
     *
     * The date supplies the day, the row supplies the clock face, and the
     * provider's timezone supplies the offset — which is the whole reason the
     * offset is not stored. On the day the clocks go forward, 09:00 is an hour
     * earlier in UTC than it was the day before, and this returns exactly that,
     * where a stored UTC time would have quietly slipped to 08:00 local.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $date  The date the window falls on.
     * @param  string|null  $timezone  The zone to read the clock face in.
     *                                 Defaults to the provider's own.
     *
     * @return Carbon The moment the window opens.
     */
    public function startsAtOn( DateTimeInterface|string $date, ?string $timezone = null ): Carbon
    {
        return $this->composeInstant( $date, $this->start_time_local, $timezone );
    }

    /**
     * Gets the instant this window closes on a given date.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $date  The date the window falls on.
     * @param  string|null  $timezone  The zone to read the clock face in.
     *                                 Defaults to the provider's own.
     *
     * @return Carbon The moment the window closes.
     */
    public function endsAtOn( DateTimeInterface|string $date, ?string $timezone = null ): Carbon
    {
        return $this->composeInstant( $date, $this->end_time_local, $timezone );
    }

    /**
     * Determines whether this row is in force on a given date.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $date  The date being checked.
     *
     * @return bool True when the date falls inside the effective window.
     */
    public function isEffectiveOn( DateTimeInterface|string $date ): bool
    {
        $day = Carbon::parse( $date )->startOfDay();

        if ( null !== $this->effective_from && $day->lt( $this->effective_from ) ) {
            return false;
        }

        return null === $this->effective_until || $day->lte( $this->effective_until );
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return AvailabilityScheduleFactory The factory instance.
     */
    protected static function newFactory(): AvailabilityScheduleFactory
    {
        return AvailabilityScheduleFactory::new();
    }

    /**
     * Combines a date, a wall-clock time, and a timezone into an instant.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $date  The date the time falls on.
     * @param  string  $wallClock  The `H:i:s` time to apply.
     * @param  string|null  $timezone  The zone to read the time in, or null to
     *                                 use the provider's.
     *
     * @throws RuntimeException When no timezone is given and the provider that
     *                          would supply one cannot be loaded, or when the
     *                          stored value is not a time of day.
     *
     * @return Carbon The composed instant.
     */
    protected function composeInstant( DateTimeInterface|string $date, string $wallClock, ?string $timezone ): Carbon
    {
        $timezone ??= $this->provider?->timezone;

        if ( null === $timezone ) {
            throw new RuntimeException( sprintf(
                'Availability schedule %s has no provider to read its wall-clock times against.',
                (string) $this->getKey(),
            ) );
        }

        // The cast hands back a value it could not read, so that a corrupt row
        // stays loadable and repairable. Composing an instant is where that has
        // to stop: Carbon does not object to `38:00:00`, it rolls into the next
        // day, and the result is a window silently reported on the wrong date.
        if ( ! WallClockTime::isWallClock( $wallClock ) ) {
            throw new RuntimeException( sprintf(
                'Availability schedule %s holds "%s", which is not a time of day.',
                (string) $this->getKey(),
                $wallClock,
            ) );
        }

        $instant = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            Carbon::parse( $date )->toDateString() . ' ' . $wallClock,
            $timezone,
        );

        // Carbon does not object to a local time that never happened. On the
        // morning the clocks go forward, 02:30 does not exist in Chicago, and
        // createFromFormat() quietly hands back 03:30 — a window an hour from
        // where the row says it is, on the one day of the year this whole
        // wall-clock design exists to get right. Reading the clock face back is
        // how that stops being silent.
        if ( $instant->format( 'H:i:s' ) !== $wallClock ) {
            throw new RuntimeException( sprintf(
                'Availability schedule %s names %s on %s, a local time that does not exist in %s: the clocks go forward over it.',
                (string) $this->getKey(),
                $wallClock,
                $instant->toDateString(),
                $timezone,
            ) );
        }

        return $instant;
    }
}
