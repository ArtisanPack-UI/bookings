<?php

/**
 * Two-way calendar sync driver contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Contracts;

use ArtisanPackUI\Bookings\Models\CalendarConnection;
use ArtisanPackUI\Bookings\Models\CalendarWatchChannel;
use Throwable;

/**
 * A calendar driver that also reads its calendar back.
 *
 * {@see CalendarSyncDriver} is the outbound surface every driver has — writing a
 * booking out and, at most, answering a busy-period read. This extends it with
 * the inbound surface a two-way driver adds: pulling the external calendar's own
 * events in as busy blocks so they suppress availability, and the push-channel
 * registration that lets the external calendar say when something moved.
 *
 * The read-only {@see \ArtisanPackUI\Bookings\Drivers\Calendar\IcalFeedDriver}
 * has none of this and deliberately does not implement this contract; the sweep
 * commands and any other caller test for it with `instanceof` before reaching
 * for a method only a two-way driver has.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
interface TwoWayCalendarDriver extends CalendarSyncDriver
{
    /**
     * Pulls the external calendar's changes in as busy blocks.
     *
     * Self-contained: it reads the change feed from the connection's stored
     * cursor (or does a bounded full read when there is none), writes the busy
     * blocks itself, and advances the cursor. This is what the daily refresh
     * sweep drives, so a dropped push or a connection that never registered one
     * still has its busy blocks brought up to date.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to read.
     *
     * @throws Throwable When the external calendar refuses or is unreachable.
     *
     * @return void
     */
    public function incrementalSync( CalendarConnection $connection ): void;

    /**
     * Registers a push channel so the calendar reports changes to this connection.
     *
     * The callback URL is passed in rather than resolved here: where an inbound
     * notification is received is the caller's concern, not the driver's.
     *
     * @since 1.0.0
     *
     * @param  CalendarConnection  $connection  The connection to watch.
     * @param  string  $callbackUrl  Where the calendar should post notifications.
     *
     * @throws Throwable When the external calendar refuses or is unreachable.
     *
     * @return CalendarWatchChannel The stored registration.
     */
    public function subscribeToChanges( CalendarConnection $connection, string $callbackUrl ): CalendarWatchChannel;

    /**
     * Replaces a push channel that is expiring or has expired.
     *
     * @since 1.0.0
     *
     * @param  CalendarWatchChannel  $channel  The registration to replace.
     * @param  string  $callbackUrl  Where the calendar should post notifications.
     *
     * @throws Throwable When the external calendar refuses to register the new
     *                   channel or is unreachable.
     *
     * @return CalendarWatchChannel The renewed registration.
     */
    public function renewSubscription( CalendarWatchChannel $channel, string $callbackUrl ): CalendarWatchChannel;
}
