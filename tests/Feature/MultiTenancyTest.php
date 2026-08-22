<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Models\Scopes\BelongsToSiteScope;
use ArtisanPackUI\Core\Contracts\SiteResolver;
use ArtisanPackUI\Core\MultiTenancy\NullSiteResolver;
use ArtisanPackUI\Core\MultiTenancy\SiteContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\FixedSiteResolver;
use Tests\Fixtures\SiteScopedBooking;
use Tests\Fixtures\StringSiteScopedBooking;

beforeEach( function (): void {
    Schema::create( 'site_scoped_bookings', function ( Blueprint $table ): void {
        $table->increments( 'id' );
        $table->unsignedBigInteger( 'site_id' )->nullable()->index();
        $table->string( 'reference' );
    } );

    // Seeded across the scope so every visibility assertion has something to
    // hide as well as something to find. forceCreate, because site_id is not
    // mass assignable — placing a row under a named site is deliberate.
    foreach ( [ [ 1, 'site-one' ], [ 2, 'site-two' ], [ null, 'no-site' ] ] as [ $siteId, $reference ] ) {
        SiteScopedBooking::withoutGlobalScope( BelongsToSiteScope::class )
            ->forceCreate( [ 'site_id' => $siteId, 'reference' => $reference ] );
    }
} );

describe( 'the shared site context', function (): void {
    it( 'is the context this package scopes by', function (): void {
        // Core owns resolution for the whole ecosystem, so the package binds
        // no contract of its own. If this binding ever stops being core's, two
        // packages can disagree about which site a request is for.
        expect( app( SiteContext::class ) )->toBeInstanceOf( SiteContext::class )
            ->and( app( SiteContext::class ) )->toBe( app( SiteContext::class ) );
    } );

    it( 'answers the enabled question on this package\'s behalf', function (): void {
        app()->instance( SiteResolver::class, new FixedSiteResolver( 2 ) );
        app()->instance( SiteContext::class, new SiteContext( app( SiteResolver::class ), app( 'config' ) ) );

        expect( config( 'artisanpack.core.multi_tenant.enabled' ) )->toBeFalse()
            ->and( BelongsToSiteScope::currentSiteId() )->toBeNull();
    } );
} );

describe( 'the site scope', function (): void {
    it( 'leaves every row visible while site scoping is disabled', function (): void {
        app()->instance( SiteResolver::class, new FixedSiteResolver( 1 ) );
        app()->instance( SiteContext::class, new SiteContext( app( SiteResolver::class ), app( 'config' ) ) );

        expect( config( 'artisanpack.core.multi_tenant.enabled' ) )->toBeFalse()
            ->and( SiteScopedBooking::count() )->toBe( 3 );
    } );

    it( 'reports no site when the shared context is not bound', function (): void {
        // An application that somehow runs this package without core's
        // provider gets an inert scope rather than a container trying — and
        // failing — to build a SiteContext out of nothing.
        config()->set( 'artisanpack.core.multi_tenant.enabled', true );
        app()->offsetUnset( SiteContext::class );

        expect( app()->bound( SiteContext::class ) )->toBeFalse()
            ->and( BelongsToSiteScope::currentSiteId() )->toBeNull()
            ->and( SiteScopedBooking::count() )->toBe( 3 );
    } );

    it( 'leaves every row visible when the resolver reports no site', function (): void {
        config()->set( 'artisanpack.core.multi_tenant.enabled', true );
        app()->instance( SiteResolver::class, new NullSiteResolver() );
        app()->instance( SiteContext::class, new SiteContext( app( SiteResolver::class ), app( 'config' ) ) );

        expect( SiteScopedBooking::count() )->toBe( 3 );
    } );

    it( 'hides a booking owned by another site', function (): void {
        scopeToSite( 2 );

        $references = SiteScopedBooking::query()->pluck( 'reference' )->all();

        expect( $references )->toBe( [ 'site-two' ] )
            ->and( SiteScopedBooking::query()->where( 'reference', 'site-one' )->first() )->toBeNull();
    } );

    it( 'hides rows that belong to no site at all', function (): void {
        scopeToSite( 1 );

        expect( SiteScopedBooking::query()->pluck( 'reference' )->all() )->toBe( [ 'site-one' ] );
    } );

    it( 'scopes on a non-numeric string identifier', function (): void {
        // Core's contract returns int|string|null because other packages key
        // on non-integer identifiers, so the scope has to carry a string
        // through to the query rather than assume an int. The identifier is
        // deliberately not numeric: seeding 42 and resolving '42' would pass on
        // the database's own coercion whether or not strings worked.
        Schema::create( 'string_site_scoped_bookings', function ( Blueprint $table ): void {
            $table->increments( 'id' );
            $table->string( 'site_id' )->nullable()->index();
            $table->string( 'reference' );
        } );

        foreach ( [ [ 'site-alpha', 'alpha-booking' ], [ 'site-beta', 'beta-booking' ] ] as [ $siteId, $reference ] ) {
            StringSiteScopedBooking::withoutGlobalScope( BelongsToSiteScope::class )
                ->forceCreate( [ 'site_id' => $siteId, 'reference' => $reference ] );
        }

        scopeToSite( 'site-alpha' );

        expect( StringSiteScopedBooking::query()->pluck( 'reference' )->all() )->toBe( [ 'alpha-booking' ] )
            ->and( StringSiteScopedBooking::acrossAllSites()->count() )->toBe( 2 );
    } );

    it( 'stamps a new record with a non-numeric string identifier', function (): void {
        Schema::create( 'string_site_scoped_bookings', function ( Blueprint $table ): void {
            $table->increments( 'id' );
            $table->string( 'site_id' )->nullable()->index();
            $table->string( 'reference' );
        } );

        scopeToSite( 'site-beta' );

        $booking = StringSiteScopedBooking::create( [ 'reference' => 'fresh' ] );

        expect( $booking->site_id )->toBe( 'site-beta' );
    } );

    it( 'scopes a find by primary key', function (): void {
        $siteOne = SiteScopedBooking::withoutGlobalScope( BelongsToSiteScope::class )
            ->where( 'reference', 'site-one' )
            ->firstOrFail();

        scopeToSite( 2 );

        expect( SiteScopedBooking::find( $siteOne->getKey() ) )->toBeNull();
    } );

    it( 'qualifies the column so a join stays unambiguous', function (): void {
        scopeToSite( 2 );

        expect( SiteScopedBooking::query()->toSql() )
            ->toContain( '"site_scoped_bookings"."site_id"' );
    } );

    it( 'can be lifted for queries that span every site', function (): void {
        scopeToSite( 2 );

        expect( SiteScopedBooking::acrossAllSites()->count() )->toBe( 3 );
    } );
} );

describe( 'a site pinned through the shared context', function (): void {
    it( 'scopes this package\'s queries', function (): void {
        // This is what a console command looping over sites depends on: pin a
        // site, run the work, and every bookings query answers for that site.
        scopeToSite( 1 );

        $seen = app( SiteContext::class )->forSite( 2, function (): array {
            return SiteScopedBooking::query()->pluck( 'reference' )->all();
        } );

        expect( $seen )->toBe( [ 'site-two' ] )
            ->and( SiteScopedBooking::query()->pluck( 'reference' )->all() )->toBe( [ 'site-one' ] );
    } );

    it( 'wins even when site scoping is switched off', function (): void {
        // forSite() is an unambiguous instruction from the calling code, so it
        // does not wait on the enabled flag — a maintenance command can scope
        // to one site on a single-tenant install.
        expect( config( 'artisanpack.core.multi_tenant.enabled' ) )->toBeFalse();

        $seen = app( SiteContext::class )->forSite( 2, function (): array {
            return SiteScopedBooking::query()->pluck( 'reference' )->all();
        } );

        expect( $seen )->toBe( [ 'site-two' ] );
    } );

    it( 'is released again when the callback finishes', function (): void {
        scopeToSite( 1 );

        app( SiteContext::class )->withoutSite( function (): void {
            expect( SiteScopedBooking::count() )->toBe( 3 );
        } );

        expect( SiteScopedBooking::query()->pluck( 'reference' )->all() )->toBe( [ 'site-one' ] );
    } );

    it( 'stamps a new record with the pinned site', function (): void {
        scopeToSite( 1 );

        $booking = app( SiteContext::class )->forSite( 2, function (): SiteScopedBooking {
            return SiteScopedBooking::create( [ 'reference' => 'pinned' ] );
        } );

        expect( $booking->site_id )->toBe( 2 );
    } );
} );

describe( 'stamping the owning site', function (): void {
    it( 'stamps a new record with the site in context', function (): void {
        scopeToSite( 2 );

        $booking = SiteScopedBooking::create( [ 'reference' => 'fresh' ] );

        expect( $booking->site_id )->toBe( 2 );
    } );

    it( 'leaves an explicitly set site alone', function (): void {
        scopeToSite( 2 );

        $booking = SiteScopedBooking::forceCreate( [ 'site_id' => 1, 'reference' => 'explicit' ] );

        expect( $booking->site_id )->toBe( 1 );
    } );

    it( 'ignores a site smuggled in through mass assignment', function (): void {
        // An explicit site beats the resolved one, which is what makes a
        // deliberate cross-site write possible. Request data must not be able
        // to reach that path, so site_id stays out of $fillable.
        scopeToSite( 2 );

        $booking = SiteScopedBooking::create( [ 'site_id' => 1, 'reference' => 'smuggled' ] );

        expect( $booking->site_id )->toBe( 2 );
    } );

    it( 'is defeated by a global unguard, which site-scoped models must avoid', function (): void {
        // Model::unguard() makes isFillable() return true for everything, so
        // the $fillable protection above stops applying. Pinned here so the
        // limitation is visible rather than discovered in production.
        scopeToSite( 2 );

        $booking = Model::unguarded(
            fn (): SiteScopedBooking => SiteScopedBooking::create( [ 'site_id' => 1, 'reference' => 'unguarded' ] ),
        );

        expect( $booking->site_id )->toBe( 1 );
    } );

    it( 'does not stamp writes that bypass model events', function (): void {
        // Builder::insert() fires no model events, so nothing stamps the site.
        // The row is then invisible to every site-scoped read — which is why
        // bulk writes have to set site_id themselves.
        scopeToSite( 2 );

        SiteScopedBooking::query()->insert( [ 'reference' => 'bulk' ] );

        $bulk = SiteScopedBooking::withoutGlobalScope( BelongsToSiteScope::class )
            ->where( 'reference', 'bulk' )
            ->firstOrFail();

        expect( $bulk->site_id )->toBeNull()
            ->and( SiteScopedBooking::query()->where( 'reference', 'bulk' )->exists() )->toBeFalse();
    } );

    it( 'leaves the site null when nothing is in context', function (): void {
        $booking = SiteScopedBooking::create( [ 'reference' => 'single-tenant' ] );

        expect( $booking->site_id )->toBeNull();
    } );
} );
