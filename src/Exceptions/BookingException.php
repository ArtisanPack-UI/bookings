<?php

/**
 * Base bookings exception.
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

use RuntimeException;

/**
 * The base every exception this package throws on its own extends.
 *
 * A caller booking on behalf of somebody else — a controller, a Livewire
 * component, a form-submission listener — wants to tell "the domain refused
 * this" apart from "something went wrong", and catching a shared base is the
 * only way to do that without enumerating subclasses that do not exist yet.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingException extends RuntimeException
{
}
