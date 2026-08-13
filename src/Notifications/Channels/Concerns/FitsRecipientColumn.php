<?php

/**
 * Recipient column fitting concern.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Notifications\Channels\Concerns;

/**
 * Keeps a channel's logged recipient inside the column that has to hold it.
 *
 * `booking_notification_log.recipient` is a `varchar(255)`, and a staff-facing
 * channel builds its value from a list rather than a single address — so a wide
 * enough audience overruns it. What happens then is decided by the connection
 * rather than by this package: MySQL out of strict mode truncates mid-key and
 * leaves a reference that resolves to the wrong people, and in strict mode it
 * rejects the insert outright, which turns a wide notification into a failed
 * send. Neither is acceptable, and both are invisible until the audience grows.
 *
 * Shared rather than written twice because the limit is a property of the
 * schema, and two copies of a number like that drift the moment one column is
 * widened.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
trait FitsRecipientColumn
{
    /**
     * The longest recipient string the log column can hold.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected static int $recipientLimit = 255;

    /**
     * Gets a reference that fits, summarising the audience when it does not.
     *
     * The summary keeps the audience readable rather than truncating it into
     * something that looks precise and is not. Where the notification actually
     * went is recorded in full by whichever system delivered it.
     *
     * @since 1.0.0
     *
     * @param  string  $reference  The full reference.
     * @param  string  $label  What the audience is, for the summary.
     * @param  int  $count  How many recipients it names.
     *
     * @return string The reference, or a summary of it.
     */
    protected function fitRecipient( string $reference, string $label, int $count ): string
    {
        if ( mb_strlen( $reference ) <= self::$recipientLimit ) {
            return $reference;
        }

        return mb_substr(
            sprintf( '%s (%d recipients)', $label, $count ),
            0,
            self::$recipientLimit,
        );
    }
}
