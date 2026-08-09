<?php

/**
 * Booking series model.
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

use ArtisanPackUI\Bookings\Database\Factories\BookingSeriesFactory;
use ArtisanPackUI\Bookings\Models\Concerns\BelongsToSite;
use ArtisanPackUI\Bookings\Models\Concerns\ErasesPersonalData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The rule a set of recurring bookings was generated from.
 *
 * The occurrences themselves live in `bookings`; this row is what they were
 * derived from. `rrule` holds a canonical RFC 5545 recurrence rule, and
 * `dtstart_local` plus `dtstart_timezone` hold a floating start — a clock face
 * and the zone to read it in, not an instant.
 *
 * That split is what makes "this and all following" edits re-derivable rather
 * than approximated: the source of truth is the rule, not the rows it produced.
 * It also means `dtstart_local` must never be treated as a point in time on its
 * own. The cast preserves the clock face and nothing more; use
 * {@see self::dtstart()} to get the instant it actually names.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int|string|null $site_id
 * @property int $service_id
 * @property int|null $provider_id
 * @property string $customer_name
 * @property string $customer_email
 * @property string|null $customer_phone
 * @property string $rrule
 * @property Carbon $dtstart_local
 * @property string $dtstart_timezone
 * @property Carbon|null $until_local
 * @property int|null $occurrence_count
 * @property int $intake_schema_version
 * @property Carbon|null $cancelled_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $pii_erased_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class BookingSeries extends Model
{
    use BelongsToSite;
    use ErasesPersonalData;
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * Named explicitly because "series" is its own plural, and a convention
     * that guesses is a convention somebody has to check.
     *
     * @var string
     */
    protected $table = 'booking_series';

    /**
     * The attributes that are mass assignable.
     *
     * See {@see Service::$fillable} for why `site_id` and `pii_erased_at` are
     * not on this list.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_id',
        'provider_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'rrule',
        'dtstart_local',
        'dtstart_timezone',
        'until_local',
        'occurrence_count',
        'intake_schema_version',
        'cancelled_at',
        'metadata',
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
        'dtstart_local'         => 'datetime',
        'until_local'           => 'datetime',
        'occurrence_count'      => 'integer',
        'intake_schema_version' => 'integer',
        'cancelled_at'          => 'datetime',
        'metadata'              => 'array',
        'pii_erased_at'         => 'datetime',
    ];

    /**
     * Gets the service this series books.
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
     * Gets the provider this series is assigned to, if any.
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
     * Gets every booking generated from this series.
     *
     * @since 1.0.0
     *
     * @return HasMany<Booking, $this> The occurrences relationship.
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany( Booking::class, 'series_id' )->orderBy( 'series_index' );
    }

    /**
     * Gets the occurrences a re-materialisation is still allowed to rewrite.
     *
     * An occurrence somebody has moved or edited individually is detached, and
     * regenerating it from the rule would throw that edit away.
     *
     * @since 1.0.0
     *
     * @return HasMany<Booking, $this> The attached occurrences relationship.
     */
    public function attachedOccurrences(): HasMany
    {
        return $this->occurrences()->whereNull( 'detached_from_series_at' );
    }

    /**
     * Gets the instant this series starts at.
     *
     * Composed from the stored clock face and the series' own timezone, rather
     * than read off `dtstart_local` directly — that attribute carries whatever
     * zone the application happens to be configured with, which is not the zone
     * the rule was authored in.
     *
     * @since 1.0.0
     *
     * @return Carbon The start of the series, in its own timezone.
     */
    public function dtstart(): Carbon
    {
        return $this->localToInstant( $this->dtstart_local );
    }

    /**
     * Gets the instant this series stops recurring at, if it is bounded that way.
     *
     * @since 1.0.0
     *
     * @return Carbon|null The end of the series, or null when it is unbounded
     *                     or bounded by an occurrence count instead.
     */
    public function until(): ?Carbon
    {
        return null === $this->until_local ? null : $this->localToInstant( $this->until_local );
    }

    /**
     * Determines whether this series has been cancelled.
     *
     * @since 1.0.0
     *
     * @return bool True when the rule has been cancelled.
     */
    public function isCancelled(): bool
    {
        return null !== $this->cancelled_at;
    }

    /**
     * Gets the position the next occurrence of this series would take.
     *
     * Derived from the highest index already used rather than from the number
     * of rows, so a series with a gap in the middle of it does not reissue an
     * index a later occurrence is still holding.
     *
     * Trashed occurrences count too. `occurrences()` hangs off a soft-deleting
     * model, so its default query cannot see them — but a soft delete does not
     * free an index, because the row still exists and can be restored. Without
     * `withTrashed()`, a series whose last occurrence was soft-deleted would
     * hand that index straight back out, and `(series_id, series_index)` is only
     * a plain index in the schema, so the duplicate would go in unremarked.
     *
     * @since 1.0.0
     *
     * @return int The next free series index.
     */
    public function nextSeriesIndex(): int
    {
        return (int) $this->occurrences()->withTrashed()->max( 'series_index' ) + 1;
    }

    /**
     * Scopes a query to series that are still recurring.
     *
     * @since 1.0.0
     *
     * @param  Builder<BookingSeries>  $query  The query being built.
     *
     * @return Builder<BookingSeries> The scoped query.
     */
    public function scopeActive( Builder $query ): Builder
    {
        return $query->whereNull( $this->qualifyColumn( 'cancelled_at' ) );
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return BookingSeriesFactory The factory instance.
     */
    protected static function newFactory(): BookingSeriesFactory
    {
        return BookingSeriesFactory::new();
    }

    /**
     * Reattaches this series' timezone to a stored floating date-time.
     *
     * @since 1.0.0
     *
     * @param  Carbon  $local  The floating local date-time.
     *
     * @return Carbon The same clock face, read in the series' timezone.
     */
    protected function localToInstant( Carbon $local ): Carbon
    {
        return Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $local->format( 'Y-m-d H:i:s' ),
            $this->dtstart_timezone,
        );
    }
}
