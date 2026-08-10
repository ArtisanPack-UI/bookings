<?php

/**
 * Booking webhook listener.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Listeners;

use ArtisanPackUI\Bookings\Events\BookingCancelled;
use ArtisanPackUI\Bookings\Events\BookingCompleted;
use ArtisanPackUI\Bookings\Events\BookingConfirmed;
use ArtisanPackUI\Bookings\Events\BookingNoShow;
use ArtisanPackUI\Bookings\Events\BookingRequested;
use ArtisanPackUI\Bookings\Events\BookingRescheduled;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Services\WebhookDispatcher;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;

/**
 * Turns booking lifecycle events into outbound webhook deliveries.
 *
 * Hung off the events for the reason the events exist: a booking can be
 * confirmed by the widget, by an admin screen, by an importer, or by a series
 * materialising, and every one of those goes through the same event. A call
 * inside {@see \ArtisanPackUI\Bookings\Services\BookingService} would cover them
 * all too, but it would put an endpoint list query — and, on a `sync` queue, the
 * whole HTTP delivery — inside the advisory lock and the transaction a booking
 * write holds, where a slow consumer becomes a slot nobody else can book.
 *
 * The events are `ShouldDispatchAfterCommit`, so by the time this runs the
 * booking is committed and readable by the worker that will deliver it.
 *
 * **The site comes from the booking, not from the context.** These events are
 * raised from console commands as well as from requests — a completion sweep, a
 * series materialising overnight — and in console nothing has resolved a site,
 * which leaves the global scope inert and every tenant's endpoints matching. One
 * tenant's customer arriving at another tenant's CRM is the kind of bug that is
 * found by the wrong person, so the booking's own `site_id` is passed through
 * rather than left to whatever happens to be in context.
 *
 * **Not `ShouldQueue`.** All it does is write a row per endpoint and push a job;
 * the delivery itself is already queued. Queueing this as well would buy a
 * second retry policy on top of the one in the ledger and a second chance to
 * fan the same event out twice.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class DispatchBookingWebhooks
{
    /**
     * Constructs the listener.
     *
     * @since 1.0.0
     *
     * @param  WebhookDispatcher  $webhooks  The webhook dispatcher.
     */
    public function __construct( protected WebhookDispatcher $webhooks )
    {
    }

    /**
     * Registers the listener's handlers with the event dispatcher.
     *
     * @since 1.0.0
     *
     * @param  Dispatcher  $events  The event dispatcher.
     *
     * @return array<class-string, string> The events mapped to their handlers.
     */
    public function subscribe( Dispatcher $events ): array
    {
        return [
            BookingRequested::class   => 'handleRequested',
            BookingConfirmed::class   => 'handleConfirmed',
            BookingRescheduled::class => 'handleRescheduled',
            BookingCancelled::class   => 'handleCancelled',
            BookingCompleted::class   => 'handleCompleted',
            BookingNoShow::class      => 'handleNoShow',
        ];
    }

    /**
     * Fans a newly requested booking out as `booking.created`.
     *
     * Named for the row rather than for the status. `booking.created` is what an
     * integration subscribes to when it wants to hear about every booking as it
     * arrives, including the ones an application holds for approval — and a
     * configuration that auto-confirms sends `booking.confirmed` immediately
     * after, which is the pair a consumer expects.
     *
     * @since 1.0.0
     *
     * @param  BookingRequested  $event  The event.
     *
     * @return void
     */
    public function handleRequested( BookingRequested $event ): void
    {
        $this->fanOut( 'booking.created', $event->booking );
    }

    /**
     * Fans a confirmed booking out as `booking.confirmed`.
     *
     * @since 1.0.0
     *
     * @param  BookingConfirmed  $event  The event.
     *
     * @return void
     */
    public function handleConfirmed( BookingConfirmed $event ): void
    {
        $this->fanOut( 'booking.confirmed', $event->booking, [ 'actor' => $event->actor->value ] );
    }

    /**
     * Fans a moved booking out as `booking.rescheduled`.
     *
     * @since 1.0.0
     *
     * @param  BookingRescheduled  $event  The event.
     *
     * @return void
     */
    public function handleRescheduled( BookingRescheduled $event ): void
    {
        $this->fanOut( 'booking.rescheduled', $event->booking, [
            'actor'           => $event->actor->value,
            'previous_period' => $event->previousPeriod->toArray(),
        ] );
    }

    /**
     * Fans a cancelled booking out as `booking.cancelled`.
     *
     * @since 1.0.0
     *
     * @param  BookingCancelled  $event  The event.
     *
     * @return void
     */
    public function handleCancelled( BookingCancelled $event ): void
    {
        $this->fanOut( 'booking.cancelled', $event->booking, [
            'actor'  => $event->actor->value,
            'reason' => $event->reason,
        ] );
    }

    /**
     * Fans a completed booking out as `booking.completed`.
     *
     * @since 1.0.0
     *
     * @param  BookingCompleted  $event  The event.
     *
     * @return void
     */
    public function handleCompleted( BookingCompleted $event ): void
    {
        $this->fanOut( 'booking.completed', $event->booking, [ 'actor' => $event->actor->value ] );
    }

    /**
     * Fans a missed booking out as `booking.no_show`.
     *
     * @since 1.0.0
     *
     * @param  BookingNoShow  $event  The event.
     *
     * @return void
     */
    public function handleNoShow( BookingNoShow $event ): void
    {
        $this->fanOut( 'booking.no_show', $event->booking, [ 'actor' => $event->actor->value ] );
    }

    /**
     * Builds the body a consumer receives.
     *
     * The envelope is `event`, `occurred_at`, and `data`, and the booking sits
     * under `data.booking` rather than at the root, so that an event later
     * needing to carry something beside the booking — the series it belongs to,
     * the endpoint it was routed by — can add a key without moving the one every
     * consumer is already reading.
     *
     * Times go out in UTC as ISO 8601, with the customer's zone named beside
     * them rather than applied to them. A consumer that wants to show the
     * customer their own clock face has what it needs; one that wants an instant
     * is not made to guess which of the two it has been given.
     *
     * The service and the provider are named as well as identified. An
     * integration writing a CRM note wants "Consultation with Dana", and making
     * it fetch two more records over an API to render one line is the difference
     * between a webhook being useful and being a poll trigger.
     *
     * @since 1.0.0
     *
     * @param  string  $event  The event name.
     * @param  Booking  $booking  The booking it concerns.
     * @param  array<string, mixed>  $extra  Anything the event adds beside it.
     *
     * @return array<string, mixed> The event body.
     */
    protected function payloadFor( string $event, Booking $booking, array $extra = [] ): array
    {
        $booking->loadMissing( [ 'service', 'provider' ] );

        return [
            'event'       => $event,
            'occurred_at' => Carbon::now()->utc()->toIso8601String(),
            'data'        => [
                'booking' => [
                    'id'             => $booking->getKey(),
                    'booking_number' => $booking->booking_number,
                    'status'         => $booking->status->value,
                    'start_time'     => $booking->start_time?->utc()->toIso8601String(),
                    'end_time'       => $booking->end_time?->utc()->toIso8601String(),
                    'series_id'      => $booking->series_id,
                    'series_index'   => $booking->series_index,
                    'notes'          => $booking->notes,
                    'intake_data'    => $booking->intake_data,
                    'created_at'     => $booking->created_at?->utc()->toIso8601String(),
                    'customer'       => [
                        'name'     => $booking->customer_name,
                        'email'    => $booking->customer_email,
                        'phone'    => $booking->customer_phone,
                        'timezone' => $booking->customer_timezone,
                    ],
                    'service'        => null === $booking->service ? null : [
                        'id'   => $booking->service->getKey(),
                        'name' => $booking->service->name,
                        'slug' => $booking->service->slug,
                    ],
                    'provider'       => null === $booking->provider ? null : [
                        'id'   => $booking->provider->getKey(),
                        'name' => $booking->provider->name,
                        'slug' => $booking->provider->slug,
                    ],
                ],
            ] + $extra,
        ];
    }

    /**
     * Queues an event for the booking's own site's endpoints.
     *
     * @since 1.0.0
     *
     * @param  string  $event  The event name.
     * @param  Booking  $booking  The booking it concerns.
     * @param  array<string, mixed>  $extra  Anything the event adds beside it.
     *
     * @return void
     */
    protected function fanOut( string $event, Booking $booking, array $extra = [] ): void
    {
        $this->webhooks->dispatch(
            $event,
            $this->payloadFor( $event, $booking, $extra ),
            $booking->site_id,
        );
    }
}
