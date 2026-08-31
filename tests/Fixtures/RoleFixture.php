<?php

/**
 * Role fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A role a {@see RoledAdmin} can hold.
 *
 * The related side of the fixture's `roles` relationship, named so the admin
 * email path's `whereHas( 'roles', where name = ... )` has a `name` to match.
 *
 * @since 1.1.0
 */
class RoleFixture extends Model
{
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
    protected $table = 'role_fixtures';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [ 'name' ];
}
