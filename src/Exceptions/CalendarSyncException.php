<?php

/**
 * Calendar sync exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Exceptions;

/**
 * A calendar sync driver could not carry out a write or a read.
 *
 * The {@see \ArtisanPackUI\Bookings\Contracts\CalendarSyncDriver} contract turns
 * on failures being loud: a driver that swallowed an error and returned normally
 * would make a broken connection look healthy while it silently stopped syncing.
 * The external calendar drivers throw this when a call to the calendar API comes
 * back as anything other than success, so the sync orchestrator records the
 * failure and — after enough of them — disables the connection.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class CalendarSyncException extends BookingException
{
}
