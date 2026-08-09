<?php

/**
 * Webhook delivery status enum.
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
 * Where a single webhook delivery attempt ended up.
 *
 * `dead` is terminal and distinct from `failed`: a failed delivery is waiting
 * for its next attempt, a dead one has spent the retry budget and will never be
 * attempted again.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
enum WebhookDeliveryStatus: string
{
    /**
     * Determines whether a delivery in this status will be attempted again.
     *
     * @since 1.0.0
     *
     * @return bool True when the retry sweep can still pick the delivery up.
     */
    public function isRetryable(): bool
    {
        return match ( $this ) {
            self::Pending, self::Failed => true,
            self::Success, self::Dead   => false,
        };
    }
    case Pending = 'pending';

    case Success = 'success';

    case Failed = 'failed';

    case Dead = 'dead';
}
