<?php

/**
 * Booking assignment strategy enum.
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
 * How a booking came to be assigned to the provider it has.
 *
 * This records what actually happened to one booking, which is why it carries
 * cases the service-level {@see ServiceAssignmentStrategy} does not: a staff
 * member or an API client can assign a booking regardless of the rule the
 * service was configured with.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum BookingAssignmentStrategy: string
{
    case Customer = 'customer';

    case RoundRobin = 'round_robin';

    case Admin = 'admin';

    case Api = 'api';

    case DefaultProvider = 'default_provider';
}
