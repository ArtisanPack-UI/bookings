<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Support\HookRegistry;

/**
 * Reads every PHP file under a directory of the package.
 *
 * @param  string  $directory  The package-relative directory to read.
 *
 * @return array<string, string> The file contents, keyed by package-relative path.
 */
function hookSources( string $directory ): array
{
    $root  = dirname( __DIR__, 3 ) . '/' . $directory;
    $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

    $sources = [];

    /** @var SplFileInfo $file */
    foreach ( $files as $file ) {
        if ( 'php' !== $file->getExtension() ) {
            continue;
        }

        $sources[ $directory . '/' . str_replace( $root . '/', '', $file->getPathname() ) ] = (string) file_get_contents(
            $file->getPathname(),
        );
    }

    return $sources;
}

/**
 * Finds every hook name passed to a hooks-package call in the given sources.
 *
 * Matching on the call rather than on any `ap.bookings.*` string is what keeps
 * the cache-key prefix in ProviderSlotLock — which is not a hook — out of the
 * results, without maintaining a list of exceptions that would quietly grow.
 *
 * Only literal names are found, so a hook fired through a variable would be
 * invisible here — and would read as "declared but never fired". Nothing in
 * `src/` does that, and a hook name assembled at runtime is not one an extender
 * could subscribe to from documentation anyway.
 *
 * @param  array<string, string>  $sources  The file contents to search.
 *
 * @return array<string, array<int, string>> The files each hook name was found in, keyed by name.
 */
function hookNamesIn( array $sources ): array
{
    $found = [];

    foreach ( $sources as $path => $contents ) {
        preg_match_all(
            '/\b(?:addAction|addFilter|doAction|applyFilters|removeAction|removeFilter|removeAllActions|removeAllFilters)\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            $contents,
            $matches,
        );

        foreach ( $matches[1] as $name ) {
            $found[ $name ][] = $path;
        }
    }

    return $found;
}

describe( 'the registry and the code agree', function (): void {
    it( 'fires every hook it declares as shipped', function (): void {
        $fired = array_keys( hookNamesIn( hookSources( 'src' ) ) );

        // Not toContain per name: naming the whole missing set at once is what
        // makes this readable when a rename drops several at a time.
        expect( array_values( array_diff( HookRegistry::shipped(), $fired ) ) )->toBe(
            [],
            'Declared shipped but never fired from src/. Fire it, or mark it pending in HookRegistry.',
        );
    } );

    it( 'declares every hook it fires', function (): void {
        $fired = array_keys( hookNamesIn( hookSources( 'src' ) ) );
        $ours  = array_filter( $fired, static fn ( string $name ): bool => str_starts_with( $name, 'ap.bookings.' ) );

        expect( array_values( array_diff( $ours, array_keys( HookRegistry::all() ) ) ) )->toBe(
            [],
            'Fired from src/ but missing from HookRegistry. Add it, with its payload comment.',
        );
    } );

    it( 'has not yet fired anything it marks as pending', function (): void {
        $fired = array_keys( hookNamesIn( hookSources( 'src' ) ) );

        // The half that fails loudly when a surface ships: the ticket that
        // builds it fires the hook, this fails, and dropping the `pending`
        // flag is what makes it pass — which puts somebody in front of the
        // payload comment at exactly the moment the payload becomes real.
        expect( array_values( array_intersect( HookRegistry::pending(), $fired ) ) )->toBe(
            [],
            'Fired from src/ but still marked pending in HookRegistry. Drop the flag and check the payload comment.',
        );
    } );

    it( 'subscribes to upstream hooks under their upstream names', function (): void {
        $fired = hookNamesIn( hookSources( 'src' ) );

        foreach ( array_keys( $fired ) as $name ) {
            expect( $name )->toStartWith( 'ap.' );
        }
    } );
} );

/**
 * Reads the package's prose documentation.
 *
 * `docs/` is walked rather than globbed. PHP's `glob()` has no globstar, so a
 * doubled-wildcard pattern under `docs/` matches exactly one directory deep — it
 * would miss both `docs/hooks.md` and `docs/guides/hooks/names.md`, and miss
 * them silently, which is the worst way for a test asserting an absence to be
 * wrong.
 *
 * @return array<string, string> The file contents, keyed by package-relative path.
 */
function hookDocs(): array
{
    $root = dirname( __DIR__, 3 );
    $docs = [];

    foreach ( glob( $root . '/*.md' ) ?: [] as $path ) {
        $docs[ str_replace( $root . '/', '', $path ) ] = (string) file_get_contents( $path );
    }

    if ( ! is_dir( $root . '/docs' ) ) {
        return $docs;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root . '/docs', FilesystemIterator::SKIP_DOTS ),
    );

    /** @var SplFileInfo $file */
    foreach ( $files as $file ) {
        if ( 'md' !== $file->getExtension() ) {
            continue;
        }

        $docs[ str_replace( $root . '/', '', $file->getPathname() ) ] = (string) file_get_contents(
            $file->getPathname(),
        );
    }

    return $docs;
}

describe( 'the retired plan §5.5 names', function (): void {
    it( 'appear nowhere in src, tests, or docs', function (): void {
        $sources = [ ...hookSources( 'src' ), ...hookSources( 'tests' ), ...hookDocs() ];

        // This file names them all, and would fail its own assertion.
        unset( $sources[ 'tests/Feature/Support/HookRegistryTest.php' ] );
        unset( $sources[ 'src/Support/HookRegistry.php' ] );

        // The changelog is the one document whose job is to record what things
        // used to be called. A rename is not documented by refusing to write
        // down the old name, and nobody subscribes to a hook they read about in
        // an entry saying it was retired. Everything else — the README, docs/ —
        // stays enforced, because those are read as instructions.
        unset( $sources['CHANGELOG.md'] );

        $offences = [];

        foreach ( $sources as $path => $contents ) {
            foreach ( HookRegistry::retired() as $retired ) {
                // Anchored on the left rather than matched as a bare substring,
                // because a retired name is the tail of the live one it was
                // renamed to: `bookings.notification.sending` sits inside
                // `ap.bookings.notification.sending`, and a plain
                // str_contains() reports the current name as a violation of
                // itself. The lookbehind requires the name to start a token, so
                // an `ap.`-prefixed hook is not its own retired ancestor.
                if ( 1 === preg_match( '/(?<![\w.])' . preg_quote( $retired, '/' ) . '/', $contents ) ) {
                    $offences[] = $path . ': ' . $retired;
                }
            }
        }

        expect( $offences )->toBe( [] );
    } );

    it( 'cannot come back under a name nobody thought to retire', function (): void {
        // The shape check, which catches the whole retired family rather than
        // the handful of it anyone remembered to list: a hook name this package
        // fires is `ap.`-prefixed, and carries no snake_case anywhere.
        $sources = [ ...hookSources( 'src' ), ...hookSources( 'tests' ) ];

        unset( $sources[ 'tests/Feature/Support/HookRegistryTest.php' ] );

        foreach ( array_keys( hookNamesIn( $sources ) ) as $name ) {
            expect( $name )
                ->toStartWith( 'ap.' )
                ->not->toMatch( '/_/' );
        }
    } );
} );

describe( 'the documentation', function (): void {
    it( 'lists every shipped hook in the README', function (): void {
        $readme = hookDocs()['README.md'] ?? '';

        expect( $readme )->not->toBeEmpty();

        $undocumented = array_values( array_filter(
            HookRegistry::shipped(),
            static fn ( string $name ): bool => ! str_contains( $readme, '`' . $name . '`' ),
        ) );

        expect( $undocumented )->toBe(
            [],
            'Fired from src/ but missing from the README hooks table.',
        );
    } );
} );

describe( 'the registry itself', function (): void {
    it( 'names every hook in the locked convention', function (): void {
        foreach ( array_keys( HookRegistry::all() ) as $name ) {
            expect( $name )->toMatch( '/^ap\.bookings(\.[a-z][a-zA-Z0-9]*)+$/' );
        }
    } );

    it( 'declares each hook as exactly one of an action or a filter', function (): void {
        foreach ( HookRegistry::all() as $name => $hook ) {
            expect( $hook['type'] )->toBeIn( [ 'action', 'filter' ], $name );
            expect( $hook['issues'] )->not->toBeEmpty( $name );
        }
    } );

    it( 'splits cleanly into shipped and pending', function (): void {
        expect( array_intersect( HookRegistry::shipped(), HookRegistry::pending() ) )->toBe( [] )
            ->and( count( HookRegistry::shipped() ) + count( HookRegistry::pending() ) )
            ->toBe( count( HookRegistry::all() ) );
    } );
} );
