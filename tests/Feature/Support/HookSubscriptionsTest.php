<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Support\HookSubscriptions;
use Tests\Fixtures\FormsFieldTypesStub;

describe( 'the optional-package gate', function (): void {
    it( 'knows the packages the integrations hang off', function (): void {
        expect( HookSubscriptions::knownPackages() )->toBe( [ 'cms-framework', 'forms' ] );
    } );

    it( 'refuses a package it has no probe for', function (): void {
        expect( static fn (): bool => HookSubscriptions::isInstalled( 'media-library' ) )
            ->toThrow( InvalidArgumentException::class, 'Unknown optional package [media-library]' );
    } );

    it( 'reports a package absent when its probe class is not loadable', function (): void {
        // cms-framework is a `suggest`, so it is genuinely absent here — which
        // is the branch a standalone install takes, and the one that has to
        // stay silent.
        expect( HookSubscriptions::isInstalled( 'cms-framework' ) )->toBeFalse();
    } );

    it( 'does not run a subscription for an absent package', function (): void {
        $ran = false;

        $bound = HookSubscriptions::whenInstalled( 'cms-framework', function () use ( &$ran ): void {
            $ran = true;
        } );

        expect( $bound )->toBeFalse()
            ->and( $ran )->toBeFalse();
    } );

    it( 'binds nothing upstream while the package is absent', function (): void {
        // The behaviour the gate exists for, end to end: a callback that
        // subscribes to another package's hook does not reach addFilter() at
        // all, so nothing of ours is left hanging off a filter that the
        // application will never apply — and, more to the point, the closure
        // body naming absent classes is never entered.
        HookSubscriptions::whenInstalled( 'cms-framework', function (): void {
            addFilter( 'ap.cmsFramework.admin.menu', static fn ( array $menu ): array => [
                ...$menu,
                'bookings' => [ 'label' => 'Bookings' ],
            ] );
        } );

        expect( applyFilters( 'ap.cmsFramework.admin.menu', [] ) )->toBe( [] );
    } );

    it( 'opens once the probe class becomes loadable', function (): void {
        // Both branches in one test on purpose. Aliasing a class is a
        // process-wide, irreversible side effect, so asserting the closed
        // branch for `forms` in a separate test would leave the two ordered by
        // luck — and the loser failing for a reason nothing in it explains.
        expect( HookSubscriptions::isInstalled( 'forms' ) )->toBeFalse();

        class_alias( FormsFieldTypesStub::class, 'ArtisanPackUI\\Forms\\Config\\FieldTypes' );

        $ran = false;

        $bound = HookSubscriptions::whenInstalled( 'forms', function () use ( &$ran ): void {
            $ran = true;
        } );

        expect( HookSubscriptions::isInstalled( 'forms' ) )->toBeTrue()
            ->and( $bound )->toBeTrue()
            ->and( $ran )->toBeTrue();
    } );
} );
