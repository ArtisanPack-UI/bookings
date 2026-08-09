<?php

/**
 * Notification log factory.
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

use ArtisanPackUI\Bookings\Enums\NotificationStatus;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

use function fake;
use function now;

/**
 * Builds notification log entries.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<NotificationLog>
     */
    protected $model = NotificationLog::class;

    /**
     * Defines the model's default state.
     *
     * A confirmation rather than a reminder, and so with a null `scheduled_for`.
     * Reminders are deduplicated on `(booking_id, type, scheduled_for)`, so a
     * default that scheduled one would make `->count( 2 )` fail on the unique
     * index — for exactly the right reason, but not usefully.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        return [
            'booking_id'    => Booking::factory(),
            'channel'       => 'mail',
            'type'          => NotificationType::Confirmation,
            'recipient'     => fake()->safeEmail(),
            'scheduled_for' => null,
            'status'        => NotificationStatus::Pending,
            'error'         => null,
            'sent_at'       => null,
        ];
    }

    /**
     * Logs a reminder scheduled for a given moment.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string|null  $scheduledFor  When the reminder is
     *                                                       due. Defaults to a
     *                                                       day from now.
     *
     * @return static The configured factory.
     */
    public function reminder( DateTimeInterface|string|null $scheduledFor = null ): static
    {
        return $this->state( fn (): array => [
            'type'          => NotificationType::Reminder,
            'scheduled_for' => Carbon::parse( $scheduledFor ?? now()->addDay() ),
        ] );
    }

    /**
     * Marks the notification as sent.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function sent(): static
    {
        return $this->state( fn (): array => [
            'status'  => NotificationStatus::Sent,
            'sent_at' => now()->subMinutes( 5 ),
            'error'   => null,
        ] );
    }

    /**
     * Marks the notification as having failed to send.
     *
     * @since 1.0.0
     *
     * @param  string  $error  What went wrong.
     *
     * @return static The configured factory.
     */
    public function failed( string $error = 'The mail transport rejected the recipient.' ): static
    {
        return $this->state( fn (): array => [
            'status'  => NotificationStatus::Failed,
            'error'   => $error,
            'sent_at' => null,
        ] );
    }

    /**
     * Sends over a channel other than mail.
     *
     * @since 1.0.0
     *
     * @param  string  $channel  The channel name.
     *
     * @return static The configured factory.
     */
    public function onChannel( string $channel ): static
    {
        return $this->state( fn (): array => [ 'channel' => $channel ] );
    }
}
