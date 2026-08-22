<?php

/**
 * Provider slot lock.
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

use ArtisanPackUI\Bookings\Exceptions\SlotLockTimeoutException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Serialises everybody who wants the same provider at the same moment.
 *
 * Two customers taking the last slot is the race the whole booking domain is
 * built around, and it is not one a read followed by a write can win on its
 * own: both requests read a free slot, both write, and one provider is now in
 * two places. What this class does is make that sequence mutually exclusive for
 * one provider on one of their working days.
 *
 * **The granularity is a day, not a slot, and that is load-bearing.** Locking on
 * `(provider, start time)` — which is what plan §5.8 originally specified — is
 * only sufficient when no two bookable slots overlap, and they routinely do:
 * with the default fifteen-minute interval a sixty-minute service offers a slot
 * every quarter hour, each overlapping the last three. Two customers taking
 * 09:00 and 09:15 would hold *different* locks, neither would see the other's
 * uncommitted row, and the unique index would wave both through because it keys
 * on the start time and the two starts differ. A provider double-booked for
 * forty-five minutes, from a completely ordinary pair of requests.
 *
 * A day is the smallest bucket that cannot have that problem, because it is
 * wider than any booking it contains. The cost is that one provider's bookings
 * for one day serialise — and a provider is one person, so that queue is short
 * and the critical section is a few reads and an insert.
 *
 * The day is the provider's *local* day for the same reason availability is
 * authored in local time: a working day does not straddle its own midnight, so
 * every booking that could overlap another lands in the same bucket. An
 * overnight shift is the exception, and the overlap check the caller runs inside
 * the lock is what covers the rest.
 *
 * The lock is advisory: it guards a *decision*, not a row, and there is no row
 * to lock in any case because the booking that would take the slot does not
 * exist yet. Every engine spells that differently.
 *
 * - **Postgres** takes `pg_advisory_xact_lock`, which the server releases at
 *   commit or rollback. Nothing has to remember to let go, which matters
 *   because the thing most likely to forget is a fatal error.
 * - **MySQL** has only session-scoped `GET_LOCK`, so the release is explicit
 *   and lives in a `finally`. Held past the transaction it would leak for the
 *   life of the connection, which on a pooled worker is effectively forever.
 * - **Everything else** — sqlite in tests, chiefly — has no advisory lock at
 *   all, so the cache store's lock stands in. That is a weaker guarantee across
 *   machines and an honest one within a process, which is exactly what a test
 *   suite needs in order to exercise the same code path.
 *
 * None of this is the last line of defence. The partial unique index on
 * `bookings` is, because a lock is only as good as the process holding it and
 * some writes will arrive from code that never took one. This exists so that
 * losing the race is rare enough to be worth retrying, rather than the normal
 * way two customers find out about each other.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ProviderSlotLock
{
    /**
     * The prefix every lock name and cache lock is taken under.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected const LOCK_PREFIX = 'ap.bookings.slot.';

    /**
     * The SQLSTATE Postgres raises when a statement outwaits `lock_timeout`.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected const POSTGRES_LOCK_NOT_AVAILABLE = '55P03';

    /**
     * The longest a lock may be waited for, in seconds, whatever config says.
     *
     * A booking is written on a request path with a customer watching it, so a
     * waiter that outlives their patience has already failed — it is just doing
     * it while still holding a PHP worker.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const MAX_WAIT_SECONDS = 30;

    /**
     * How long a cache lock is held before the store reclaims it.
     *
     * Only reached when the process holding it dies without releasing, which is
     * why it is generous relative to the wait: reclaiming a lock a live worker
     * is still inside would hand the same slot to two of them.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const CACHE_LOCK_TTL_SECONDS = 60;

    /**
     * Constructs the lock.
     *
     * @since 1.0.0
     *
     * @param  ConnectionResolverInterface  $connections  The database connections.
     * @param  CacheManager  $cache  The cache manager, for the fallback lock.
     * @param  ConfigRepository  $config  The application configuration.
     */
    public function __construct(
        protected ConnectionResolverInterface $connections,
        protected CacheManager $cache,
        protected ConfigRepository $config,
    ) {
    }

    /**
     * Runs a callback holding the lock on one provider's slot, in a transaction.
     *
     * The transaction is part of the contract rather than a convenience. The
     * whole point is that reading availability and writing the booking are one
     * indivisible step, and a lock around two separate transactions would leave
     * the first one visible to everybody while the second was still deciding.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider whose day is being locked.
     * @param  CarbonInterface  $start  An instant on that day.
     * @param  string  $timezone  The provider's zone, which decides whose day it is.
     * @param  Closure  $callback  The work to do while holding the lock.
     *
     * @throws SlotLockTimeoutException When the lock could not be taken in time.
     * @throws RuntimeException When the cache store cannot issue locks.
     *
     * @return mixed Whatever the callback returned.
     */
    public function withSlotLock( int $providerId, CarbonInterface $start, string $timezone, Closure $callback ): mixed
    {
        $connection = $this->connections->connection();
        $driver     = $connection->getDriverName();

        if ( 'pgsql' === $driver ) {
            return $this->withPostgresLock( $connection, $providerId, $start, $timezone, $callback );
        }

        if ( in_array( $driver, [ 'mysql', 'mariadb' ], true ) ) {
            return $this->withMysqlLock( $connection, $providerId, $start, $timezone, $callback );
        }

        return $this->withCacheLock( $connection, $providerId, $start, $timezone, $callback );
    }

    /**
     * Gets the name a slot's lock is taken under.
     *
     * Hashed rather than composed from the two values, for one reason that
     * matters and one that only looks cosmetic. MySQL caps a lock name at 64
     * characters and silently misbehaves past it, and a fixed-width name cannot
     * drift over that however long an id gets. The hash is truncated to 128
     * bits, which is far more than a collision needs — and a collision here
     * costs one unnecessary wait, not a wrong answer.
     *
     * The instant is resolved to the provider's local date first. Two callers
     * holding the same moment in different zones are contending for the same
     * day, and any two bookings that could overlap fall on the same local date —
     * which is the property the whole guard rests on.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider whose day is being locked.
     * @param  CarbonInterface  $start  An instant on that day.
     * @param  string  $timezone  The provider's zone.
     *
     * @return string The lock name.
     */
    public function lockName( int $providerId, CarbonInterface $start, string $timezone ): string
    {
        return self::LOCK_PREFIX . substr( $this->digest( $providerId, $start, $timezone ), 0, 32 );
    }

    /**
     * Gets the integer key Postgres identifies a slot's lock by.
     *
     * Postgres names advisory locks with a bigint rather than a string, so the
     * same digest is folded down to one. Fifteen hex digits is 60 bits, which
     * stays clear of the sign bit on every platform without any arithmetic to
     * get wrong.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider whose day is being locked.
     * @param  CarbonInterface  $start  An instant on that day.
     * @param  string  $timezone  The provider's zone.
     *
     * @return int The advisory lock key.
     */
    public function lockKey( int $providerId, CarbonInterface $start, string $timezone ): int
    {
        return (int) hexdec( substr( $this->digest( $providerId, $start, $timezone ), 0, 15 ) );
    }

    /**
     * Runs the callback under a Postgres transaction-scoped advisory lock.
     *
     * `lock_timeout` is set `LOCAL`, so it reverts at the end of the transaction
     * and does not leak onto the next thing this connection does. Without it the
     * wait is unbounded, and an advisory lock a crashed worker never released
     * would stall every subsequent booking for that slot until somebody noticed.
     * Exceeding it raises SQLSTATE 55P03, which is translated here so that every
     * engine reports a timeout the same way.
     *
     * A caller who has already opened a transaction of their own gets a
     * savepoint instead, and the lock is then held until *their* transaction
     * ends rather than until this one does. That is the safe direction to be
     * wrong in — it locks for longer, never for less — but it is worth knowing
     * before wrapping a batch of bookings in one transaction.
     *
     * @since 1.0.0
     *
     * @param  ConnectionInterface  $connection  The database connection.
     * @param  int  $providerId  The provider whose day is being locked.
     * @param  CarbonInterface  $start  An instant on that day.
     * @param  string  $timezone  The provider's zone.
     * @param  Closure  $callback  The work to do while holding the lock.
     *
     * @throws SlotLockTimeoutException When the lock could not be taken in time.
     *
     * @return mixed Whatever the callback returned.
     */
    protected function withPostgresLock(
        ConnectionInterface $connection,
        int $providerId,
        CarbonInterface $start,
        string $timezone,
        Closure $callback,
    ): mixed {
        $key     = $this->lockKey( $providerId, $start, $timezone );
        $seconds = $this->waitSeconds();

        try {
            return $connection->transaction( function () use ( $connection, $key, $seconds, $callback ): mixed {
                $connection->statement( sprintf( 'set local lock_timeout = %d', $seconds * 1000 ) );
                $connection->statement( 'select pg_advisory_xact_lock(?)', [ $key ] );

                return $callback();
            } );
        } catch ( QueryException $failed ) {
            if ( self::POSTGRES_LOCK_NOT_AVAILABLE === (string) $failed->getCode() ) {
                throw SlotLockTimeoutException::for( $providerId, $start, $seconds );
            }

            throw $failed;
        }
    }

    /**
     * Runs the callback under a MySQL named lock.
     *
     * The lock is taken before the transaction opens and released after it
     * closes, in that order and not the other way round. `GET_LOCK` is not
     * transactional — a rollback does not undo it — so releasing inside the
     * transaction would hand the slot to the next waiter while this one's
     * booking was still uncommitted, which is the exact window the lock exists
     * to close.
     *
     * @since 1.0.0
     *
     * @param  ConnectionInterface  $connection  The database connection.
     * @param  int  $providerId  The provider whose day is being locked.
     * @param  CarbonInterface  $start  An instant on that day.
     * @param  string  $timezone  The provider's zone.
     * @param  Closure  $callback  The work to do while holding the lock.
     *
     * @throws SlotLockTimeoutException When the lock could not be taken in time.
     *
     * @return mixed Whatever the callback returned.
     */
    protected function withMysqlLock(
        ConnectionInterface $connection,
        int $providerId,
        CarbonInterface $start,
        string $timezone,
        Closure $callback,
    ): mixed {
        $name    = $this->lockName( $providerId, $start, $timezone );
        $seconds = $this->waitSeconds();

        // Cast in SQL rather than in PHP. GET_LOCK reports failure as 0 and a
        // server error as NULL, and both are falsy in PHP for different reasons
        // — casting keeps the distinction from mattering at the call site.
        $acquired = $connection->selectOne( 'select get_lock(?, ?) as acquired', [ $name, $seconds ] );

        if ( 1 !== (int) ( $acquired->acquired ?? 0 ) ) {
            throw SlotLockTimeoutException::for( $providerId, $start, $seconds );
        }

        try {
            return $connection->transaction( $callback );
        } finally {
            $connection->selectOne( 'select release_lock(?) as released', [ $name ] );
        }
    }

    /**
     * Runs the callback under a cache lock, for engines with no advisory lock.
     *
     * sqlite is the case this exists for, and the point is coverage rather than
     * production safety: the booking path has to take *a* lock on every engine
     * so that the in-lock code is the code the test suite runs. An application
     * deployed on sqlite gets a guarantee no wider than one process, which is
     * about as far as sqlite goes anyway.
     *
     * @since 1.0.0
     *
     * @param  ConnectionInterface  $connection  The database connection.
     * @param  int  $providerId  The provider whose day is being locked.
     * @param  CarbonInterface  $start  An instant on that day.
     * @param  string  $timezone  The provider's zone.
     * @param  Closure  $callback  The work to do while holding the lock.
     *
     * @throws SlotLockTimeoutException When the lock could not be taken in time.
     * @throws RuntimeException When the configured cache store cannot issue locks.
     *
     * @return mixed Whatever the callback returned.
     */
    protected function withCacheLock(
        ConnectionInterface $connection,
        int $providerId,
        CarbonInterface $start,
        string $timezone,
        Closure $callback,
    ): mixed {
        $store = $this->cache->store( $this->config->get( 'artisanpack.bookings.lock.store' ) );

        if ( ! $store->getStore() instanceof LockProvider ) {
            throw new RuntimeException( sprintf(
                'The cache store backing artisanpack.bookings.lock.store cannot issue locks, so bookings on the "%s" driver cannot be serialised. Configure a store that can.',
                $connection->getDriverName(),
            ) );
        }

        $seconds = $this->waitSeconds();
        $lock    = $store->getStore()->lock( $this->lockName( $providerId, $start, $timezone ), self::CACHE_LOCK_TTL_SECONDS );

        try {
            return $lock->block( $seconds, static fn (): mixed => $connection->transaction( $callback ) );
        } catch ( LockTimeoutException ) {
            throw SlotLockTimeoutException::for( $providerId, $start, $seconds );
        }
    }

    /**
     * Gets how long a lock may be waited for, in seconds.
     *
     * @since 1.0.0
     *
     * @return int The bounded wait.
     */
    protected function waitSeconds(): int
    {
        $configured = (int) $this->config->get( 'artisanpack.bookings.lock.wait_seconds', 5 );

        return max( 1, min( $configured, self::MAX_WAIT_SECONDS ) );
    }

    /**
     * Gets the digest both lock identifiers are cut from.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider whose day is being locked.
     * @param  CarbonInterface  $start  An instant on that day.
     * @param  string  $timezone  The provider's zone.
     *
     * @return string The hex digest.
     */
    protected function digest( int $providerId, CarbonInterface $start, string $timezone ): string
    {
        return hash(
            'sha256',
            $providerId . '@' . CarbonImmutable::instance( $start )->setTimezone( $timezone )->toDateString(),
        );
    }
}
