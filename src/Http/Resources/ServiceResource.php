<?php

/**
 * Public service API resource.
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

use ArtisanPackUI\Bookings\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use function config;

/**
 * A service as the public booking widget sees it.
 *
 * Deliberately narrower than the row. `site_id`, `metadata`, and
 * `default_provider_id` are all readable on the model and none of them are the
 * customer's business: the first two are where an installation keeps whatever
 * it wants to keep, and the third names a member of staff the widget is never
 * asked to show. Every field here is one the widget renders or branches on.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /**
     * Transforms the resource into an array.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return array<string, mixed> The service.
     */
    public function toArray( Request $request ): array
    {
        return [
            'id'                    => (int) $this->getKey(),
            'slug'                  => $this->slug,
            'name'                  => $this->name,
            'description'           => $this->description,
            'duration'              => (int) $this->duration,
            'buffer_before'         => (int) $this->buffer_before,
            'buffer_after'          => (int) $this->buffer_after,
            'price'                 => $this->price,
            'is_free'               => (bool) $this->is_free,
            'color'                 => $this->color,
            'image_url'             => $this->image_url,
            // Resolved rather than passed through, so a widget never has to
            // repeat the package's fallback chain to know which zone the
            // service's day is drawn in.
            'timezone'              => $this->timezone
                ?: ( config( 'artisanpack.bookings.timezone' ) ?: 'UTC' ),
            // What the widget branches on to decide whether to show a provider
            // picker at all: only "any" leaves the choice to the customer.
            'assignment_strategy'   => $this->assignment_strategy->value,
            'intake_schema'         => $this->intake_schema,
            'intake_schema_version' => (int) $this->intake_schema_version,
        ];
    }
}
