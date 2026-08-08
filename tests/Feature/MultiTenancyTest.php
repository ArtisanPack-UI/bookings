<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Contracts\SiteResolver;
use ArtisanPackUI\Bookings\Models\Scopes\BelongsToSiteScope;
use ArtisanPackUI\Bookings\MultiTenancy\HookSiteResolver;
use ArtisanPackUI\Bookings\MultiTenancy\NullSiteResolver;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\FixedSiteResolver;
use Tests\Fixtures\SiteScopedBooking;

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

describe( 'the site resolver binding', function (): void {
    it( 'defaults to the hook-backed resolver', function (): void {
        expect( app( SiteResolver::class ) )->toBeInstanceOf( HookSiteResolver::class );
    } );

    it( 'uses the resolver named in configuration', function (): void {
        config()->set( 'artisanpack.bookings.multi_tenant.site_resolver', FixedSiteResolver::class );

        expect( app( SiteResolver::class ) )->toBeInstanceOf( FixedSiteResolver::class );
    } );

    it( 'ignores an empty resolver setting', function (): void {
        config()->set( 'artisanpack.bookings.multi_tenant.site_resolver', '' );

        expect( app( SiteResolver::class ) )->toBeInstanceOf( HookSiteResolver::class );
    } );

    it( 'resolves the binding as a singleton', function (): void {
        expect( app( SiteResolver::class ) )->toBe( app( SiteResolver::class ) );
    } );
} );

describe( 'the hook-backed resolver', function (): void {
    it( 'reports no site when nothing answers the filter', function (): void {
        expect( ( new HookSiteResolver() )->currentSiteId() )->toBeNull();
    } );

    it( 'returns the site a listener supplies', function (): void {
        addFilter( HookSiteResolver::HOOK, fn (): int => 7 );

        expect( ( new HookSiteResolver() )->currentSiteId() )->toBe( 7 );
    } );

    it( 'accepts a numeric string identifier', function (): void {
        addFilter( HookSiteResolver::HOOK, fn (): string => '7' );

        expect( ( new HookSiteResolver() )->currentSiteId() )->toBe( 7 );
    } );

    it( 'rejects a value it cannot read as a site identifier', function ( mixed $resolved ): void {
        // Coercing an unusable value to null would silently unscope every query
        // and leak one site's bookings into another, so it has to fail loudly.
        addFilter( HookSiteResolver::HOOK, fn (): mixed => $resolved );

        expect( fn () => ( new HookSiteResolver() )->currentSiteId() )
            ->toThrow( UnexpectedValueException::class );
    } )->with( [
        'array'              => [ [ 'site' => 7 ] ],
        'non-numeric string' => [ 'seven' ],
        'empty string'       => [ '' ],
        'float'              => [ 7.5 ],
        'boolean'            => [ true ],
        'object'             => [ new stdClass() ],
    ] );

    it( 'reflects a site that changes mid-process', function (): void {
        $siteId = 1;

        addFilter( HookSiteResolver::HOOK, function () use ( &$siteId ): int {
            return $siteId;
        } );

        $resolver = new HookSiteResolver();

        expect( $resolver->currentSiteId() )->toBe( 1 );

        $siteId = 2;

        expect( $resolver->currentSiteId() )->toBe( 2 );
    } );
} );

describe( 'the site scope', function (): void {
    it( 'leaves every row visible while multi-tenancy is disabled', function (): void {
        app()->instance( SiteResolver::class, new FixedSiteResolver( 1 ) );

        expect( config( 'artisanpack.bookings.multi_tenant.enabled' ) )->toBeFalse()
            ->and( SiteScopedBooking::count() )->toBe( 3 );
    } );

    it( 'reports no site when no resolver is bound', function (): void {
        // Tenancy is on and configuration is readable, so the two earlier
        // guards pass and this genuinely lands on the unbound-resolver branch.
        // Without the config repository the scope would bail one check sooner
        // and the branch would go untested while still looking covered.
        $app    = app();
        $config = $app->make( 'config' );
        $config->set( 'artisanpack.bookings.multi_tenant.enabled', true );

        $bare = new Container();
        $bare->instance( 'config', $config );
        Container::setInstance( $bare );

        try {
            expect( $bare->bound( SiteResolver::class ) )->toBeFalse()
                ->and( BelongsToSiteScope::currentSiteId() )->toBeNull();
        } finally {
            Container::setInstance( $app );
        }
    } );

    it( 'leaves every row visible when no resolver is bound', function (): void {
        config()->set( 'artisanpack.bookings.multi_tenant.enabled', true );
        app()->offsetUnset( SiteResolver::class );

        expect( app()->bound( SiteResolver::class ) )->toBeFalse()
            ->and( SiteScopedBooking::count() )->toBe( 3 );
    } );

    it( 'leaves every row visible when the resolver reports no site', function (): void {
        config()->set( 'artisanpack.bookings.multi_tenant.enabled', true );
        app()->instance( SiteResolver::class, new NullSiteResolver() );

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
