<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Contracts\ResolvesHostAddresses;
use ArtisanPackUI\Bookings\Rules\ValidWebhookUrl;
use ArtisanPackUI\Bookings\Services\WebhookUrlGuard;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\TestsWithSqlite;
use Tests\Support\FakeHostResolver;

uses( TestsWithSqlite::class );

/**
 * Points the guard at a fixed resolution map for the rule to consult.
 *
 * @param  array<string, array<int, string>>  $map  Host-to-addresses overrides.
 */
function bindWebhookResolver( array $map = [], array $default = [ '93.184.216.34' ] ): void
{
    app()->instance( ResolvesHostAddresses::class, new FakeHostResolver( $map, $default ) );
    app()->forgetInstance( WebhookUrlGuard::class );
}

it( 'passes a public https URL', function (): void {
    bindWebhookResolver();

    $validator = Validator::make(
        [ 'url' => 'https://hooks.example.com/bookings' ],
        [ 'url' => [ new ValidWebhookUrl() ] ],
    );

    expect( $validator->passes() )->toBeTrue();
} );

it( 'fails a URL that resolves to a private address', function (): void {
    bindWebhookResolver( [ 'internal.example.com' => [ '10.0.0.9' ] ] );

    $validator = Validator::make(
        [ 'url' => 'https://internal.example.com/hook' ],
        [ 'url' => [ new ValidWebhookUrl() ] ],
    );

    expect( $validator->fails() )->toBeTrue()
        ->and( $validator->errors()->first( 'url' ) )->toContain( 'address that is not allowed' );
} );

it( 'fails the metadata endpoint', function (): void {
    bindWebhookResolver();

    $validator = Validator::make(
        [ 'url' => 'https://169.254.169.254/latest/meta-data/' ],
        [ 'url' => [ new ValidWebhookUrl() ] ],
    );

    expect( $validator->fails() )->toBeTrue();
} );

it( 'fails a non-string value', function (): void {
    bindWebhookResolver();

    $validator = Validator::make(
        [ 'url' => [ 'not', 'a', 'string' ] ],
        [ 'url' => [ new ValidWebhookUrl() ] ],
    );

    expect( $validator->fails() )->toBeTrue()
        ->and( $validator->errors()->first( 'url' ) )->toContain( 'not a valid absolute URL' );
} );
