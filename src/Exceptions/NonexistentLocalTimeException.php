<?php

/**
 * Nonexistent local time exception.
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

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A wall-clock time names an instant the clocks skipped over.
 *
 * On the morning the clocks go forward, an hour of local time does not exist —
 * 02:30 in Chicago is neither before nor after the jump from 02:00 to 03:00. A
 * schedule or override authored inside that hour is not corrupt; it names a time
 * that is real on every other day of the year. This is thrown for exactly that
 * case, distinct from the plain `RuntimeException` a genuinely unreadable value
 * raises, so a caller resolving availability can clamp to the instant the clock
 * jumped to rather than 500 the provider's whole day — while a caller validating
 * a stored value still sees an exception.
 *
 * It carries {@see self::clampedInstant()}: the instant Carbon rolls the missing
 * local time to, which is the first representation of that wall time after the
 * transition.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class NonexistentLocalTimeException extends RuntimeException
{
    /**
     * Constructs the exception.
     *
     * @since 1.0.0
     *
     * @param  string  $message  What went wrong.
     * @param  Carbon  $clampedInstant  The post-transition instant to clamp to.
     */
    public function __construct(
        string $message,
        protected readonly Carbon $clampedInstant,
    ) {
        parent::__construct( $message );
    }

    /**
     * Gets the instant the missing local time clamps to.
     *
     * @since 1.0.0
     *
     * @return Carbon The post-transition instant.
     */
    public function clampedInstant(): Carbon
    {
        return $this->clampedInstant;
    }
}
