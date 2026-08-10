<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Exceptions\IntakeValidationException;
use ArtisanPackUI\Bookings\Models\IntakeSchemaVersion;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Services\IntakeFieldValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

/**
 * Gets the validator under test.
 *
 * @return IntakeFieldValidator The validator.
 */
function intake(): IntakeFieldValidator
{
    return app( IntakeFieldValidator::class );
}

/**
 * Builds a one-field intake form.
 *
 * @param  array<string, mixed>  $field  Anything to change about the field.
 *
 * @return array<string, mixed> The schema.
 */
function intakeSchema( array $field = [] ): array
{
    return [
        'fields' => [
            $field + [ 'name' => 'goal', 'type' => 'textarea', 'required' => true ],
        ],
    ];
}

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.intakeSchema' );
} );

describe( 'publishing a version', function (): void {
    it( 'appends a version and moves the service on to it', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null, 'intake_schema_version' => 1 ] );

        $first  = intake()->publish( $service, intakeSchema() );
        $second = intake()->publish( $service, intakeSchema( [ 'name' => 'referrer', 'type' => 'text' ] ) );

        expect( $first->version )->toBe( 1 )
            ->and( $second->version )->toBe( 2 )
            ->and( $service->fresh()->intake_schema_version )->toBe( 2 )
            ->and( IntakeSchemaVersion::query()->forService( $service )->count() )->toBe( 2 );
    } );

    it( 'keeps every earlier version readable', function (): void {
        // The whole reason versions exist: last year's answers have to render
        // against last year's form, not against this year's.
        $service = Service::factory()->create();

        intake()->publish( $service, intakeSchema() );
        intake()->publish( $service, intakeSchema( [ 'name' => 'referrer' ] ) );

        expect( intake()->schemaFor( $service, 1 )['fields'][0]['name'] )->toBe( 'goal' )
            ->and( intake()->schemaFor( $service, 2 )['fields'][0]['name'] )->toBe( 'referrer' );
    } );

    it( 'reads a service whose form was never published through a version row', function (): void {
        // The ordinary state of a service seeded by a factory or an importer.
        // Refusing to read it would make every booking against it unvalidatable.
        $service = Service::factory()->create( [
            'intake_schema'         => intakeSchema(),
            'intake_schema_version' => 1,
        ] );

        expect( intake()->schemaFor( $service, 1 ) )->toBe( intakeSchema() )
            ->and( intake()->schemaFor( $service, 7 ) )->toBe( [] );
    } );
} );

describe( 'validating answers', function (): void {
    it( 'accepts the answers the form asked for and drops the rest', function (): void {
        $service = Service::factory()->create();
        intake()->publish( $service, intakeSchema() );

        $accepted = intake()->validate( $service, 1, [
            'goal'     => 'Learn to juggle',
            'smuggled' => 'not on the form',
        ] );

        expect( $accepted )->toBe( [ 'goal' => 'Learn to juggle' ] );
    } );

    it( 'reports missing and malformed answers per field', function (): void {
        $service = Service::factory()->create();
        intake()->publish( $service, [
            'fields' => [
                [ 'name' => 'goal', 'type' => 'textarea', 'required' => true ],
                [ 'name' => 'contact', 'type' => 'email', 'required' => true ],
            ],
        ] );

        try {
            intake()->validate( $service, 1, [ 'contact' => 'not-an-email' ] );

            $this->fail( 'The validator accepted answers it should have refused.' );
        } catch ( IntakeValidationException $refused ) {
            expect( $refused->errors()->keys() )->toEqualCanonicalizing( [ 'goal', 'contact' ] );
        }
    } );

    it( 'lets an optional field go unanswered', function (): void {
        $service = Service::factory()->create();
        intake()->publish( $service, intakeSchema( [ 'required' => false ] ) );

        expect( intake()->validate( $service, 1, [] ) )->toBeNull();
    } );

    it( 'holds a choice field to the options it offers', function (): void {
        $service = Service::factory()->create();
        intake()->publish( $service, [
            'fields' => [
                [
                    'name'     => 'referrer',
                    'type'     => 'select',
                    'required' => true,
                    'options'  => [ 'search', 'referral', 'social' ],
                ],
            ],
        ] );

        expect( intake()->validate( $service, 1, [ 'referrer' => 'social' ] ) )
            ->toBe( [ 'referrer' => 'social' ] );

        expect( fn () => intake()->validate( $service, 1, [ 'referrer' => 'billboard' ] ) )
            ->toThrow( IntakeValidationException::class );
    } );

    it( 'holds each answer of a multi-answer field to the options', function (): void {
        // `in:` against an array never matches, so the constraint has to be
        // applied to the members rather than to the array holding them —
        // otherwise every correct multi-select answer is refused too.
        $service = Service::factory()->create();
        intake()->publish( $service, [
            'fields' => [
                [
                    'name'     => 'topics',
                    'type'     => 'multiselect',
                    'required' => true,
                    'options'  => [ 'pricing', 'onboarding', 'migration' ],
                ],
            ],
        ] );

        expect( intake()->validate( $service, 1, [ 'topics' => [ 'pricing', 'migration' ] ] ) )
            ->toBe( [ 'topics' => [ 'pricing', 'migration' ] ] );

        expect( fn () => intake()->validate( $service, 1, [ 'topics' => [ 'pricing', 'weather' ] ] ) )
            ->toThrow( IntakeValidationException::class );
    } );

    it( 'validates an old booking against the form it was actually captured with', function (): void {
        // The case §5.10 exists for. Version 2 asks a question version 1 never
        // did, and answers given to version 1 must not be judged against it.
        $service = Service::factory()->create();

        intake()->publish( $service, intakeSchema() );
        intake()->publish( $service, [
            'fields' => [
                [ 'name' => 'goal', 'type' => 'textarea', 'required' => true ],
                [ 'name' => 'budget', 'type' => 'number', 'required' => true ],
            ],
        ] );

        expect( intake()->validate( $service, 1, [ 'goal' => 'Learn to juggle' ] ) )
            ->toBe( [ 'goal' => 'Learn to juggle' ] );

        expect( fn () => intake()->validate( $service, 2, [ 'goal' => 'Learn to juggle' ] ) )
            ->toThrow( IntakeValidationException::class );
    } );

    it( 'ignores a field with no name rather than refusing every booking', function (): void {
        // Nothing in the payload matches it and nothing can be keyed under it,
        // so there is no way to enforce such a field and no way to report that
        // it was not enforced.
        $service = Service::factory()->create();
        intake()->publish( $service, [ 'fields' => [ [ 'type' => 'text', 'required' => true ] ] ] );

        expect( intake()->validate( $service, 1, [] ) )->toBeNull();
    } );

    it( 'validates a type it has never heard of as plain text', function (): void {
        // An administrator adding a field type this package does not know about
        // should get a booking that works; `rules` is where they say more.
        $service = Service::factory()->create();
        intake()->publish( $service, intakeSchema( [
            'type'  => 'signature-pad',
            'rules' => [ 'max:5' ],
        ] ) );

        expect( intake()->validate( $service, 1, [ 'goal' => 'ok' ] ) )->toBe( [ 'goal' => 'ok' ] );

        expect( fn () => intake()->validate( $service, 1, [ 'goal' => 'far too long' ] ) )
            ->toThrow( IntakeValidationException::class );
    } );
} );

describe( 'ap.bookings.intakeSchema', function (): void {
    it( 'hands a subscriber the snapshotted schema, the service, and the version', function (): void {
        $service = Service::factory()->create();
        intake()->publish( $service, intakeSchema() );
        intake()->publish( $service, intakeSchema( [ 'name' => 'referrer' ] ) );

        $seen = null;

        addFilter(
            'ap.bookings.intakeSchema',
            function ( array $schema, Service $forService, int $version ) use ( &$seen ): array {
                $seen = [ $schema, $forService->getKey(), $version ];

                return $schema;
            },
        );

        intake()->schemaFor( $service, 1 );

        expect( $seen[0]['fields'][0]['name'] )->toBe( 'goal' )
            ->and( $seen[1] )->toBe( $service->getKey() )
            ->and( $seen[2] )->toBe( 1 );
    } );

    it( 'honours a subscriber that adds a field, without writing it back', function (): void {
        $service = Service::factory()->create();
        intake()->publish( $service, intakeSchema() );

        addFilter( 'ap.bookings.intakeSchema', static function ( array $schema ): array {
            $schema['fields'][] = [ 'name' => 'referrer', 'type' => 'text', 'required' => true ];

            return $schema;
        } );

        expect( fn () => intake()->validate( $service, 1, [ 'goal' => 'Learn to juggle' ] ) )
            ->toThrow( IntakeValidationException::class );

        // The record of what was actually asked has to survive being decorated;
        // persisting the filter's output would make the version row lie about
        // history the next time anybody loaded it.
        expect( IntakeSchemaVersion::query()->forService( $service )->where( 'version', 1 )->value( 'schema' ) )
            ->toBe( intakeSchema() );
    } );

    it( 'refuses a subscriber that returns something other than a schema', function (): void {
        $service = Service::factory()->create();
        intake()->publish( $service, intakeSchema() );

        addFilter( 'ap.bookings.intakeSchema', static fn (): string => 'not a schema' );

        expect( fn () => intake()->schemaFor( $service, 1 ) )->toThrow( UnexpectedValueException::class );
    } );
} );
