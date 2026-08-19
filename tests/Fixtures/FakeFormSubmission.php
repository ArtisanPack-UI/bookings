<?php

/**
 * A stand-in for a forms submission.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

/**
 * Mimics the slice of `artisanpack-ui/forms`' FormSubmission the listener reads.
 *
 * The forms package is a `suggest`, so its FormSubmission model is genuinely
 * absent from this package's test suite. {@see \ArtisanPackUI\Bookings\Integrations\Forms\FormBookingListener}
 * is written against the accessors every forms submission exposes rather than
 * the class itself — it types what it is handed `object` — so this reproduces
 * exactly those accessors: the `values` collection it walks to find the picked
 * slot, `getValue()` for a control field by name, and `getEmailValue()` for the
 * first email answer. Nothing more, because nothing more is read.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class FakeFormSubmission
{
    /**
     * The submitted values, as objects carrying name, type, and value.
     *
     * @since 1.0.0
     *
     * @var array<int, object>
     */
    public array $values;

    /**
     * Builds a submission from plain value rows.
     *
     * A row may carry a `config` array, which is attached as the value's
     * `->field->field_config` — the shape the listener reads a booking_slot
     * field's contact mappings from.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{name: string, type: string, value: string|null, config?: array<string, mixed>}>  $values  The rows.
     */
    public function __construct( array $values )
    {
        $this->values = array_map(
            static fn ( array $row ): object => (object) [
                'field_name'  => $row['name'],
                'field_type'  => $row['type'],
                'value'       => $row['value'],
                'field'       => (object) [ 'field_config' => $row['config'] ?? [] ],
            ],
            $values,
        );
    }

    /**
     * Gets a submitted value by field name.
     *
     * @since 1.0.0
     *
     * @param  string  $fieldName  The field name.
     *
     * @return string|null The value, or null when the field was not answered.
     */
    public function getValue( string $fieldName ): ?string
    {
        foreach ( $this->values as $value ) {
            if ( $fieldName === $value->field_name ) {
                return null === $value->value ? null : (string) $value->value;
            }
        }

        return null;
    }

    /**
     * Gets the first email answer.
     *
     * @since 1.0.0
     *
     * @return string|null The email, or null when none was an email field.
     */
    public function getEmailValue(): ?string
    {
        foreach ( $this->values as $value ) {
            if ( 'email' === $value->field_type ) {
                return null === $value->value ? null : (string) $value->value;
            }
        }

        return null;
    }
}
