<?php

/**
 * Wall-clock time cast.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a `TIME` column to a plain `H:i:s` string and back.
 *
 * Availability is authored as local wall-clock time in a provider's own
 * timezone and is deliberately never normalised to UTC, so these columns must
 * not become date-times. A Carbon instance would carry a date and an offset the
 * column does not have, and the moment either got applied the value would stop
 * meaning "nine in the morning, whatever the offset is that day" and start
 * meaning a fixed instant — which is exactly the bug that makes a schedule jump
 * an hour every spring.
 *
 * A string is therefore the honest representation. Turning one into a real
 * instant needs a date and a zone, which only the caller has; see
 * {@see \ArtisanPackUI\Bookings\Models\AvailabilitySchedule::startsAtOn()}.
 *
 * The cast exists to normalise, because the engines do not agree: MySQL and
 * Postgres hand back `09:00:00`, SQLite hands back whatever was written to it.
 * Everything that reads one of these columns can rely on `H:i:s`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @implements CastsAttributes<string|null, DateTimeInterface|string|null>
 */
class WallClockTime implements CastsAttributes
{
    /**
     * The shape a wall-clock time has to have.
     *
     * Hours stop at 23 deliberately. MySQL's `TIME` type accepts a much wider
     * range — it models durations as well as clock faces — and a value outside a
     * day is not a time of day whatever the column is willing to hold.
     *
     * @since 1.0.0
     *
     * @var string
     */
    private const PATTERN = '/^([01]\d|2[0-3]|\d):([0-5]\d)(?::([0-5]\d))?$/';

    /**
     * Determines whether a value can be read as a wall-clock time.
     *
     * Public because {@see self::get()} deliberately hands back values it could
     * not parse, so anything about to *use* one — to compose an instant out of
     * it, say — has to be able to ask first. Carbon will not ask on its own:
     * given `38:00:00` it rolls into the next day and reports no problem at all.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The value to test.
     *
     * @return bool True when the value is a readable `H:i` or `H:i:s`.
     */
    public static function isWallClock( mixed $value ): bool
    {
        return is_string( $value ) && 1 === preg_match( self::PATTERN, trim( $value ) );
    }

    /**
     * Casts the stored value to a normalised wall-clock string.
     *
     * Reading never throws. A value this cast cannot parse is a row somebody has
     * to look at and repair — written by an import, or a MySQL `TIME` holding
     * one of the out-of-range values that column type allows — and refusing to
     * hydrate it would take the screen that shows it down with it, leaving no
     * way to reach the offending row at all. The rule belongs on writes, where
     * {@see self::set()} still enforces it; a read hands back what is actually
     * in the column and lets the caller see the problem.
     *
     * @since 1.0.0
     *
     * @param  Model  $model  The model the attribute belongs to.
     * @param  string  $key  The attribute name.
     * @param  mixed  $value  The raw value from the database.
     * @param  array<string, mixed>  $attributes  The model's raw attributes.
     *
     * @return string|null The time as `H:i:s`, the raw value when it cannot be
     *                     read as one, or null when unset.
     */
    public function get( Model $model, string $key, mixed $value, array $attributes ): ?string
    {
        try {
            return $this->normalize( $value, $key );
        } catch ( InvalidArgumentException ) {
            return is_string( $value ) ? $value : null;
        }
    }

    /**
     * Prepares a wall-clock value for storage.
     *
     * @since 1.0.0
     *
     * @param  Model  $model  The model the attribute belongs to.
     * @param  string  $key  The attribute name.
     * @param  mixed  $value  The value being set.
     * @param  array<string, mixed>  $attributes  The model's raw attributes.
     *
     * @return string|null The time as `H:i:s`, or null when unset.
     */
    public function set( Model $model, string $key, mixed $value, array $attributes ): ?string
    {
        return $this->normalize( $value, $key );
    }

    /**
     * Reduces any accepted representation to `H:i:s`.
     *
     * A `DateTimeInterface` is accepted for convenience and immediately stripped
     * of its date and offset — only the clock face survives, which is the whole
     * point of the column.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The value to normalise.
     * @param  string  $key  The attribute name, used in the failure message.
     *
     * @throws InvalidArgumentException When the value is not a readable time.
     *
     * @return string|null The time as `H:i:s`, or null when unset.
     */
    private function normalize( mixed $value, string $key ): ?string
    {
        if ( null === $value || '' === $value ) {
            return null;
        }

        if ( $value instanceof DateTimeInterface ) {
            return $value->format( 'H:i:s' );
        }

        if ( is_string( $value ) && preg_match( self::PATTERN, trim( $value ), $parts ) ) {
            return sprintf( '%02d:%02d:%02d', (int) $parts[1], (int) $parts[2], (int) ( $parts[3] ?? 0 ) );
        }

        throw new InvalidArgumentException( sprintf(
            'The "%s" attribute must be a wall-clock time such as "09:00" or "09:00:00".',
            $key,
        ) );
    }
}
