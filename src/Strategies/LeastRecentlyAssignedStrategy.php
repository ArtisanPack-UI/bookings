<?php

/**
 * Least-recently-assigned round-robin strategy.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Strategies;

use ArtisanPackUI\Bookings\Contracts\RoundRobinStrategy;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Support\Slot;

/**
 * Gives the slot to whoever has gone longest without one.
 *
 * The cursor is `service_providers.round_robin_last_assigned_at`, a timestamp
 * rather than a position, and that choice is what makes the rotation survive
 * the things that actually happen to it: a provider added halfway through the
 * week, one deactivated for a month, a candidate list that differs slot by slot
 * because everybody has their own hours. A positional cursor has to be told
 * about all of that. A "least recently assigned" reading answers it by
 * construction — whoever has waited longest is next, and who was in the running
 * last time does not come into it.
 *
 * A provider who has never been assigned anything sorts first, ahead of
 * everybody with a timestamp. Without that, somebody added today would wait
 * behind the entire history of the rota before their first booking.
 *
 * Ties break on `round_robin_weight`, descending, so a provider carrying more
 * of the load wins the coin toss. Two providers only tie when neither has ever
 * been assigned or both were assigned in the same second, which on a quiet rota
 * is most of the early bookings — the weight is what keeps that from being
 * decided by insertion order. Beyond that it falls back to the id, so the same
 * inputs always give the same answer and a lost race retries deterministically.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class LeastRecentlyAssignedStrategy implements RoundRobinStrategy
{
    /**
     * Chooses the provider for a slot.
     *
     * Sorted in PHP rather than ordered in SQL, deliberately. The candidate list
     * has already been narrowed to the providers who are genuinely free for this
     * slot — which takes availability rules, overrides, buffers, and external
     * busy blocks that no `ORDER BY` can see — and a subscriber to
     * `ap.bookings.availableProviders` may have changed it again since. Going
     * back to the database to order what is already in memory would either
     * re-fetch a set that no longer matches or quietly drop whatever the
     * subscriber added. {@see ServiceProvider::scopeLeastRecentlyAssigned()} is
     * the same rule for the cases that really are a query.
     *
     * @since 1.0.0
     *
     * @param  array<int, ServiceProvider>  $candidates  The providers free for the
     *                                                   slot. Never empty.
     * @param  Service  $service  The service being booked.
     * @param  Slot  $slot  The slot being assigned.
     *
     * @return ServiceProvider|null The provider who has waited longest, or null
     *                              when there were no candidates at all.
     */
    public function select( array $candidates, Service $service, Slot $slot ): ?ServiceProvider
    {
        if ( [] === $candidates ) {
            return null;
        }

        usort( $candidates, static function ( ServiceProvider $first, ServiceProvider $second ): int {
            $firstAssigned  = $first->round_robin_last_assigned_at;
            $secondAssigned = $second->round_robin_last_assigned_at;

            // Never assigned sorts first, and two of those are a tie rather than
            // an ordering — comparing the timestamps directly would make null
            // less than every real one on some comparisons and equal on others.
            if ( null === $firstAssigned || null === $secondAssigned ) {
                if ( null !== $firstAssigned ) {
                    return 1;
                }

                if ( null !== $secondAssigned ) {
                    return -1;
                }

                return self::breakTie( $first, $second );
            }

            return $firstAssigned->getTimestamp() <=> $secondAssigned->getTimestamp()
                ?: self::breakTie( $first, $second );
        } );

        return $candidates[0];
    }

    /**
     * Orders two providers who have waited exactly as long as each other.
     *
     * @since 1.0.0
     *
     * @param  ServiceProvider  $first  The first provider.
     * @param  ServiceProvider  $second  The second provider.
     *
     * @return int The comparison result.
     */
    protected static function breakTie( ServiceProvider $first, ServiceProvider $second ): int
    {
        return (int) $second->round_robin_weight <=> (int) $first->round_robin_weight
            ?: (int) $first->getKey() <=> (int) $second->getKey();
    }
}
