<?php

/**
 * Reminder scheduler.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Services;

use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use UnexpectedValueException;

use function applyFilters;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function config;
use function get_debug_type;
use function is_array;
use function is_numeric;
use function max;
use function rsort;
use function sprintf;

/**
 * Works out which reminders are due, and sends them.
 *
 * A reminder is identified by the moment it was scheduled for — the booking's
 * start minus a configured number of hours — and that moment is what goes into
 * the notification log's unique key. So the cadence is not merely a setting: it
 * is the deduplication key, and two runs of the cron computing the same cadence
 * compute the same key and claim the same row.
 *
 * The cron is expected to run more often than the window it sweeps, and to
 * overlap itself occasionally. Both are fine. A reminder whose moment has passed
 * but whose row has not been claimed is due; once claimed it never is again.
 *
 * **Late is better than never, within reason.** A cron that was down for an hour
 * comes back and finds reminders whose moment passed while it was gone, and
 * sends them — a reminder an hour late is worth more than no reminder. What it
 * does not do is send one *after* the appointment has started, which would be a
 * reminder to attend something already under way.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ReminderScheduler
{
    /**
     * Constructs the scheduler.
     *
     * @since 1.0.0
     *
     * @param  NotificationService  $notifications  The notification service.
     */
    public function __construct( protected NotificationService $notifications )
    {
    }

    /**
     * Sends every reminder that has come due.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable|null  $now  The moment to treat as now.
     *
     * @return int How many reminders were sent.
     */
    public function sendDue( ?CarbonImmutable $now = null ): int
    {
        $now  = $now ?? CarbonImmutable::now();
        $sent = 0;

        foreach ( $this->candidates( $now ) as $booking ) {
            foreach ( $this->dueMomentsFor( $booking, $now ) as $moment ) {
                $sent += [] === $this->notifications->send( NotificationType::Reminder, $booking, $moment )
                    ? 0
                    : 1;
            }
        }

        return $sent;
    }

    /**
     * Gets the moments a booking's reminders are scheduled for.
     *
     * Duplicates are removed after the filter rather than trusted away. The
     * unique index already makes a repeated window harmless — the second claim
     * loses — but relying on that alone would mean a subscriber appending a
     * window the config already has produced an INSERT that fails on every cron
     * run forever, logging a duplicate-claim line each time for something that
     * was never going to send.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to schedule reminders for.
     *
     * @throws UnexpectedValueException When a subscriber returns a non-array.
     *
     * @return array<int, CarbonImmutable> The scheduled moments, ascending.
     */
    public function momentsFor( Booking $booking ): array
    {
        /** @var array<int, mixed> $configured */
        $configured = (array) config( 'artisanpack.bookings.notifications.reminder.hours_before', [] );

        $filtered = applyFilters(
            'ap.bookings.reminderScheduling',
            array_values( $configured ),
            $booking,
        );

        if ( ! is_array( $filtered ) ) {
            throw new UnexpectedValueException( sprintf(
                'ap.bookings.reminderScheduling must return an array, got %s.',
                get_debug_type( $filtered ),
            ) );
        }

        $hours = array_values( array_unique( array_map(
            // Rounded to the hour the cadence is expressed in, because the
            // moment derived from it is a deduplication key: 24 and 24.4 would
            // otherwise be two reminders a customer reads as one sent twice.
            static fn ( mixed $hour ): int => (int) $hour,
            array_filter(
                $filtered,
                static fn ( mixed $hour ): bool => is_numeric( $hour ) && (int) $hour > 0,
            ),
        ) ) );

        // Descending by hours-before, which is ascending by moment: the
        // 48-hour reminder happens before the 24-hour one. Sorting the other way
        // would return the moments newest-first and send a booking's reminders
        // in reverse, which matters once a catch-up run has more than one due.
        rsort( $hours );

        $start = CarbonImmutable::instance( $booking->start_time );

        return array_map(
            static fn ( int $hour ): CarbonImmutable => $start->subHours( $hour ),
            $hours,
        );
    }

    /**
     * Gets the reminder moments for a booking that have come due.
     *
     * @since 1.0.0
     *
     * @param  Booking  $booking  The booking to check.
     * @param  CarbonImmutable  $now  The moment to treat as now.
     *
     * @return array<int, CarbonImmutable> The moments that are due.
     */
    protected function dueMomentsFor( Booking $booking, CarbonImmutable $now ): array
    {
        return array_values( array_filter(
            $this->momentsFor( $booking ),
            static fn ( CarbonImmutable $moment ): bool => $moment->lessThanOrEqualTo( $now ),
        ) );
    }

    /**
     * Gets the bookings that could have a reminder due.
     *
     * Bounded at both ends. Anything already started is past reminding, and the
     * lookahead is the longest configured window plus a margin, so a run does not
     * walk every future booking in the system to discover that none of them are
     * due for a week.
     *
     * **Deliberately unscoped by site.** The sweep runs from cron, where no site
     * is in context and the scope is already inert — but an application whose
     * resolver answers in console too would otherwise have this quietly sweep one
     * tenant and leave every other customer without a reminder, with nothing
     * anywhere to say so. Reading across sites is safe in a way that writing
     * across them is not: each reminder goes to the customer on the booking, with
     * that booking's own details, so there is nothing here that could reach the
     * wrong tenant. The notification log carries no `site_id` of its own.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable  $now  The moment to treat as now.
     *
     * @return Collection<int, Booking> The candidate bookings.
     */
    protected function candidates( CarbonImmutable $now ): Collection
    {
        return Booking::query()
            ->acrossAllSites()
            ->whereIn( 'status', [ BookingStatus::Requested->value, BookingStatus::Confirmed->value ] )
            ->where( 'start_time', '>', $now )
            ->where( 'start_time', '<=', $now->addHours( $this->lookaheadHours() ) )
            ->with( [ 'service', 'provider' ] )
            ->orderBy( 'start_time' )
            ->get();
    }

    /**
     * Gets how far ahead a run should look for bookings.
     *
     * Read from configuration rather than from the filtered cadence, because the
     * filter takes a booking and the lookahead has to be decided before there is
     * one. A subscriber adding a window beyond the configured maximum therefore
     * needs the configured list to cover it — which is what
     * `reminder.max_lookahead_hours` is for.
     *
     * @since 1.0.0
     *
     * @return int The lookahead, in hours.
     */
    protected function lookaheadHours(): int
    {
        /** @var array<int, mixed> $configured */
        $configured = (array) config( 'artisanpack.bookings.notifications.reminder.hours_before', [] );

        $longest = 0;

        foreach ( $configured as $hour ) {
            if ( is_numeric( $hour ) ) {
                $longest = max( $longest, (int) $hour );
            }
        }

        $floor = (int) config( 'artisanpack.bookings.notifications.reminder.max_lookahead_hours', 0 );

        return max( 1, $longest, $floor );
    }
}
