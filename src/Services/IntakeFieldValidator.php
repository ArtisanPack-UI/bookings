<?php

/**
 * Intake field validator.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Services;

use ArtisanPackUI\Bookings\Exceptions\IntakeValidationException;
use ArtisanPackUI\Bookings\Models\IntakeSchemaVersion;
use ArtisanPackUI\Bookings\Models\Service;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Rule;
use UnexpectedValueException;

use function applyFilters;
use function array_key_exists;
use function array_values;
use function filter_var;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function trim;

/**
 * Checks a customer's intake answers against the form they were asked.
 *
 * The form is authored by an administrator at runtime and changes over the life
 * of a service, so "the form" is never a single thing — it is a version. Every
 * booking records the version it was captured against, and everything here
 * takes that version as an argument rather than reading the service's current
 * one. Validating last year's answers against this year's form is how you end
 * up rejecting a booking for missing a question nobody was ever asked.
 *
 * A schema is `[ 'fields' => [ [ 'name' => …, 'type' => …, 'required' => … ], … ] ]`.
 * The fields are a JSON *list*, not an object keyed by name, because MySQL
 * stores JSON objects sorted by key and hands them back that way — a form keyed
 * by field name would ask its questions in a different order there than on
 * sqlite or Postgres.
 *
 * Answers to fields the schema does not name are dropped rather than stored.
 * `intake_data` is written from a public request on a table an administrator
 * reads back, so accepting whatever arrives would make the column an
 * unvalidated dumping ground reachable by anybody who can reach the widget.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class IntakeFieldValidator
{
    /**
     * Constructs the validator.
     *
     * @since 1.0.0
     *
     * @param  ValidationFactory  $validator  The application's validation factory.
     */
    public function __construct(
        protected ValidationFactory $validator,
    ) {
    }

    /**
     * Validates a payload against one version of a service's intake form.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service whose form is being answered.
     * @param  int  $version  The schema version the answers were captured against.
     * @param  array<string, mixed>  $payload  The answers given.
     *
     * @throws IntakeValidationException When an answer is missing or unusable.
     * @throws UnexpectedValueException When a subscriber to
     *                                  `ap.bookings.intakeSchema` returns
     *                                  something that is not a schema.
     *
     * @return array<string, mixed>|null The accepted answers, restricted to the
     *                                   fields the schema names, or null when the
     *                                   form asked nothing.
     */
    public function validate( Service $service, int $version, array $payload ): ?array
    {
        $fields = $this->fields( $this->schemaFor( $service, $version ) );

        if ( [] === $fields ) {
            return null;
        }

        $rules      = [];
        $attributes = [];

        foreach ( $fields as $field ) {
            $rules[ $field['name'] ]      = $this->rulesFor( $field );
            $attributes[ $field['name'] ] = $field['label'];

            // A choice constraint on a multi-answer field belongs to each answer
            // rather than to the array holding them: `in:` against an array
            // never matches, so applying it at the top level would reject every
            // multi-select answer including the correct ones.
            if ( [] !== $field['options'] && $this->isMultiAnswer( $field['type'] ) ) {
                $rules[ $field['name'] . '.*' ]      = [ 'string', Rule::in( $field['options'] ) ];
                $attributes[ $field['name'] . '.*' ] = $field['label'];
            }
        }

        $validation = $this->validator->make( $payload, $rules, [], $attributes );

        if ( $validation->fails() ) {
            throw new IntakeValidationException( $validation->errors() );
        }

        $accepted = [];

        foreach ( $fields as $field ) {
            if ( array_key_exists( $field['name'], $payload ) ) {
                $accepted[ $field['name'] ] = $payload[ $field['name'] ];
            }
        }

        return [] === $accepted ? null : $accepted;
    }

    /**
     * Gets the schema a version of a service's form was recorded with.
     *
     * `ap.bookings.intakeSchema` runs on the snapshotted version rather than on
     * the service's current form, and its output is never written back. A
     * subscriber decorating the schema is describing how the form should be
     * read, not editing the record of what was asked — persisting that would
     * make the version row lie about history the next time anybody loaded it.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service whose form is wanted.
     * @param  int  $version  The version to look up.
     *
     * @throws UnexpectedValueException When a subscriber returns something other
     *                                  than an array.
     *
     * @return array<string, mixed> The recorded schema, filtered.
     */
    public function schemaFor( Service $service, int $version ): array
    {
        $schema = $service->intakeSchemaForVersion( $version );

        // A service whose form was never published through publish() has its
        // current schema on the service row and no version row behind it. That
        // is the ordinary state of a service seeded by a factory or an importer,
        // and refusing to read it would make every such booking unvalidatable.
        if ( null === $schema && $version === (int) $service->intake_schema_version ) {
            $schema = $service->intake_schema;
        }

        $filtered = applyFilters( 'ap.bookings.intakeSchema', $schema ?? [], $service, $version );

        if ( ! is_array( $filtered ) ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.intakeSchema must return an array, got %s.',
                get_debug_type( $filtered ),
            ) );
        }

        return $filtered;
    }

    /**
     * Records a new version of a service's intake form.
     *
     * Appends rather than replaces, and moves the service's pointer to the row
     * it just wrote. The two have to happen together: a service pointing at a
     * version that was never recorded takes every booking made against it with
     * it, since there is then no form to render their answers through.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service whose form changed.
     * @param  array<string, mixed>  $schema  The new form.
     *
     * @return IntakeSchemaVersion The version row that was written.
     */
    public function publish( Service $service, array $schema ): IntakeSchemaVersion
    {
        return $service->getConnection()->transaction( static function () use ( $service, $schema ): IntakeSchemaVersion {
            $current = (int) $service->intake_schema_version;

            // Back-fill the version the service is *currently* pointing at before
            // appending anything, when it has a form and nothing ever recorded
            // it. A service seeded or imported at version 1 keeps its form on the
            // service row alone, and bookings taken against it snapshot version 1
            // — so appending the edit as version 1 would rewrite the form those
            // bookings were captured with, which is the one thing versioning
            // exists to make impossible.
            //
            // Gated on there being a form, because a service that has never had
            // one has no history to protect: back-filling an empty version there
            // would only push every real version number up by one and leave a row
            // recording that nothing was ever asked.
            $backfill = $service->intake_schema;

            if (
                is_array( $backfill ) && [] !== $backfill
                && ! $service->intakeSchemaVersions()->where( 'version', $current )->exists()
            ) {
                $service->intakeSchemaVersions()->create( [
                    'version' => $current,
                    'schema'  => $backfill,
                ] );
            }

            $version = 1 + (int) $service->intakeSchemaVersions()->max( 'version' );

            $recorded = $service->intakeSchemaVersions()->create( [
                'version' => $version,
                'schema'  => $schema,
            ] );

            $service->forceFill( [
                'intake_schema'         => $schema,
                'intake_schema_version' => $version,
            ] )->save();

            return $recorded;
        } );
    }

    /**
     * Gets the fields a schema names, normalised.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $schema  The schema to read.
     *
     * @return array<int, array{name: string, type: string, label: string, required: bool, options: array<int, string>, rules: array<int, string>}> The fields.
     */
    protected function fields( array $schema ): array
    {
        $fields = $schema['fields'] ?? [];

        if ( ! is_array( $fields ) ) {
            return [];
        }

        $normalised = [];

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            $name = is_string( $field['name'] ?? null ) ? trim( $field['name'] ) : '';

            // A field with no name has nothing in the payload to match and
            // nothing to key an error under, so there is no way to enforce it
            // and no way to report that it was not enforced. Skipping is the
            // only outcome that does not silently reject valid bookings.
            if ( '' === $name ) {
                continue;
            }

            $normalised[] = [
                'name'     => $name,
                'type'     => is_string( $field['type'] ?? null ) ? $field['type'] : 'text',
                'label'    => is_string( $field['label'] ?? null ) ? $field['label'] : $name,
                'required' => $this->isTruthy( $field['required'] ?? null ),
                'options'  => $this->stringList( $field['options'] ?? [] ),
                'rules'    => $this->stringList( $field['rules'] ?? [] ),
            ];
        }

        return $normalised;
    }

    /**
     * Gets the validation rules one field's answer has to satisfy.
     *
     * The type is a hint about what a question is, not a promise about what
     * arrives — the payload comes off an HTTP request — so an unrecognised type
     * validates as a plain string rather than throwing. An administrator adding
     * a field type this package has not heard of should get a booking that
     * works, and a `rules` entry is where they say what it must look like.
     *
     * @since 1.0.0
     *
     * @param  array{name: string, type: string, label: string, required: bool, options: array<int, string>, rules: array<int, string>}  $field  The field.
     *
     * @return array<int, string> The rules, in the order they are applied.
     */
    protected function rulesFor( array $field ): array
    {
        $rules = [ $field['required'] ? 'required' : 'nullable' ];

        // `Rule::in` rather than an `in:` string. Laravel parses the string form
        // with `str_getcsv`, so an option holding a comma — "Referral, word of
        // mouth" is an ordinary thing to put on a form — silently becomes two
        // entries: the real answer is refused forever, and a value nobody
        // offered is accepted in its place.
        $choices = [] === $field['options'] ? [] : [ Rule::in( $field['options'] ) ];

        $typed = match ( $field['type'] ) {
            'email'                     => [ 'email' ],
            'url'                       => [ 'url' ],
            'number'                    => [ 'numeric' ],
            'integer'                   => [ 'integer' ],
            'date', 'datetime'          => [ 'date' ],
            'time'                      => [ 'date_format:H:i' ],
            'checkbox', 'boolean'       => [ 'boolean' ],
            'select', 'radio'           => [ 'string', ...$choices ],
            'multiselect', 'checkboxes' => [ 'array' ],
            default                     => [ 'string' ],
        };

        return [ ...$rules, ...$typed, ...$field['rules'] ];
    }

    /**
     * Determines whether a field type holds more than one answer.
     *
     * @since 1.0.0
     *
     * @param  string  $type  The field type.
     *
     * @return bool True when the answer is an array of answers.
     */
    protected function isMultiAnswer( string $type ): bool
    {
        return in_array( $type, [ 'multiselect', 'checkboxes' ], true );
    }

    /**
     * Reads a schema's idea of "yes".
     *
     * A schema is JSON authored by an administrator through a form builder, an
     * import, or by hand, so `required` arrives as `true`, `1`, `"1"`, `"true"`,
     * or `"yes"` depending on which. Insisting on a real boolean would be
     * defensible if it failed closed — but it fails *open*: every one of those
     * spellings would read as "optional" and the booking would be accepted with
     * the mandatory questions blank and no error anywhere to explain it.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The raw value.
     *
     * @return bool True when the schema meant yes.
     */
    protected function isTruthy( mixed $value ): bool
    {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_string( $value ) || is_int( $value ) ) {
            return true === filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
        }

        return false;
    }

    /**
     * Gets a list of strings out of whatever a schema put in a field.
     *
     * Numbers are kept rather than dropped, and that matters more than it looks.
     * A rating field offering `[ 1, 2, 3, 4, 5 ]` is ordinary JSON, and dropping
     * every entry would leave the list empty — which does not narrow the field to
     * nothing, it removes the choice constraint altogether and accepts anything
     * at all. Silently widening what a form accepts is the wrong way to be
     * strict about types.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The raw value.
     *
     * @return array<int, string> The entries, as strings.
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
}
