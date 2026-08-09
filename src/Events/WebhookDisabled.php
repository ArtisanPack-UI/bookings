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
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an outbound webhook endpoint stops being delivered to.
 *
 * An endpoint that fails often enough is disabled rather than retried forever,
 * and the consumer on the other end has no way of noticing that on their own.
 * This event is where an application hooks in to tell them.
 *
 * The payload is readonly and dispatched after commit. Plan §5.8 writes
 * bookings inside a transaction and behind an advisory lock, and
 * {@see SerializesModels} restores a payload by re-reading it from the
 * database — so an event dispatched mid-transaction can reach a queue worker on
 * another connection before the commit lands, and the listener dies with a
 * ModelNotFoundException on a row that does exist. Outside a transaction the
 * interface changes nothing.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class WebhookDisabled implements ShouldDispatchAfterCommit
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
        public readonly Webhook $webhook,
        public readonly string $reason,
    ) {
    }
}
