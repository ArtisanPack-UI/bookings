<?php

/**
 * Intake schema version model.
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

use ArtisanPackUI\Bookings\Database\Factories\IntakeSchemaVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded version of a service's intake form.
 *
 * Every edit to a service's form appends a version rather than replacing the
 * current one, and each booking records the version it was captured against.
 * That is what keeps an old booking readable: rendering last year's answers
 * against this year's form gives you fields nobody was asked and answers with
 * nowhere to go.
 *
 * The table has no `site_id` — a version belongs to a service, and the service
 * already belongs to a site.
 *
 * A schema keeps its questions in a JSON *list*, not an object keyed by field
 * name. That is load-bearing rather than stylistic: MySQL's native JSON type
 * stores objects sorted by key and returns them that way, so a form keyed by
 * field name would render its questions in a different order there than on
 * sqlite or Postgres. List order is preserved by every engine the package
 * supports, and the engine matrix asserts as much.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int $service_id
 * @property int $version
 * @property array<string, mixed> $schema
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class IntakeSchemaVersion extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'booking_intake_schema_versions';

    /**
     * The attributes that are mass assignable.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_id',
        'version',
        'schema',
    ];

    /**
     * Gets the service whose form this version records.
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
     * Scopes a query to the versions of one service's form.
     *
     * @since 1.0.0
     *
     * @param  Builder<IntakeSchemaVersion>  $query  The query being built.
     * @param  int|Service  $service  The service whose history is wanted.
     *
     * @return Builder<IntakeSchemaVersion> The scoped query.
     */
    public function scopeForService( Builder $query, Service|int $service ): Builder
    {
        return $query->where(
            $this->qualifyColumn( 'service_id' ),
            $service instanceof Service ? $service->getKey() : $service,
        );
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return IntakeSchemaVersionFactory The factory instance.
     */
    protected static function newFactory(): IntakeSchemaVersionFactory
    {
        return IntakeSchemaVersionFactory::new();
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
            'version' => 'integer',
            'schema'  => 'array',
        ];
    }
}
