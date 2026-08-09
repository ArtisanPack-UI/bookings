<?php

/**
 * Service/provider pivot model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use InvalidArgumentException;

use function sprintf;

/**
 * The pairing of a provider with a service they offer.
 *
 * The row carries the per-pairing overrides — a senior provider charging more
 * for the same service, or taking longer over it — which is why it is a model
 * rather than a bare pivot table.
 *
 * There is no `site_id` here. Both sides of the pairing are already scoped to a
 * site, so a row cannot span two of them; a third copy of the same fact would
 * only create somewhere for it to disagree.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int $service_id
 * @property int $provider_id
 * @property string|null $custom_price
 * @property int|null $custom_duration
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ServiceProviderService extends Pivot
{
    /**
     * Indicates whether the model's ID is auto-incrementing.
     *
     * The pivot table has its own primary key, so the row is addressable on its
     * own rather than only through the pair it joins.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'service_provider_service';

    /**
     * The attributes that are mass assignable.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_id',
        'provider_id',
        'custom_price',
        'custom_duration',
    ];

    /**
     * The attributes that should be cast.
     *
     * Declared as a property rather than through the `casts()` method Laravel 11
     * introduced. The method does not exist on Laravel 10, where it is not
     * overriding anything and is simply never called — so every cast on every
     * model would quietly do nothing, and a JSON column would come back as a
     * string with no error to notice. The property is read by every version the
     * package's constraints allow.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    protected $casts = [
        'custom_price'    => 'decimal:2',
        'custom_duration' => 'integer',
    ];

    /**
     * Gets the effective price of the service for this provider.
     *
     * The service is passed in rather than loaded, because every caller already
     * has it — this is read while rendering a service's provider list — and a
     * lazy load here would be one query per provider on that page.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being priced.
     *
     * @throws InvalidArgumentException When given a service this row is not for.
     *
     * @return string|null The overridden price, or the service's own.
     */
    public function priceFor( Service $service ): ?string
    {
        $this->assertPairedWith( $service );

        return $this->custom_price ?? $service->price;
    }

    /**
     * Gets the effective duration of the service for this provider, in minutes.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service being scheduled.
     *
     * @throws InvalidArgumentException When given a service this row is not for.
     *
     * @return int The overridden duration, or the service's own.
     */
    public function durationFor( Service $service ): int
    {
        $this->assertPairedWith( $service );

        return $this->custom_duration ?? $service->duration;
    }

    /**
     * Refuses to answer for a service this row is not the pairing for.
     *
     * The overrides fall back to the service's own price and duration, so being
     * handed the wrong service does not fail — it quietly returns a number that
     * belongs to something else. That is a wrong invoice rather than an
     * exception, which is worth one comparison to rule out.
     *
     * @since 1.0.0
     *
     * @param  Service  $service  The service the caller believes this row is for.
     *
     * @throws InvalidArgumentException When the service is not this row's.
     *
     * @return void
     */
    private function assertPairedWith( Service $service ): void
    {
        if ( null === $this->service_id || $this->service_id === $service->getKey() ) {
            return;
        }

        throw new InvalidArgumentException( sprintf(
            'This pairing is for service %s, not service %s.',
            (string) $this->service_id,
            (string) $service->getKey(),
        ) );
    }
}
