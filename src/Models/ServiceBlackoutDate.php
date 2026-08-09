<?php

/**
 * Service blackout date model.
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

use ArtisanPackUI\Bookings\Database\Factories\ServiceBlackoutDateFactory;
use ArtisanPackUI\Bookings\Models\Concerns\BelongsToSite;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A date range during which a service cannot be booked.
 *
 * A null `service_id` closes every service on the site, which is how a holiday
 * or a whole-business shutdown is expressed. Both bounds are inclusive: a
 * blackout from the 24th to the 26th closes all three days.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int|string|null $site_id
 * @property int|null $service_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ServiceBlackoutDate extends Model
{
    use BelongsToSite;
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_id',
        'starts_on',
        'ends_on',
        'reason',
    ];

    /**
     * Gets the service this blackout closes, if it is not site-wide.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Service, $this> The service relationship.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo( Service::class );
    }

    /**
     * Scopes a query to the blackouts that close a given service on a date.
     *
     * Site-wide blackouts — the ones with no `service_id` — are included, since
     * a closure that applies to everything applies to this service too.
     *
     * The bounds are compared against the ends of the day rather than a bare
     * date string: Eloquent writes a `date` cast through the connection's date
     * format, so the stored value is `2026-12-24 00:00:00`, and on SQLite —
     * where the column is text — `'2026-12-24 00:00:00' <= '2026-12-24'` is
     * false. The blackout would not close its own first day.
     *
     * @since 1.0.0
     *
     * @param  Builder<ServiceBlackoutDate>  $query  The query being built.
     * @param  int|Service|null  $service  The service being checked, if any.
     * @param  DateTimeInterface|string  $date  The date being checked.
     *
     * @return Builder<ServiceBlackoutDate> The scoped query.
     */
    public function scopeClosing( Builder $query, Service|int|null $service, DateTimeInterface|string $date ): Builder
    {
        $day       = Carbon::parse( $date );
        $serviceId = $service instanceof Service ? $service->getKey() : $service;

        return $query
            ->where( $this->qualifyColumn( 'starts_on' ), '<=', $day->copy()->endOfDay() )
            ->where( $this->qualifyColumn( 'ends_on' ), '>=', $day->copy()->startOfDay() )
            ->where( function ( Builder $scoped ) use ( $serviceId ): void {
                $scoped->whereNull( $this->qualifyColumn( 'service_id' ) );

                if ( null !== $serviceId ) {
                    $scoped->orWhere( $this->qualifyColumn( 'service_id' ), $serviceId );
                }
            } );
    }

    /**
     * Determines whether this blackout covers a given date.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $date  The date being checked.
     *
     * @return bool True when the date falls inside the range.
     */
    public function covers( DateTimeInterface|string $date ): bool
    {
        // The `date` cast already truncates both bounds to midnight, so only
        // the incoming value needs it.
        $day = Carbon::parse( $date )->startOfDay();

        return $day->between( $this->starts_on, $this->ends_on );
    }

    /**
     * Determines whether this blackout closes every service on the site.
     *
     * @since 1.0.0
     *
     * @return bool True when no single service is named.
     */
    public function isSiteWide(): bool
    {
        return null === $this->service_id;
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return ServiceBlackoutDateFactory The factory instance.
     */
    protected static function newFactory(): ServiceBlackoutDateFactory
    {
        return ServiceBlackoutDateFactory::new();
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
            'starts_on' => 'date',
            'ends_on'   => 'date',
        ];
    }
}
