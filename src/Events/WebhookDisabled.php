<?php

/**
 * Webhook disabled event.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Events;

use ArtisanPackUI\Bookings\Models\Webhook;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an outbound webhook endpoint stops being delivered to.
 *
 * An endpoint that fails often enough is disabled rather than retried forever,
 * and the consumer on the other end has no way of noticing that on their own.
 * This event is where an application hooks in to tell them.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class WebhookDisabled
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Constructs the event.
     *
     * @since 1.0.0
     *
     * @param  Webhook  $webhook  The webhook that was disabled.
     * @param  string  $reason  Why it was disabled.
     */
    public function __construct(
        public Webhook $webhook,
        public string $reason,
    ) {
    }
}
