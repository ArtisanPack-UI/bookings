<?php

/**
 * Public service provider API resource.
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

use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider as the public booking widget sees it.
 *
 * `email` and `phone` are on the row and are not here. They are how the package
 * reaches a member of staff, not something a customer picking an appointment
 * needs — and an unauthenticated endpoint that lists every provider's address
 * is a staff directory anybody can scrape.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @mixin ServiceProvider
 */
class ServiceProviderResource extends JsonResource
{
    /**
     * Transforms the resource into an array.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return array<string, mixed> The provider.
     */
    public function toArray( Request $request ): array
    {
        return [
            'id'        => (int) $this->getKey(),
            'slug'      => $this->slug,
            'name'      => $this->name,
            'bio'       => $this->bio,
            'timezone'  => $this->timezone,
            'image_url' => $this->image_url,
        ];
    }
}
