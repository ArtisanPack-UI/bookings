<?php

/**
 * Public service resolution.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Http\Controllers\Public\Concerns;

use ArtisanPackUI\Bookings\Models\Service;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function __;

/**
 * One answer to "which service is this, and may the public book it".
 *
 * Shared rather than repeated because the two controllers have to agree. A
 * widget that listed a service the booking endpoint then refused — or the
 * reverse — would be a bug nobody could reproduce from either half.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
trait ResolvesPublicService
{
    /**
     * Gets the bookable service a slug names, or gives up.
     *
     * Raises the 404 itself rather than leaning on `firstOrFail()`, whose
     * message names the model class — which is a detail of this package's
     * internals being handed to anybody who guesses a wrong slug.
     *
     * @since 1.0.0
     *
     * @param  string  $slug  The slug from the request.
     *
     * @throws NotFoundHttpException When no active service answers to the slug.
     *
     * @return Service The active service.
     */
    protected function resolvePublicService( string $slug ): Service
    {
        $service = Service::query()
            ->active()
            ->where( 'slug', $slug )
            ->first();

        if ( ! $service instanceof Service ) {
            throw new NotFoundHttpException( __( 'No bookable service was found for that address.' ) );
        }

        return $service;
    }
}
