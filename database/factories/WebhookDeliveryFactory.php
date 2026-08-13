<?php

/**
 * Webhook delivery factory.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Database\Factories;

use ArtisanPackUI\Bookings\Enums\WebhookDeliveryStatus;
use ArtisanPackUI\Bookings\Models\Webhook;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds webhook delivery attempts.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<WebhookDelivery>
     */
    protected $model = WebhookDelivery::class;

    /**
     * Defines the model's default state.
     *
     * Queued but not yet attempted, with a null `next_attempt_at` — which is
     * what a brand new delivery looks like, and which the due scope has to treat
     * as ready rather than as unscheduled.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        return [
            'webhook_id'      => Webhook::factory(),
            'event_type'      => fake()->randomElement( [
                'booking.created',
                'booking.cancelled',
                'booking.rescheduled',
            ] ),
            'payload'         => [
                'id'   => fake()->numberBetween( 1, 9999 ),
                'type' => 'booking',
                'data' => [ 'booking_number' => 'BK-' . Str::upper( Str::random( 12 ) ) ],
            ],
            'attempt_number'  => 1,
            'status'          => WebhookDeliveryStatus::Pending,
            'response_status' => null,
            'response_body'   => null,
            'next_attempt_at' => null,
            'attempted_at'    => null,
            'succeeded_at'    => null,
        ];
    }

    /**
     * Leaves the delivery queued.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function pending(): static
    {
        return $this->state( fn (): array => [
            'status'          => WebhookDeliveryStatus::Pending,
            'attempted_at'    => null,
            'succeeded_at'    => null,
            'response_status' => null,
            // Cleared, so that `->failed()->pending()` describes a queued
            // delivery rather than one carrying a retry time from the state it
            // was chained onto — which the due sweep would then skip until that
            // stale moment passed.
            'next_attempt_at' => null,
        ] );
    }

    /**
     * Marks the delivery as accepted by the endpoint.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function success(): static
    {
        return $this->state( fn (): array => [
            'status'          => WebhookDeliveryStatus::Success,
            'response_status' => 200,
            'response_body'   => '{"ok":true}',
            'attempted_at'    => now()->subMinutes( 5 ),
            'succeeded_at'    => now()->subMinutes( 5 ),
            'next_attempt_at' => null,
        ] );
    }

    /**
     * Marks the delivery as failed and waiting on a retry.
     *
     * @since 1.0.0
     *
     * @param  int  $minutesUntilRetry  How long until the next attempt is due.
     *
     * @return static The configured factory.
     */
    public function failed( int $minutesUntilRetry = 5 ): static
    {
        return $this->state( fn (): array => [
            'status'          => WebhookDeliveryStatus::Failed,
            'attempt_number'  => fake()->numberBetween( 2, 4 ),
            'response_status' => 500,
            'response_body'   => 'Internal Server Error',
            'attempted_at'    => now()->subMinutes( 10 ),
            'next_attempt_at' => now()->addMinutes( $minutesUntilRetry ),
        ] );
    }

    /**
     * Marks the delivery as beyond retrying.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function dead(): static
    {
        return $this->state( fn (): array => [
            'status'          => WebhookDeliveryStatus::Dead,
            'attempt_number'  => 5,
            'response_status' => 500,
            'response_body'   => 'Internal Server Error',
            'attempted_at'    => now()->subHours( 12 ),
            'next_attempt_at' => null,
        ] );
    }

    /**
     * Makes the delivery due for its next attempt now.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function due(): static
    {
        return $this->state( fn (): array => [
            'status'          => WebhookDeliveryStatus::Failed,
            'next_attempt_at' => now()->subMinute(),
        ] );
    }
}
