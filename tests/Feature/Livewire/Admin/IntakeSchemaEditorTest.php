<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Livewire\Admin\IntakeSchemaEditor;
use ArtisanPackUI\Bookings\Models\IntakeSchemaVersion;
use ArtisanPackUI\Bookings\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.intakeSchema' );
} );

/**
 * A single editor field row, filled from the defaults with overrides.
 *
 * @param  array<string, mixed>  $overrides  The values to override.
 *
 * @return array{name: string, type: string, label: string, required: bool, options: string} The row.
 */
function intakeField( array $overrides = [] ): array
{
    return array_merge( [
        'name'     => 'goal',
        'type'     => 'textarea',
        'label'    => 'Your goal',
        'required' => false,
        'options'  => '',
    ], $overrides );
}

describe( 'loading the form', function (): void {
    it( 'reads the service\'s current form into the editor', function (): void {
        $service = Service::factory()->create( [
            'intake_schema' => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'textarea', 'label' => 'Your goal', 'required' => true ],
                ],
            ],
        ] );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->assertSet( 'fields.0.name', 'goal' )
            ->assertSet( 'fields.0.type', 'textarea' )
            ->assertSet( 'fields.0.required', true );
    } );
} );

describe( 'saving a version', function (): void {
    it( 'appends a version and moves the service pointer to it', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null, 'intake_schema_version' => 1 ] );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.0', intakeField( [ 'name' => 'goal', 'required' => true ] ) )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->assertDispatched( 'bookings-intake-schema-saved' );

        $version = IntakeSchemaVersion::query()->forService( $service->id )->latest( 'version' )->first();

        expect( $version )->not->toBeNull()
            ->and( $service->refresh()->intake_schema_version )->toBe( $version->version )
            ->and( $version->schema['fields'][0]['name'] )->toBe( 'goal' )
            ->and( $version->schema['fields'][0]['required'] )->toBeTrue();
    } );

    it( 'splits an options box into one choice per line', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null ] );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.0', intakeField( [
                'name'    => 'referrer',
                'type'    => 'select',
                'options' => "Search\nReferral, word of mouth\nSocial",
            ] ) )
            ->call( 'save' )
            ->assertHasNoErrors();

        $version = IntakeSchemaVersion::query()->forService( $service->id )->latest( 'version' )->first();

        expect( $version->schema['fields'][0]['options'] )
            ->toBe( [ 'Search', 'Referral, word of mouth', 'Social' ] );
    } );

    it( 'requires each field to be named', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null ] );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.0.name', '' )
            ->call( 'save' )
            ->assertHasErrors( 'fields.0.name' );
    } );

    it( 'keeps a field\'s position when it is moved up', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null ] );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.0', intakeField( [ 'name' => 'first' ] ) )
            ->call( 'addField' )
            ->set( 'fields.1', intakeField( [ 'name' => 'second' ] ) )
            ->call( 'moveUp', 1 )
            ->call( 'save' )
            ->assertHasNoErrors();

        $version = IntakeSchemaVersion::query()->forService( $service->id )->latest( 'version' )->first();

        expect( array_column( $version->schema['fields'], 'name' ) )->toBe( [ 'second', 'first' ] );
    } );

    it( 'keeps a field\'s position when it is moved down', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null ] );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.0', intakeField( [ 'name' => 'first' ] ) )
            ->call( 'addField' )
            ->set( 'fields.1', intakeField( [ 'name' => 'second' ] ) )
            ->call( 'moveDown', 0 )
            ->call( 'save' )
            ->assertHasNoErrors();

        $version = IntakeSchemaVersion::query()->forService( $service->id )->latest( 'version' )->first();

        expect( array_column( $version->schema['fields'], 'name' ) )->toBe( [ 'second', 'first' ] );
    } );

    it( 'drops a removed field and reindexes the rest', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null ] );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.0', intakeField( [ 'name' => 'first' ] ) )
            ->call( 'addField' )
            ->set( 'fields.1', intakeField( [ 'name' => 'second' ] ) )
            ->call( 'removeField', 0 )
            ->call( 'save' )
            ->assertHasNoErrors();

        $version = IntakeSchemaVersion::query()->forService( $service->id )->latest( 'version' )->first();

        expect( array_column( $version->schema['fields'], 'name' ) )->toBe( [ 'second' ] );
    } );

    it( 'rejects a field type it does not offer', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null ] );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.0', intakeField( [ 'name' => 'goal', 'type' => 'wysiwyg' ] ) )
            ->call( 'save' )
            ->assertHasErrors( 'fields.0.type' );
    } );

    it( 'records the current form as its own version before appending an edit', function (): void {
        // A factory service carries a two-field form on its row at version 1 with
        // no version rows behind it — the state publish() back-fills for.
        $service = Service::factory()->create();

        expect( $service->intakeSchemaVersions()->count() )->toBe( 0 );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.2', intakeField( [ 'name' => 'budget', 'type' => 'number' ] ) )
            ->call( 'save' )
            ->assertHasNoErrors();

        $service->refresh();

        $backfilled = IntakeSchemaVersion::query()->forService( $service->id )->where( 'version', 1 )->first();
        $appended   = IntakeSchemaVersion::query()->forService( $service->id )->where( 'version', 2 )->first();

        expect( $service->intake_schema_version )->toBe( 2 )
            ->and( $backfilled )->not->toBeNull()
            ->and( array_column( $backfilled->schema['fields'], 'name' ) )->toBe( [ 'goal', 'referrer' ] )
            ->and( $appended->schema['fields'] )->toHaveCount( 3 );
    } );
} );

describe( 'the version diff', function (): void {
    it( 'reports no change when a single version is compared with itself', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null ] );

        $component = Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.0', intakeField( [ 'name' => 'goal' ] ) )
            ->call( 'save' )
            ->assertHasNoErrors();

        expect( $component->get( 'diffLeft' ) )->toBe( 1 )
            ->and( $component->get( 'diffRight' ) )->toBe( 1 );

        $diff = $component->instance()->diff();

        expect( $diff['added'] )->toBe( [] )
            ->and( $diff['removed'] )->toBe( [] )
            ->and( $diff['changed'] )->toBe( [] );
    } );
} );

describe( 'the version diff', function (): void {
    it( 'reports what changed between two versions', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null ] );

        $component = Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.0', intakeField( [ 'name' => 'goal', 'required' => false ] ) )
            ->call( 'addField' )
            ->set( 'fields.1', intakeField( [ 'name' => 'phone', 'type' => 'text' ] ) )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->set( 'fields.0.required', true )
            ->call( 'removeField', 1 )
            ->call( 'addField' )
            ->set( 'fields.1', intakeField( [ 'name' => 'budget', 'type' => 'number' ] ) )
            ->call( 'save' )
            ->assertHasNoErrors()
            ->set( 'diffLeft', 1 )
            ->set( 'diffRight', 2 );

        $diff = $component->instance()->diff();

        expect( $diff['added'] )->toBe( [ 'budget' ] )
            ->and( $diff['removed'] )->toBe( [ 'phone' ] )
            ->and( $diff['changed'] )->toBe( [ 'goal' ] );
    } );
} );

describe( 'the ap.bookings.intakeSchema hook', function (): void {
    it( 'renders the filtered schema so injected fields are previewed', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null ] );

        addFilter( 'ap.bookings.intakeSchema', function ( array $schema ): array {
            $schema['fields'][] = [
                'name'  => 'consent',
                'type'  => 'checkbox',
                'label' => 'Consent injected by a plugin',
            ];

            return $schema;
        } );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.0', intakeField( [ 'name' => 'goal' ] ) )
            ->assertSee( 'Consent injected by a plugin' );
    } );

    it( 'hands the filter the version the next save will actually write', function (): void {
        // The service carries a form but no version rows, so the next save
        // back-fills version 1 and appends version 2 — the number the preview
        // must pass the filter, not the naive max()+1 of 1.
        $service = Service::factory()->create();

        $seen          = new stdClass();
        $seen->version = null;

        addFilter( 'ap.bookings.intakeSchema', function ( array $schema, $service, int $version ) use ( $seen ): array {
            $seen->version = $version;

            return $schema;
        } );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->instance()
            ->filteredFields();

        expect( $seen->version )->toBe( 2 );
    } );

    it( 'never persists a plugin-injected field into a version row', function (): void {
        $service = Service::factory()->create( [ 'intake_schema' => null ] );

        addFilter( 'ap.bookings.intakeSchema', function ( array $schema ): array {
            $schema['fields'][] = [
                'name'  => 'consent',
                'type'  => 'checkbox',
                'label' => 'Consent injected by a plugin',
            ];

            return $schema;
        } );

        Livewire::test( IntakeSchemaEditor::class, [ 'serviceId' => $service->id ] )
            ->call( 'addField' )
            ->set( 'fields.0', intakeField( [ 'name' => 'goal' ] ) )
            ->call( 'save' )
            ->assertHasNoErrors();

        $version = IntakeSchemaVersion::query()->forService( $service->id )->latest( 'version' )->first();

        expect( array_column( $version->schema['fields'], 'name' ) )
            ->toBe( [ 'goal' ] )
            ->not->toContain( 'consent' );
    } );
} );
