<?php

declare( strict_types=1 );

use ArtisanPackUI\Core\Contracts\SiteResolver;
use ArtisanPackUI\Core\MultiTenancy\SiteContext;
use Tests\Fixtures\FixedSiteResolver;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend( Tests\TestCase::class )
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in( 'Feature' );

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend( 'toBeOne', function () {
    return $this->toBe( 1 );
} );

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Enables site scoping and puts the given site in context.
 *
 * Rebuilds core's shared site context around a fixed resolver rather than
 * stubbing the context itself, so the tests still run through the real
 * `SiteContext` — including its `enabled` check, which is the thing that
 * decides whether a resolver is consulted at all.
 *
 * @param  int|string|null  $siteId  The site to resolve, or null for none.
 *
 * @return void
 */
function scopeToSite( int|string|null $siteId ): void
{
    config()->set( 'artisanpack.core.multi_tenant.enabled', true );

    app()->instance( SiteResolver::class, new FixedSiteResolver( $siteId ) );
    app()->instance( SiteContext::class, new SiteContext(
        app( SiteResolver::class ),
        app( 'config' ),
    ) );
}
