<?php

/**
 * String-keyed site-scoped model fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Bookings\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * A model whose owning site is identified by a non-numeric string.
 *
 * Core's contract returns `int|string|null` because packages in the ecosystem
 * key on identifiers that are not integers. Proving the scope carries a string
 * through needs a value the database cannot quietly treat as a number — seed
 * `42` into an integer column and resolve `'42'` and the comparison succeeds
 * on coercion alone, which would pass whether or not string identifiers work.
 *
 * @since 1.0.0
 */
class StringSiteScopedBooking extends Model
{
    use BelongsToSite;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'string_site_scoped_bookings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [ 'reference' ];
}
