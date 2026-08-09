<?php

/**
 * Service model.
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

use ArtisanPackUI\Bookings\Database\Factories\ServiceFactory;
use ArtisanPackUI\Bookings\Enums\ServiceAssignmentStrategy;
use ArtisanPackUI\Bookings\Models\Concerns\BelongsToSite;
use ArtisanPackUI\Bookings\Models\Concerns\ErasesPersonalData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use function function_exists;

/**
 * The thing a customer books.
 *
 * A name, a duration, and the rules that govern how it is scheduled. Everything
 * else in the package hangs off this model.
 *
 * `default_provider_id` carries no foreign key at the database level — see the
 * services migration for why — so the relationship is enforced here instead.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int|string|null $site_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $duration
 * @property int $buffer_before
 * @property int $buffer_after
 * @property string|null $price
 * @property bool $is_free
 * @property int $max_bookings_per_slot
 * @property bool $is_active
 * @property array<string, mixed>|null $intake_schema
 * @property int $intake_schema_version
 * @property ServiceAssignmentStrategy $assignment_strategy
 * @property int|null $default_provider_id
 * @property int|null $image_media_id
 * @property string|null $image_url
 * @property string|null $color
 * @property string|null $timezone
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $pii_erased_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read string|null                 $contrast_color
 */
class Service extends Model
{
    use BelongsToSite;
    use ErasesPersonalData;
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * `site_id` is deliberately absent. An explicitly set site beats the one
     * resolved from context, so a fillable `site_id` would let request data
     * place a service under another tenant.
     *
     * `pii_erased_at` is absent for a different reason: it is the marker saying
     * a row has been through the erasure routine, and nothing but that routine
     * has any business setting it.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'duration',
        'buffer_before',
        'buffer_after',
        'price',
        'is_free',
        'max_bookings_per_slot',
        'is_active',
        'intake_schema',
        'intake_schema_version',
        'assignment_strategy',
        'default_provider_id',
        'image_media_id',
        'image_url',
        'color',
        'timezone',
        'metadata',
    ];

    /**
     * Gets the providers who offer this service.
     *
     * The pivot table is named explicitly. Laravel would derive
     * `service_service_provider` from the two class names, which is not the
     * table the migration created.
     *
     * @since 1.0.0
     *
     * @return BelongsToMany<ServiceProvider, $this, ServiceProviderService> The providers relationship.
     */
    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceProvider::class,
            'service_provider_service',
            'service_id',
            'provider_id',
        )
            ->using( ServiceProviderService::class )
            ->withPivot( [ 'id', 'custom_price', 'custom_duration' ] )
            ->withTimestamps();
    }

    /**
     * Gets the provider this service falls back to.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<ServiceProvider, $this> The default provider relationship.
     */
    public function defaultProvider(): BelongsTo
    {
        return $this->belongsTo( ServiceProvider::class, 'default_provider_id' );
    }

    /**
     * Gets the date ranges during which this service cannot be booked.
     *
     * @since 1.0.0
     *
     * @return HasMany<ServiceBlackoutDate, $this> The blackout dates relationship.
     */
    public function blackoutDates(): HasMany
    {
        return $this->hasMany( ServiceBlackoutDate::class );
    }

    /**
     * Gets the bookings made against this service.
     *
     * @since 1.0.0
     *
     * @return HasMany<Booking, $this> The bookings relationship.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany( Booking::class );
    }

    /**
     * Gets the recurring series booked against this service.
     *
     * @since 1.0.0
     *
     * @return HasMany<BookingSeries, $this> The series relationship.
     */
    public function series(): HasMany
    {
        return $this->hasMany( BookingSeries::class );
    }

    /**
     * Gets the history of this service's intake form.
     *
     * @since 1.0.0
     *
     * @return HasMany<IntakeSchemaVersion, $this> The schema versions relationship.
     */
    public function intakeSchemaVersions(): HasMany
    {
        return $this->hasMany( IntakeSchemaVersion::class );
    }

    /**
     * Scopes a query to services that can currently be booked.
     *
     * @since 1.0.0
     *
     * @param  Builder<Service>  $query  The query being built.
     *
     * @return Builder<Service> The scoped query.
     */
    public function scopeActive( Builder $query ): Builder
    {
        return $query->where( $this->qualifyColumn( 'is_active' ), true );
    }

    /**
     * Gets the intake schema recorded for a given version of this service's form.
     *
     * Rendering an old booking's answers against the current form gives you
     * fields nobody was asked and answers with nowhere to go, so anything
     * displaying a booking has to ask for the version that booking was captured
     * against rather than reading `intake_schema`.
     *
     * @since 1.0.0
     *
     * @param  int  $version  The schema version to look up.
     *
     * @return array<string, mixed>|null The recorded schema, or null when the
     *                                   version was never recorded.
     */
    public function intakeSchemaForVersion( int $version ): ?array
    {
        return $this->intakeSchemaVersions()
            ->where( 'version', $version )
            ->value( 'schema' );
    }

    /**
     * Gets a text colour that reads against this service's theme colour.
     *
     * Delegates to `artisanpack-ui/accessibility`, which is a suggested rather
     * than a required dependency — the accessor reports null when it is not
     * installed rather than guessing at a contrast ratio, because a wrong answer
     * here is an unreadable label rather than a visible failure.
     *
     * @since 1.0.0
     *
     * @return Attribute<string|null, never> The contrast colour accessor.
     */
    protected function contrastColor(): Attribute
    {
        return Attribute::get( function (): ?string {
            if ( null === $this->color || ! function_exists( 'a11yGetContrastColor' ) ) {
                return null;
            }

            return a11yGetContrastColor( $this->color );
        } );
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return ServiceFactory The factory instance.
     */
    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
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
            'duration'              => 'integer',
            'buffer_before'         => 'integer',
            'buffer_after'          => 'integer',
            'price'                 => 'decimal:2',
            'is_free'               => 'boolean',
            'max_bookings_per_slot' => 'integer',
            'is_active'             => 'boolean',
            'intake_schema'         => 'array',
            'intake_schema_version' => 'integer',
            'assignment_strategy'   => ServiceAssignmentStrategy::class,
            'metadata'              => 'array',
            'pii_erased_at'         => 'datetime',
        ];
    }
}
