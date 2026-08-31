<?php

/**
 * Roled admin fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;

/**
 * Stands in for the host user model when the admin email path resolves by role.
 *
 * The role is a cms-framework concept, resolved through a `roles` relationship on
 * the application's own user model — which this package cannot assume exists. This
 * fixture is a user model that has one, so the role path can be exercised the way
 * a real install with cms-framework provides it: pointed at by
 * `auth.providers.users.model`, carrying an address to email and a `roles`
 * relation to filter on.
 *
 * @since 1.1.0
 */
class RoledAdmin extends Model
{
    use Notifiable;

    /**
     * Whether the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'roled_admins';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [ 'email' ];

    /**
     * Creates the tables this fixture and its roles need.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public static function createTables(): void
    {
        if ( ! Schema::hasTable( 'roled_admins' ) ) {
            Schema::create( 'roled_admins', function ( Blueprint $table ): void {
                $table->id();
                $table->string( 'email' );
            } );
        }

        if ( ! Schema::hasTable( 'role_fixtures' ) ) {
            Schema::create( 'role_fixtures', function ( Blueprint $table ): void {
                $table->id();
                $table->string( 'name' );
            } );
        }

        if ( ! Schema::hasTable( 'roled_admin_role' ) ) {
            Schema::create( 'roled_admin_role', function ( Blueprint $table ): void {
                $table->unsignedBigInteger( 'admin_id' );
                $table->unsignedBigInteger( 'role_id' );
            } );
        }
    }

    /**
     * Gets the roles this admin holds.
     *
     * @since 1.1.0
     *
     * @return BelongsToMany<RoleFixture, $this> The roles relationship.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany( RoleFixture::class, 'roled_admin_role', 'admin_id', 'role_id' );
    }
}
