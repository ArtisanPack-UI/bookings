<?php

/**
 * UTC date-time cast.
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
use Illuminate\Support\Carbon;

/**
 * Casts a `DATETIME` column that holds a point in time stored in UTC.
 *
 * These columns are instants, written in UTC and meant to read back as the same
 * instant whatever the host application's `app.timezone` is. Laravel's plain
 * `'datetime'` cast cannot promise that: it parses a bare `Y-m-d H:i:s` through
 * `date_default_timezone_get()`, which Laravel sets to `app.timezone` once at
 * boot, so on any installation whose zone is not UTC the stored digits hydrate
 * reinterpreted in the local zone — the same clock face at the wrong instant,
 * shifted by the offset.
 *
 * This cast pins the zone instead of inheriting it. A value read from the column
 * is always parsed as UTC, and a value on its way in is always converted to UTC
 * before it is formatted, so the round trip is symmetric and the instant a
 * caller wrote is the instant a caller reads back — on every installation, in
 * every zone.
 *
 * A zone-aware value handed in keeps its instant: a `DateTimeInterface` already
 * names one, so it is converted to UTC rather than reinterpreted, and only its
 * clock face changes.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @implements CastsAttributes<Carbon|null, DateTimeInterface|int|string|null>
 */
class UtcDateTime implements CastsAttributes
{
    /**
     * The format the column is written in.
     *
     * @since 1.0.0
     *
     * @var string
     */
    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * Hydrates the stored value as a Carbon in UTC.
     *
     * @since 1.0.0
     *
     * @param  Model  $model  The model the attribute belongs to.
     * @param  string  $key  The attribute name.
     * @param  mixed  $value  The raw value from the database.
     * @param  array<string, mixed>  $attributes  The model's raw attributes.
     *
     * @return Carbon|null The instant in UTC, or null when unset.
     */
    public function get( Model $model, string $key, mixed $value, array $attributes ): ?Carbon
    {
        if ( null === $value || '' === $value ) {
            return null;
        }

        return $this->toUtc( $value );
    }

    /**
     * Prepares a value for storage as UTC digits.
     *
     * @since 1.0.0
     *
     * @param  Model  $model  The model the attribute belongs to.
     * @param  string  $key  The attribute name.
     * @param  mixed  $value  The value being set.
     * @param  array<string, mixed>  $attributes  The model's raw attributes.
     *
     * @return string|null The instant as `Y-m-d H:i:s` in UTC, or null when unset.
     */
    public function set( Model $model, string $key, mixed $value, array $attributes ): ?string
    {
        if ( null === $value || '' === $value ) {
            return null;
        }

        return $this->toUtc( $value )->format( self::FORMAT );
    }

    /**
     * Resolves any accepted representation to a Carbon in UTC.
     *
     * A `DateTimeInterface` names an instant already, so it is converted rather
     * than reinterpreted — its offset is honoured and only the clock face moves.
     * A numeric value is a Unix timestamp, which is an instant by definition. A
     * bare string carries no zone, so it is read as UTC, which is the contract
     * these columns are written under.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The value to resolve.
     *
     * @return Carbon The instant in UTC.
     */
    private function toUtc( mixed $value ): Carbon
    {
        if ( $value instanceof DateTimeInterface ) {
            return Carbon::instance( $value )->utc();
        }

        if ( is_numeric( $value ) ) {
            return Carbon::createFromTimestamp( (int) $value, 'UTC' );
        }

        return Carbon::parse( (string) $value, 'UTC' )->utc();
    }
}
