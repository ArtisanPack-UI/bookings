<?php

/**
 * Availability override model.
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
use ArtisanPackUI\Bookings\Database\Factories\AvailabilityOverrideFactory;
use ArtisanPackUI\Bookings\Enums\AvailabilityOverrideType;
use ArtisanPackUI\Bookings\Exceptions\NonexistentLocalTimeException;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A single-date exception to a provider's weekly schedule.
 *
 * Either a day off or a day worked at hours other than the usual ones — which
 * of the two is stated by `type` rather than inferred, so reading a row does
 * not require knowing which meaning a boolean was carrying.
 *
 * Same wall-clock semantics as {@see AvailabilitySchedule}: the times are local
 * to the provider's timezone and are never stored as UTC.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int $provider_id
 * @property Carbon $date
 * @property AvailabilityOverrideType $type
 * @property string|null $start_time_local
 * @property string|null $end_time_local
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AvailabilityOverride extends Model
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
        'date',
        'type',
        'start_time_local',
        'end_time_local',
        'reason',
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
        'date'             => 'date',
        'type'             => AvailabilityOverrideType::class,
        'start_time_local' => WallClockTime::class,
        'end_time_local'   => WallClockTime::class,
    ];

    /**
     * Gets the provider this exception applies to.
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
     * Scopes a query to a provider's exceptions on a given date.
     *
     * Matched as a range over the day rather than with `whereDate()`. A stored
     * `date` cast carries a time component — Eloquent writes it through the
     * connection's date format — so an equality on the bare date would miss it
     * on SQLite, and wrapping the column in a date function would put the
     * `(provider_id, date)` index out of reach on the engines where it matters.
     *
     * @since 1.0.0
     *
     * @param  Builder<AvailabilityOverride>  $query  The query being built.
     * @param  int|ServiceProvider  $provider  The provider whose day is wanted.
     * @param  DateTimeInterface|string  $date  The date being checked.
     *
     * @return Builder<AvailabilityOverride> The scoped query.
     */
    public function scopeFor( Builder $query, ServiceProvider|int $provider, DateTimeInterface|string $date ): Builder
    {
        $day = Carbon::parse( $date );

        $query
            ->where(
                $this->qualifyColumn( 'provider_id' ),
                $provider instanceof ServiceProvider ? $provider->getKey() : $provider,
            )
            ->whereBetween( $this->qualifyColumn( 'date' ), [
                $day->copy()->startOfDay(),
                $day->copy()->endOfDay(),
            ] );

        return $query;
    }

    /**
     * Determines whether this exception closes the day entirely.
     *
     * @since 1.0.0
     *
     * @return bool True when the provider is unavailable all day.
     */
    public function isUnavailable(): bool
    {
        return AvailabilityOverrideType::Unavailable === $this->type;
    }

    /**
     * Determines whether this exception replaces the day's usual hours.
     *
     * @since 1.0.0
     *
     * @return bool True when the row carries a replacement window.
     */
    public function isCustomHours(): bool
    {
        return AvailabilityOverrideType::CustomHours === $this->type;
    }

    /**
     * Gets the instant the replacement window opens.
     *
     * @since 1.0.0
     *
     * @param  string|null  $timezone  The zone to read the clock face in.
     *                                 Defaults to the provider's own.
     *
     * @return Carbon|null The moment the window opens, or null on a day off.
     */
    public function startsAt( ?string $timezone = null ): ?Carbon
    {
        return null === $this->start_time_local
            ? null
            : $this->composeInstant( $this->start_time_local, $timezone );
    }

    /**
     * Gets the instant the replacement window closes.
     *
     * @since 1.0.0
     *
     * @param  string|null  $timezone  The zone to read the clock face in.
     *                                 Defaults to the provider's own.
     *
     * @return Carbon|null The moment the window closes, or null on a day off.
     */
    public function endsAt( ?string $timezone = null ): ?Carbon
    {
        return null === $this->end_time_local
            ? null
            : $this->composeInstant( $this->end_time_local, $timezone );
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return AvailabilityOverrideFactory The factory instance.
     */
    protected static function newFactory(): AvailabilityOverrideFactory
    {
        return AvailabilityOverrideFactory::new();
    }

    /**
     * Combines this row's date, a wall-clock time, and a timezone into an instant.
     *
     * @since 1.0.0
     *
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
    protected function composeInstant( string $wallClock, ?string $timezone ): Carbon
    {
        $timezone ??= $this->provider?->timezone;

        if ( null === $timezone ) {
            throw new RuntimeException( sprintf(
                'Availability override %s has no provider to read its wall-clock times against.',
                (string) $this->getKey(),
            ) );
        }

        // The cast hands back a value it could not read, so that a corrupt row
        // stays loadable and repairable. Composing an instant is where that has
        // to stop: Carbon does not object to `38:00:00`, it rolls into the next
        // day, and the result is a window silently reported on the wrong date.
        if ( ! WallClockTime::isWallClock( $wallClock ) ) {
            throw new RuntimeException( sprintf(
                'Availability override %s holds "%s", which is not a time of day.',
                (string) $this->getKey(),
                $wallClock,
            ) );
        }

        $instant = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $this->date->toDateString() . ' ' . $wallClock,
            $timezone,
        );

        // Carbon does not object to a local time that never happened. On the
        // morning the clocks go forward, 02:30 does not exist in Chicago, and
        // createFromFormat() quietly hands back 03:30 — a window an hour from
        // where the row says it is, on the one day of the year this whole
        // wall-clock design exists to get right. Reading the clock face back is
        // how that stops being silent.
        if ( $instant->format( 'H:i:s' ) !== $wallClock ) {
            throw new NonexistentLocalTimeException(
                sprintf(
                    'Availability override %s names %s on %s, a local time that does not exist in %s: the clocks go forward over it.',
                    (string) $this->getKey(),
                    $wallClock,
                    $instant->toDateString(),
                    $timezone,
                ),
                $instant,
            );
        }

        return $instant;
    }
}
