<?php

/**
 * Valid webhook URL rule.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Rules;

use ArtisanPackUI\Bookings\Services\WebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a webhook URL the guard would refuse to deliver to.
 *
 * The save-time half of the SSRF guard. Apply it to whatever field a screen
 * accepts an endpoint URL in, and a tenant cannot store an address that points
 * at loopback, the private network, or the cloud metadata endpoint in the first
 * place. Delivery re-checks the same guard {@see WebhookUrlGuard} regardless, so
 * a name repointed after it was stored is still caught — this rule is the early,
 * legible refusal, not the whole defence.
 *
 * The guard is resolved from the container rather than constructed, so the rule
 * sees the same `allowed_hosts` and scheme allowlist the delivery path does.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ValidWebhookUrl implements ValidationRule
{
    /**
     * Validates that the URL is one the guard allows.
     *
     * @since 1.0.0
     *
     * @param  string  $attribute  The attribute under validation.
     * @param  mixed  $value  The value under validation.
     * @param  Closure  $fail  The failure callback.
     *
     * @return void
     */
    public function validate( string $attribute, mixed $value, Closure $fail ): void
    {
        if ( ! is_string( $value ) ) {
            $fail( __( 'The webhook URL is not a valid absolute URL.' ) );

            return;
        }

        $refusal = app( WebhookUrlGuard::class )->inspect( $value );

        if ( null !== $refusal ) {
            $fail( $refusal );
        }
    }
}
