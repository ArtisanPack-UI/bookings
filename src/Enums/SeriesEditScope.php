<?php

/**
 * Series edit scope enum.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Enums;

/**
 * How far through a recurring series an edit reaches.
 *
 * These are the three choices every calendar application offers when you edit a
 * repeating appointment, and they mean different things to the data:
 *
 * - `This` detaches a single occurrence from its rule. The rule is untouched;
 *   the row stops being re-derivable from it, which is what
 *   `bookings.detached_from_series_at` records.
 * - `ThisAndFollowing` splits the series in two — the original rule is bounded
 *   at the occurrence, and a new series carries the change forward.
 * - `All` rewrites the rule itself. Occurrences already detached stay detached,
 *   because a detached occurrence is no longer described by the rule.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum SeriesEditScope: string
{
    case This = 'this';

    case ThisAndFollowing = 'this_and_following';

    case All = 'all';
}
