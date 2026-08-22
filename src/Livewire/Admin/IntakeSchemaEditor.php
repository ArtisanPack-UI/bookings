<?php

/**
 * Admin intake schema editor.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Livewire\Admin;

use ArtisanPackUI\Bookings\Models\IntakeSchemaVersion;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Services\IntakeFieldValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Edits a service's intake form, one appended version at a time.
 *
 * A service's form is never edited in place. Every booking records the version
 * of the form it was captured against and keeps rendering against it, so a save
 * here does not overwrite the current form — it {@see IntakeFieldValidator::publish()
 * appends} a new `booking_intake_schema_versions` row and moves the service's
 * pointer to it. Last year's bookings still read against last year's questions;
 * this year's read against this year's.
 *
 * What the editor shows and what it saves are deliberately two different things.
 * The preview is the schema after `ap.bookings.intakeSchema` has run over it, so
 * the administrator sees the form a plugin will actually validate against —
 * fields another package injects included. The save writes the editor's own
 * state, before that filter, because a plugin-injected field belongs to the
 * plugin: persisting it into a version row would record, as history, a question
 * the administrator never authored and the plugin can no longer take back.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class IntakeSchemaEditor extends Component
{
    /**
     * The field types an administrator can build a question from.
     *
     * The set {@see IntakeFieldValidator} knows how to turn into validation
     * rules. A type outside it still validates — as a plain string — but is not
     * offered here, because the editor should not suggest a question it cannot
     * describe the checking of.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected const FIELD_TYPES = [
        'text',
        'textarea',
        'email',
        'url',
        'number',
        'integer',
        'date',
        'datetime',
        'time',
        'checkbox',
        'select',
        'radio',
        'multiselect',
        'checkboxes',
    ];

    /**
     * The service whose form is being edited.
     *
     * Locked: it is the row publish appends a version to, and a client that
     * could repoint it could write a form version onto a service the
     * administrator never opened.
     *
     * @since 1.0.0
     *
     * @var int
     */
    #[Locked]
    public int $serviceId = 0;

    /**
     * The form being edited, as a list of fields.
     *
     * A list rather than a map keyed by name, matching how a schema is stored:
     * the order of the questions is the order they are asked, and a map keyed by
     * name would be re-sorted by whichever database engine backs the site.
     *
     * @since 1.0.0
     *
     * @var array<int, array{_key: int, name: string, type: string, label: string, required: bool, options: string}>
     */
    public array $fields = [];

    /**
     * The next stable identity to hand a field row.
     *
     * A field's position is not its identity: removing or reordering one shuffles
     * every index below it, and keying the rendered rows by index would make
     * Livewire morph the wrong DOM node onto the wrong field — losing focus and
     * cursor from under the editor's hands. Each row carries a `_key` drawn from
     * this counter instead, stable across a row's whole life, so the rows the
     * server sends and the nodes the browser keeps stay matched.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $fieldKeySeq = 0;

    /**
     * The version the diff view reads on its left, the "before".
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    public ?int $diffLeft = null;

    /**
     * The version the diff view reads on its right, the "after".
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    public ?int $diffRight = null;

    /**
     * Loads the service's current form into the editor.
     *
     * The raw form off the service row, not the filtered one: this is the state
     * the administrator edits and the state a save writes back, and starting it
     * from the filtered schema would fold a plugin's injected fields into the
     * authored form the first time anybody pressed save.
     *
     * @since 1.0.0
     *
     * @param  int  $serviceId  The service whose form is edited.
     *
     * @return void
     */
    public function mount( int $serviceId ): void
    {
        $service = Service::query()->findOrFail( $serviceId );

        $this->serviceId = $service->getKey();
        $this->fields    = $this->fieldsFromSchema( $service->intake_schema );

        $this->resetDiffSelection();
    }

    /**
     * Appends a blank field to the end of the form.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function addField(): void
    {
        $this->fields[] = [
            '_key'     => $this->fieldKeySeq++,
            'name'     => '',
            'type'     => 'text',
            'label'    => '',
            'required' => false,
            'options'  => '',
        ];
    }

    /**
     * Removes a field from the form.
     *
     * @since 1.0.0
     *
     * @param  int  $index  The position of the field to remove.
     *
     * @return void
     */
    public function removeField( int $index ): void
    {
        if ( ! array_key_exists( $index, $this->fields ) ) {
            return;
        }

        unset( $this->fields[ $index ] );

        $this->fields = array_values( $this->fields );
    }

    /**
     * Moves a field one place towards the top of the form.
     *
     * The order fields are listed in is the order they are asked, so moving one
     * is an edit to the form and not merely to the screen.
     *
     * @since 1.0.0
     *
     * @param  int  $index  The position of the field to move.
     *
     * @return void
     */
    public function moveUp( int $index ): void
    {
        if ( $index <= 0 || ! array_key_exists( $index, $this->fields ) ) {
            return;
        }

        [ $this->fields[ $index - 1 ], $this->fields[ $index ] ] = [ $this->fields[ $index ], $this->fields[ $index - 1 ] ];
    }

    /**
     * Moves a field one place towards the bottom of the form.
     *
     * @since 1.0.0
     *
     * @param  int  $index  The position of the field to move.
     *
     * @return void
     */
    public function moveDown( int $index ): void
    {
        if ( ! array_key_exists( $index + 1, $this->fields ) ) {
            return;
        }

        [ $this->fields[ $index + 1 ], $this->fields[ $index ] ] = [ $this->fields[ $index ], $this->fields[ $index + 1 ] ];
    }

    /**
     * Validates the form and appends it as a new version.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function save(): void
    {
        $this->validate();

        $service = Service::query()->findOrFail( $this->serviceId );

        app( IntakeFieldValidator::class )->publish( $service, $this->schemaFromFields() );

        $this->resetDiffSelection();

        $this->dispatch(
            'bookings-intake-schema-saved',
            serviceId: $this->serviceId,
            version: $service->refresh()->intake_schema_version,
        );
    }

    /**
     * Gets the recorded history of the service's form, newest first.
     *
     * @since 1.0.0
     *
     * @return \Illuminate\Support\Collection<int, IntakeSchemaVersion> The versions.
     */
    public function versions(): \Illuminate\Support\Collection
    {
        return IntakeSchemaVersion::query()
            ->forService( $this->serviceId )
            ->orderByDesc( 'version' )
            ->get();
    }

    /**
     * Gets the form as it will actually be validated, filter and all.
     *
     * The editor's current state run through `ap.bookings.intakeSchema`, so the
     * administrator sees the questions a plugin adds to their form before they
     * commit to it. The prospective next version is passed as the filter's
     * version argument — the number this form will carry once saved — because
     * that is the version any booking taken against it will be captured with.
     *
     * @since 1.0.0
     *
     * @return array<int, array{name: string, type: string, label: string, required: bool, options: array<int, string>}> The filtered fields.
     */
    public function filteredFields(): array
    {
        $service = Service::query()->findOrFail( $this->serviceId );

        $filtered = applyFilters(
            'ap.bookings.intakeSchema',
            $this->schemaFromFields(),
            $service,
            $this->nextVersion( $service ),
        );

        if ( ! is_array( $filtered ) ) {
            return [];
        }

        return $this->normaliseFields( $filtered['fields'] ?? [] );
    }

    /**
     * Compares the two selected versions, field by field.
     *
     * Keyed by field name because that is the identity a form field keeps across
     * versions: a label reworded or a question made required is a change to the
     * same field, while a field that appears or vanishes is an add or a remove.
     * A field with no name cannot be told apart from another and is left out of
     * the comparison rather than reported as spurious churn.
     *
     * @since 1.0.0
     *
     * @return array{added: list<string>, removed: list<string>, changed: list<string>} The differences.
     */
    public function diff(): array
    {
        $left  = $this->fieldsByName( $this->schemaForVersion( $this->diffLeft ) );
        $right = $this->fieldsByName( $this->schemaForVersion( $this->diffRight ) );

        $added   = array_values( array_diff( array_keys( $right ), array_keys( $left ) ) );
        $removed = array_values( array_diff( array_keys( $left ), array_keys( $right ) ) );

        $changed = [];

        foreach ( $right as $name => $field ) {
            if ( array_key_exists( $name, $left ) && $left[ $name ] !== $field ) {
                $changed[] = $name;
            }
        }

        return [
            'added'   => $added,
            'removed' => $removed,
            'changed' => $changed,
        ];
    }

    /**
     * Gets the field types an administrator can pick between.
     *
     * @since 1.0.0
     *
     * @return list<string> The types.
     */
    public function fieldTypes(): array
    {
        return self::FIELD_TYPES;
    }

    /**
     * Renders the editor.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'bookings::livewire.admin.intake-schema-editor', [
            'versions'       => $this->versions(),
            'filteredFields' => $this->filteredFields(),
            'differences'    => $this->diff(),
        ] );
    }

    /**
     * Gets the validation rules the form is checked against before it is saved.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The rules.
     */
    protected function rules(): array
    {
        return [
            'fields'            => [ 'array' ],
            'fields.*.name'     => [ 'required', 'string', 'max:255', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/' ],
            'fields.*.type'     => [ 'required', 'string', Rule::in( self::FIELD_TYPES ) ],
            'fields.*.label'    => [ 'nullable', 'string', 'max:255' ],
            'fields.*.required' => [ 'boolean' ],
            'fields.*.options'  => [ 'nullable', 'string' ],
        ];
    }

    /**
     * Gets the human-readable names for the field rows in a validation message.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The attribute names.
     */
    protected function validationAttributes(): array
    {
        $attributes = [];

        foreach ( array_keys( $this->fields ) as $index ) {
            $position = $index + 1;

            $attributes[ "fields.$index.name" ] = __( 'field :position name', [ 'position' => $position ] );
            $attributes[ "fields.$index.type" ] = __( 'field :position type', [ 'position' => $position ] );
        }

        return $attributes;
    }

    /**
     * Predicts the version number the next save will record.
     *
     * Mirrors {@see IntakeFieldValidator::publish()} rather than guessing at
     * `max(version) + 1`, because publish back-fills first: a service carrying a
     * form on its row but no version behind it — the ordinary state of one
     * seeded or imported — has its current form recorded as its current version
     * before the edit is appended, so the edit lands one higher than a naive
     * count of existing rows would suggest. The preview passes this number to
     * `ap.bookings.intakeSchema` as the version its form is about to become, so a
     * mismatch here would hand a subscriber the wrong version to reason about.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service whose form is being edited.
     *
     * @return int The version the next save will write.
     */
    protected function nextVersion( Service $service ): int
    {
        $maxRecorded = (int) $service->intakeSchemaVersions()->max( 'version' );
        $current     = (int) $service->intake_schema_version;

        $backfills = is_array( $service->intake_schema ) && [] !== $service->intake_schema
            && ! $service->intakeSchemaVersions()->where( 'version', $current )->exists();

        return 1 + ( $backfills ? max( $maxRecorded, $current ) : $maxRecorded );
    }

    /**
     * Builds a storable schema from the editor's field rows.
     *
     * Each field's options are typed as one per line in the editor and split
     * back into a list here; a field type that does not offer choices keeps no
     * options at all rather than an empty list, so an unrelated edit does not
     * add a key to the stored form.
     *
     * @since 1.0.0
     *
     * @return array{fields: array<int, array<string, mixed>>} The schema.
     */
    protected function schemaFromFields(): array
    {
        $fields = [];

        foreach ( $this->fields as $field ) {
            $entry = [
                'name'     => trim( $field['name'] ),
                'type'     => $field['type'],
                'label'    => '' === trim( (string) $field['label'] ) ? trim( $field['name'] ) : trim( $field['label'] ),
                'required' => (bool) $field['required'],
            ];

            $options = $this->splitOptions( $field['options'] );

            if ( [] !== $options ) {
                $entry['options'] = $options;
            }

            $fields[] = $entry;
        }

        return [ 'fields' => $fields ];
    }

    /**
     * Reads the editor's field rows out of a stored schema.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>|null  $schema  The stored schema, or null.
     *
     * @return array<int, array{name: string, type: string, label: string, required: bool, options: string}> The editor rows.
     */
    protected function fieldsFromSchema( ?array $schema ): array
    {
        $rows = [];

        foreach ( $this->normaliseFields( $schema['fields'] ?? [] ) as $field ) {
            $rows[] = [
                '_key'     => $this->fieldKeySeq++,
                'name'     => $field['name'],
                'type'     => $field['type'],
                'label'    => $field['label'],
                'required' => $field['required'],
                'options'  => implode( "\n", $field['options'] ),
            ];
        }

        return $rows;
    }

    /**
     * Gets the schema recorded for a version, or the live form for the pending one.
     *
     * @since 1.0.0
     *
     * @param  int|null  $version  The version to read.
     *
     * @return array<string, mixed> The schema.
     */
    protected function schemaForVersion( ?int $version ): array
    {
        if ( null === $version ) {
            return $this->schemaFromFields();
        }

        $schema = IntakeSchemaVersion::query()
            ->forService( $this->serviceId )
            ->where( 'version', $version )
            ->value( 'schema' );

        return is_array( $schema ) ? $schema : [];
    }

    /**
     * Keys a schema's normalised fields by name for comparison.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $schema  The schema to read.
     *
     * @return array<string, array{type: string, label: string, required: bool, options: array<int, string>}> The fields, by name.
     */
    protected function fieldsByName( array $schema ): array
    {
        $byName = [];

        foreach ( $this->normaliseFields( $schema['fields'] ?? [] ) as $field ) {
            if ( '' === $field['name'] ) {
                continue;
            }

            $byName[ $field['name'] ] = [
                'type'     => $field['type'],
                'label'    => $field['label'],
                'required' => $field['required'],
                'options'  => $field['options'],
            ];
        }

        return $byName;
    }

    /**
     * Normalises whatever a schema put in its `fields` into predictable rows.
     *
     * @since 1.0.0
     *
     * @param  mixed  $fields  The raw fields.
     *
     * @return array<int, array{name: string, type: string, label: string, required: bool, options: array<int, string>}> The normalised fields.
     */
    protected function normaliseFields( mixed $fields ): array
    {
        if ( ! is_array( $fields ) ) {
            return [];
        }

        $normalised = [];

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            $name = is_string( $field['name'] ?? null ) ? trim( $field['name'] ) : '';

            $normalised[] = [
                'name'     => $name,
                'type'     => is_string( $field['type'] ?? null ) ? $field['type'] : 'text',
                'label'    => is_string( $field['label'] ?? null ) && '' !== trim( $field['label'] ) ? $field['label'] : $name,
                'required' => (bool) ( $field['required'] ?? false ),
                'options'  => $this->stringList( $field['options'] ?? [] ),
            ];
        }

        return $normalised;
    }

    /**
     * Splits an editor options box into a list of choices.
     *
     * One choice per line: a comma is an ordinary character inside a choice —
     * "Referral, word of mouth" is a single answer a form offers — so splitting
     * on it would cut real choices in half.
     *
     * @since 1.0.0
     *
     * @param  string  $options  The raw options box.
     *
     * @return array<int, string> The choices.
     */
    protected function splitOptions( string $options ): array
    {
        $lines = preg_split( '/\r\n|\r|\n/', $options ) ?: [];

        $choices = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );

            if ( '' !== $line ) {
                $choices[] = $line;
            }
        }

        return $choices;
    }

    /**
     * Gets a list of strings out of whatever a schema stored as choices.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The raw value.
     *
     * @return array<int, string> The choices, as strings.
     */
    protected function stringList( mixed $value ): array
    {
        if ( ! is_array( $value ) ) {
            return [];
        }

        $strings = [];

        foreach ( $value as $entry ) {
            if ( is_string( $entry ) || is_int( $entry ) || is_float( $entry ) ) {
                $strings[] = (string) $entry;
            }
        }

        return array_values( $strings );
    }

    /**
     * Points the diff view at the two most recent versions.
     *
     * The newest recorded version against the one before it — the change an
     * administrator most often wants to see. With only one version recorded
     * there is nothing to compare it to, so both ends rest on it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function resetDiffSelection(): void
    {
        $versions = IntakeSchemaVersion::query()
            ->forService( $this->serviceId )
            ->orderByDesc( 'version' )
            ->pluck( 'version' )
            ->all();

        $this->diffRight = $versions[0] ?? null;
        $this->diffLeft  = $versions[1] ?? ( $versions[0] ?? null );
    }
}
