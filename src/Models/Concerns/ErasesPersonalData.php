<?php

/**
 * Personal data erasure concern.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Gives a model the `pii_erased_at` marker and the reads that depend on it.
 *
 * Erasure and deletion are different things and a model that holds personal
 * data needs both. A soft delete hides a row while keeping the audit trail;
 * erasure applies to rows that have to keep existing — a booking somebody was
 * invoiced for — and overwrites the personal columns in place.
 *
 * Because the redaction is a placeholder rather than a null, the row still
 * looks populated afterwards. `pii_erased_at` is the only thing that
 * distinguishes an erased row from a real one, which is why the erasure routine
 * has to set it in the same write that redacts the fields, and why anything
 * rendering customer data should ask {@see self::isPiiErased()} before treating
 * what it reads as a real name.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @mixin Model
 */
trait ErasesPersonalData
{
    /**
     * Determines whether this row's personal data has been erased.
     *
     * @since 1.0.0
     *
     * @return bool True when the personal columns hold redaction placeholders.
     */
    public function isPiiErased(): bool
    {
        return null !== $this->getAttribute( 'pii_erased_at' );
    }

    /**
     * Scopes a query to rows whose personal data has been erased.
     *
     * @since 1.0.0
     *
     * @param  Builder<Model>  $query  The query being built.
     *
     * @return Builder<Model> The scoped query.
     */
    public function scopePiiErased( Builder $query ): Builder
    {
        return $query->whereNotNull( $this->qualifyColumn( 'pii_erased_at' ) );
    }

    /**
     * Scopes a query to rows whose personal data is still intact.
     *
     * @since 1.0.0
     *
     * @param  Builder<Model>  $query  The query being built.
     *
     * @return Builder<Model> The scoped query.
     */
    public function scopeNotPiiErased( Builder $query ): Builder
    {
        return $query->whereNull( $this->qualifyColumn( 'pii_erased_at' ) );
    }
}
