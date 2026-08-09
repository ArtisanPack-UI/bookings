<?php

/**
 * Service assignment strategy enum.
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
 * The rule a service uses to pick a provider when the customer does not.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum ServiceAssignmentStrategy: string
{
    case Any = 'any';

    case RoundRobin = 'round_robin';

    case DefaultProvider = 'default_provider';
}
