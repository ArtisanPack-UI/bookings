<?php

/**
 * Service provider model.
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

use ArtisanPackUI\Bookings\Database\Factories\ServiceProviderFactory;
use ArtisanPackUI\Bookings\Models\Concerns\BelongsToSite;
use ArtisanPackUI\Bookings\Models\Concerns\ErasesPersonalData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use function config;
use function sprintf;

/**
 * The person — or resource — a booking is assigned to.
 *
 * A provider does not have to be an application user: `user_id` is nullable and
 * carries no foreign key, because a package cannot assume the host
 * application's users table exists or what its key type is.
 *
 * `timezone` is an IANA name and is never null. Availability is authored as
 * wall-clock time against it, so a provider without a zone has schedules that
 * mean nothing.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int|string|null $site_id
 * @property int|string|null $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $bio
 * @property string $timezone
 * @property int|null $image_media_id
 * @property string|null $image_url
 * @property int $round_robin_weight
 * @property \Illuminate\Support\Carbon|null $round_robin_last_assigned_at
 * @property int $sort_order
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property string|null $ical_token_hash
 * @property \Illuminate\Support\Carbon|null $pii_erased_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class ServiceProvider extends Model
{
    use BelongsToSite;
    use ErasesPersonalData;
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * `ical_token_hash` is absent on purpose, for the reason
     * {@see Booking::$fillable} leaves `manage_token_hash` off: a caller who
     * could set the hash directly could set it to the hash of a token they
     * already know, which is the whole attack the hashing exists to prevent.
     * Feed tokens are minted, never assigned — see
     * {@see \ArtisanPackUI\Bookings\Services\IcalTokenService}.
     *
     * See {@see Service::$fillable} for why `site_id` and `pii_erased_at` are
     * not on this list.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'email',
        'phone',
        'bio',
        'timezone',
        'image_media_id',
        'image_url',
        'round_robin_weight',
        'round_robin_last_assigned_at',
        'sort_order',
        'is_active',
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
        'round_robin_weight'           => 'integer',
        'round_robin_last_assigned_at' => 'datetime',
        'sort_order'                   => 'integer',
        'is_active'                    => 'boolean',
        'metadata'                     => 'array',
        'pii_erased_at'                => 'datetime',
    ];

    /**
     * The attributes hidden from array and JSON output.
     *
     * The hash is not the feed token, but it is the only secret the row holds,
     * and an admin API that serialised a provider wholesale would publish it to
     * anybody who could read the response.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $hidden = [ 'ical_token_hash' ];

    /**
     * Gets the services this provider offers.
     *
     * @since 1.0.0
     *
     * @return BelongsToMany<Service, $this, ServiceProviderService> The services relationship.
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(
            Service::class,
            'service_provider_service',
            'provider_id',
            'service_id',
        )
            ->using( ServiceProviderService::class )
            ->withPivot( [ 'id', 'custom_price', 'custom_duration' ] )
            ->withTimestamps();
    }

    /**
     * Gets the services this provider is the fallback for.
     *
     * @since 1.0.0
     *
     * @return HasMany<Service, $this> The default-for relationship.
     */
    public function defaultForServices(): HasMany
    {
        return $this->hasMany( Service::class, 'default_provider_id' );
    }

    /**
     * Gets this provider's recurring weekly hours.
     *
     * @since 1.0.0
     *
     * @return HasMany<AvailabilitySchedule, $this> The schedules relationship.
     */
    public function availabilitySchedules(): HasMany
    {
        return $this->hasMany( AvailabilitySchedule::class, 'provider_id' );
    }

    /**
     * Gets this provider's single-date exceptions.
     *
     * @since 1.0.0
     *
     * @return HasMany<AvailabilityOverride, $this> The overrides relationship.
     */
    public function availabilityOverrides(): HasMany
    {
        return $this->hasMany( AvailabilityOverride::class, 'provider_id' );
    }

    /**
     * Gets the bookings assigned to this provider.
     *
     * @since 1.0.0
     *
     * @return HasMany<Booking, $this> The bookings relationship.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany( Booking::class, 'provider_id' );
    }

    /**
     * Gets the external calendars connected to this provider.
     *
     * @since 1.0.0
     *
     * @return HasMany<CalendarConnection, $this> The connections relationship.
     */
    public function calendarConnections(): HasMany
    {
        return $this->hasMany( CalendarConnection::class, 'provider_id' );
    }

    /**
     * Gets the application user this provider represents, if any.
     *
     * The user model is read from configuration rather than assumed, because a
     * package cannot know what the host application calls its users.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Model, $this> The user relationship.
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config( 'artisanpack.bookings.user_model', 'App\\Models\\User' );

        return $this->belongsTo( $userModel, 'user_id' );
    }

    /**
     * Scopes a query to providers who can currently take bookings.
     *
     * @since 1.0.0
     *
     * @param  Builder<ServiceProvider>  $query  The query being built.
     *
     * @return Builder<ServiceProvider> The scoped query.
     */
    public function scopeActive( Builder $query ): Builder
    {
        return $query->where( $this->qualifyColumn( 'is_active' ), true );
    }

    /**
     * Orders a query by how long ago each provider was last assigned work.
     *
     * This is the round-robin cursor. A provider who has never been assigned
     * anything sorts first, which is what stops a newly added provider waiting
     * behind everybody else's history before they get their first booking.
     *
     * @since 1.0.0
     *
     * @param  Builder<ServiceProvider>  $query  The query being built.
     *
     * @return Builder<ServiceProvider> The ordered query.
     */
    public function scopeLeastRecentlyAssigned( Builder $query ): Builder
    {
        // The tie-breaks matter as much as the cursor and have to match
        // {@see \ArtisanPackUI\Bookings\Strategies\LeastRecentlyAssignedStrategy},
        // which sorts the same providers in PHP once availability has narrowed
        // them. On a fresh rota every timestamp is null, so without the weight
        // and the key this scope and that strategy would disagree about whose
        // turn it is for the first booking every service ever takes.
        return $query
            ->orderByRaw(
                sprintf( 'case when %s is null then 0 else 1 end asc', $this->qualifyColumn( 'round_robin_last_assigned_at' ) ),
            )
            ->orderBy( $this->qualifyColumn( 'round_robin_last_assigned_at' ) )
            ->orderByDesc( $this->qualifyColumn( 'round_robin_weight' ) )
            ->orderBy( $this->getQualifiedKeyName() );
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return ServiceProviderFactory The factory instance.
     */
    protected static function newFactory(): ServiceProviderFactory
    {
        return ServiceProviderFactory::new();
    }
}
