<?php

/**
 * Complete past bookings command.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Console\Commands;

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Exceptions\InvalidBookingTransitionException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

use function __;

/**
 * Closes out confirmed bookings whose end time has passed.
 *
 * Scheduled hourly. An appointment nobody touched after it happened would
 * otherwise sit as `confirmed` forever, which is wrong in the two places it is
 * read: a provider's diary shows finished work as still upcoming, and anything
 * hanging off `BookingCompleted` — a follow-up email, a review request, an
 * invoice — never fires.
 *
 * **Only confirmed bookings.** A `requested` booking is one nobody approved, and
 * marking it delivered would be the package asserting an appointment happened
 * that may never have been accepted. Those are left for staff to dispose of, as
 * a completion or a no-show, which is a judgement rather than a consequence of
 * the clock.
 *
 * The transition goes through {@see BookingService::complete()} rather than a
 * status update, which is what makes `ap.bookings.completed` fire exactly once
 * per booking and the `BookingCompleted` event go out with it. A bare update
 * would move the same rows and tell nobody.
 *
 * Running it twice is harmless: the first run leaves nothing matching the query
 * the second one makes.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class CompletePastBookingsCommand extends Command
{
    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:complete-past
        {--dry-run : Report what would be completed without changing anything.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Marks confirmed bookings whose end time has passed as completed.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @param  BookingService  $bookings  The booking service.
     *
     * @return int The command exit code.
     */
    public function handle( BookingService $bookings ): int
    {
        $now = CarbonImmutable::now();

        if ( $this->option( 'dry-run' ) ) {
            // Counted rather than walked, and deliberately without touching the
            // service: a dry run fires no hooks and dispatches no events, so an
            // operator can ask what a run would do without a subscriber emailing
            // anybody about it.
            $this->info( __(
                ':count booking(s) would be completed.',
                [ 'count' => $this->candidates( $now )->count() ],
            ) );

            return self::SUCCESS;
        }

        $completed = 0;

        foreach ( $this->candidates( $now )->lazyById() as $booking ) {
            try {
                $bookings->complete( $booking, BookingActor::System );
            } catch ( InvalidBookingTransitionException ) {
                // Somebody cancelled it between the page being read and this row
                // being reached. Their decision wins; the sweep moves on.
                continue;
            }

            $completed++;
        }

        $this->info( __( ':count booking(s) completed.', [ 'count' => $completed ] ) );

        return self::SUCCESS;
    }

    /**
     * Gets the bookings that have finished without being closed out.
     *
     * **Deliberately unscoped by site**, for the reason the reminder sweep is:
     * this runs from cron, where no site is in context, and an application whose
     * resolver answers in console too would otherwise quietly complete one
     * tenant's bookings and leave every other tenant's open forever. Nothing
     * here can reach the wrong tenant — each booking is transitioned on its own
     * terms, and the event carries the booking that moved.
     *
     * The service and provider are eager loaded, and loaded across every site
     * for the same reason the bookings are. `BookingCompleted` is picked up by
     * the webhook listener, which names both in the body it sends — and its own
     * `loadMissing()` runs through the site scope, so on an installation whose
     * resolver answers in console every tenant but the resolved one would have
     * had `service: null, provider: null` posted to their integration. Loading
     * them here means the listener finds them already in place.
     *
     * `end_time` is stored in UTC — {@see BookingService::create()} writes it
     * that way — and a bound `CarbonImmutable` is rendered by `format()` in its
     * own zone with no conversion. So the comparison has to be made in UTC
     * explicitly, exactly as {@see AvailabilityService} does it: on an
     * application whose `app.timezone` is Asia/Tokyo, an unconverted `now()`
     * binds as 21:00 against a stored 12:00 and completes appointments nine
     * hours before they happen.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable  $now  The moment to treat as now.
     *
     * @return Builder<Booking> The candidate query.
     */
    protected function candidates( CarbonImmutable $now ): Builder
    {
        return Booking::query()
            ->acrossAllSites()
            ->with( [
                'service'  => static function ( Relation $query ): void {
                    $query->acrossAllSites();
                },
                'provider' => static function ( Relation $query ): void {
                    $query->acrossAllSites();
                },
            ] )
            ->where( 'status', BookingStatus::Confirmed->value )
            ->where( 'end_time', '<=', $now->utc() );
    }
}
