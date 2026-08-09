<?php

/**
 * Site-scoped model fixture.
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
 * A minimal model that opts into site scoping.
 *
 * The scope's own mechanics are exercised against a stand-in with the shape
 * every owned table has — a nullable, indexed site_id — so that a failure here
 * points at the scope rather than at whichever real model happened to be used.
 * The package's own models are held to the same rules in
 * tests/Feature/Models/SiteScopingTest.php.
 *
 * `site_id` is deliberately not mass assignable, which is the rule the real
 * models follow too — an explicitly set site beats the resolved one, so a
 * fillable site_id would let request data write into another tenant. Tests that
 * need to place a row under a specific site use forceCreate().
 *
 * @since 1.0.0
 */
class SiteScopedBooking extends Model
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
    protected $table = 'site_scoped_bookings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [ 'reference' ];
}
