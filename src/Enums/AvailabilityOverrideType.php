<?php

/**
 * Availability override type enum.
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
 * What a single-date exception to a weekly schedule actually says.
 *
 * The two cases want different columns — `unavailable` needs no times at all,
 * `custom_hours` needs both — which is why this is an explicit type rather than
 * the boolean the original spec carried. A boolean would have had to mean
 * "blocked" and "these hours instead" at the same time.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum AvailabilityOverrideType: string
{
    case Unavailable = 'unavailable';

    case CustomHours = 'custom_hours';
}
