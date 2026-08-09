<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Contracts\MeetingType;
use ArtisanPackUI\Bookings\Contracts\MeetingTypeRegistry as MeetingTypeRegistryContract;
use ArtisanPackUI\Bookings\MeetingTypes\MeetingTypeRegistry;
use ArtisanPackUI\Bookings\MeetingTypes\RegisteredMeetingType;

it( 'binds the registry as a singleton', function (): void {
    expect( app( MeetingTypeRegistryContract::class ) )
        ->toBeInstanceOf( MeetingTypeRegistry::class )
        ->toBe( app( MeetingTypeRegistryContract::class ) );
} );

it( 'resolves the same instance through the concrete class', function (): void {
    // Without the alias the container auto-builds a second, empty registry for
    // the concrete class — so a consumer type-hinting the shipped implementation
    // would silently lose every PHP-side registration.
    $registry = app( MeetingTypeRegistryContract::class );
    $registry->register( new RegisteredMeetingType( 'workshop', 'Workshop', 'All day' ) );

    expect( app( MeetingTypeRegistry::class ) )->toBe( $registry )
        ->and( app( MeetingTypeRegistry::class )->has( 'workshop' ) )->toBeTrue();
} );

it( 'ships the four built-in meeting types', function (): void {
    $types = app( MeetingTypeRegistryContract::class )->all();

    expect( array_keys( $types ) )
        ->toBe( [ 'one_to_one', 'group', 'recurring', 'round_robin' ] );
} );

it( 'describes how each built-in type behaves', function (): void {
    $registry = app( MeetingTypeRegistryContract::class );

    expect( $registry->get( 'one_to_one' )->allowsMultipleAttendees() )->toBeFalse()
        ->and( $registry->get( 'group' )->allowsMultipleAttendees() )->toBeTrue()
        ->and( $registry->get( 'recurring' )->isRecurring() )->toBeTrue()
        ->and( $registry->get( 'round_robin' )->assignsProviderAutomatically() )->toBeTrue();
} );

it( 'translates its labels in the locale current at read time', function (): void {
    // The registry is a singleton, so a label translated at construction would
    // be stuck in whichever locale was active the first time anything resolved
    // it — which an Octane process or a queue worker outlives.
    $registry = app( MeetingTypeRegistryContract::class );

    // Dot-free source strings, because `addLines()` reads a `.` as a group
    // separator and would mangle a sentence key. Real applications translate
    // sentence keys through JSON language files, where the whole sentence is
    // the key; the lookup `label()` and `description()` perform is the same one
    // either way.
    $registry->register( new RegisteredMeetingType( 'clinic', 'Clinic', 'Walk-in hours' ) );

    expect( $registry->get( 'one_to_one' )->label() )->toBe( 'One-to-one' )
        ->and( $registry->get( 'clinic' )->label() )->toBe( 'Clinic' );

    app( 'translator' )->addLines(
        [
            '*.One-to-one'    => 'En tête-à-tête',
            '*.Clinic'        => 'Permanence',
            '*.Walk-in hours' => 'Heures sans rendez-vous',
        ],
        'fr',
    );
    app()->setLocale( 'fr' );

    expect( $registry->get( 'one_to_one' )->label() )->toBe( 'En tête-à-tête' )
        ->and( $registry->get( 'clinic' )->label() )->toBe( 'Permanence' )
        ->and( $registry->get( 'clinic' )->description() )->toBe( 'Heures sans rendez-vous' );
} );

it( 'passes an untranslated string straight through when no translation exists', function (): void {
    expect( app( MeetingTypeRegistryContract::class )->get( 'group' )->label() )->toBe( 'Group' );
} );

it( 'falls back to the source string when a translation is not a string', function (): void {
    // __() is typed string|array|null; an application is free to map a key to an
    // array of lines, and returning that from a method declared `: string` would
    // be a TypeError raised inside the package for a mistake made outside it.
    app( 'translator' )->addLines( [ '*.Group' => [ 'one', 'two' ] ], 'fr' );
    app()->setLocale( 'fr' );

    expect( app( MeetingTypeRegistryContract::class )->get( 'group' )->label() )->toBe( 'Group' );
} );

it( 'answers for a key that is not registered', function (): void {
    $registry = app( MeetingTypeRegistryContract::class );

    expect( $registry->has( 'nothing_like_this' ) )->toBeFalse()
        ->and( $registry->get( 'nothing_like_this' ) )->toBeNull();
} );

it( 'registers a type in PHP', function (): void {
    $registry = app( MeetingTypeRegistryContract::class );

    $registry->register( new RegisteredMeetingType( 'workshop', 'Workshop', 'All day.' ) );

    expect( $registry->has( 'workshop' ) )->toBeTrue()
        ->and( $registry->get( 'workshop' )->label() )->toBe( 'Workshop' );
} );

describe( 'the ap.bookings.registeredMeetingTypes filter', function (): void {
    it( 'lets a consumer add a type', function (): void {
        addFilter( 'ap.bookings.registeredMeetingTypes', function ( array $types ): array {
            $types[] = new RegisteredMeetingType( 'webinar', 'Webinar', 'Broadcast to many.', allowsMultipleAttendees: true );

            return $types;
        } );

        $registry = app( MeetingTypeRegistryContract::class );

        expect( $registry->has( 'webinar' ) )->toBeTrue()
            ->and( $registry->get( 'webinar' )->allowsMultipleAttendees() )->toBeTrue()
            ->and( $registry->all() )->toHaveCount( 5 );
    } );

    it( 'lets a consumer replace a built-in type', function (): void {
        addFilter( 'ap.bookings.registeredMeetingTypes', function ( array $types ): array {
            $types['group'] = new RegisteredMeetingType( 'group', 'Class', 'Renamed by the application.', allowsMultipleAttendees: true );

            return $types;
        } );

        $registry = app( MeetingTypeRegistryContract::class );

        expect( $registry->get( 'group' )->label() )->toBe( 'Class' )
            ->and( $registry->all() )->toHaveCount( 4 );
    } );

    it( 'lets a consumer remove a type', function (): void {
        addFilter( 'ap.bookings.registeredMeetingTypes', function ( array $types ): array {
            unset( $types['round_robin'] );

            return $types;
        } );

        expect( app( MeetingTypeRegistryContract::class )->has( 'round_robin' ) )->toBeFalse();
    } );

    it( 'keys an appended type by the key the type reports', function (): void {
        addFilter( 'ap.bookings.registeredMeetingTypes', function ( array $types ): array {
            $types['a_key_that_disagrees'] = new RegisteredMeetingType( 'panel', 'Panel', 'Several providers.' );

            return $types;
        } );

        $registry = app( MeetingTypeRegistryContract::class );

        expect( $registry->has( 'panel' ) )->toBeTrue()
            ->and( $registry->has( 'a_key_that_disagrees' ) )->toBeFalse();
    } );

    it( 'runs on every read, so a late subscriber is still heard', function (): void {
        $registry = app( MeetingTypeRegistryContract::class );

        expect( $registry->has( 'late' ) )->toBeFalse();

        addFilter( 'ap.bookings.registeredMeetingTypes', function ( array $types ): array {
            $types[] = new RegisteredMeetingType( 'late', 'Late', 'Registered after the first read.' );

            return $types;
        } );

        expect( $registry->has( 'late' ) )->toBeTrue();
    } );

    it( 'refuses a filtered value that is not an array', function (): void {
        addFilter( 'ap.bookings.registeredMeetingTypes', fn (): string => 'nope' );

        app( MeetingTypeRegistryContract::class )->all();
    } )->throws( UnexpectedValueException::class, 'must return an array' );

    it( 'refuses an entry that is not a meeting type', function (): void {
        addFilter( 'ap.bookings.registeredMeetingTypes', function ( array $types ): array {
            $types[] = 'not a meeting type';

            return $types;
        } );

        app( MeetingTypeRegistryContract::class )->all();
    } )->throws( UnexpectedValueException::class, MeetingType::class );
} );
