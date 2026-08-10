<?php

/**
 * Notifiable admin fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;

/**
 * Stands in for the application's admin model.
 *
 * The package cannot assume a user table, so the database channel is told which
 * model to notify through configuration. This is the model those tests point it
 * at — and, along with Laravel's own `notifications` table, it is created by the
 * test rather than by a package migration, because neither belongs to this
 * package's schema.
 *
 * @since 1.0.0
 */
class NotifiableAdmin extends Model
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
    protected $table = 'notifiable_admins';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [ 'email' ];

    /**
     * Creates the tables this fixture and its notifications need.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function createTable(): void
    {
        if ( ! Schema::hasTable( 'notifiable_admins' ) ) {
            Schema::create( 'notifiable_admins', function ( Blueprint $table ): void {
                $table->id();
                $table->string( 'email' );
            } );
        }

        if ( ! Schema::hasTable( 'notifications' ) ) {
            Schema::create( 'notifications', function ( Blueprint $table ): void {
                $table->uuid( 'id' )->primary();
                $table->string( 'type' );
                $table->morphs( 'notifiable' );
                $table->text( 'data' );
                $table->timestamp( 'read_at' )->nullable();
                $table->timestamps();
            } );
        }
    }
}
