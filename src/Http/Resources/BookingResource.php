<?php

/**
 * Public booking API resource.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Http\Resources;

use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking as the customer who just made it sees it.
 *
 * The manage token is conspicuously absent, and has to stay absent. The plain
 * token exists exactly once, in the confirmation email — that is the whole
 * mechanism (§9.2): only its hash is stored, so an endpoint that returned the
 * plain value would be handing out a credential over an unauthenticated
 * response and quietly turning a one-copy secret into a two-copy one.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    /**
     * Transforms the resource into an array.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return array<string, mixed> The booking.
     */
    public function toArray( Request $request ): array
    {
        return [
            'id'                => (int) $this->getKey(),
            'status'            => $this->status->value,
            'start_time'        => $this->start_time?->utc()->toIso8601String(),
            'end_time'          => $this->end_time?->utc()->toIso8601String(),
            'customer_name'     => $this->customer_name,
            'customer_email'    => $this->customer_email,
            'customer_timezone' => $this->customer_timezone,
            'service'           => [
                'id'   => (int) $this->service_id,
                'slug' => $this->service?->slug,
                'name' => $this->service?->name,
            ],
            'provider'          => null === $this->provider_id ? null : [
                'id'   => (int) $this->provider_id,
                'slug' => $this->provider?->slug,
                'name' => $this->provider?->name,
            ],
        ];
    }
}
