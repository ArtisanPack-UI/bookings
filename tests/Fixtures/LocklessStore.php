<?php

/**
 * Lock-incapable cache store fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

use Illuminate\Contracts\Cache\Store;

/**
 * A cache store that can hold values but cannot issue locks.
 *
 * Every store Laravel ships implements `LockProvider`, so the case where one
 * does not can only come from an application's own driver — and it is the case
 * that matters most, because on an engine with no advisory lock the cache lock
 * is the only thing serialising two customers after the same slot. This exists
 * so the refusal is proven rather than assumed.
 *
 * @since 1.0.0
 */
class LocklessStore implements Store
{
    /**
     * The values held.
     *
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected array $values = [];

    /**
     * Retrieves an item from the cache by key.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The key to read.
     *
     * @return mixed The value, or null.
     */
    public function get( $key ): mixed
    {
        return $this->values[ $key ] ?? null;
    }

    /**
     * Retrieves multiple items from the cache by key.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $keys  The keys to read.
     *
     * @return array<string, mixed> The values, keyed by key.
     */
    public function many( array $keys ): array
    {
        $values = [];

        foreach ( $keys as $key ) {
            $values[ $key ] = $this->get( $key );
        }

        return $values;
    }

    /**
     * Stores an item in the cache for a given number of seconds.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The key to write.
     * @param  mixed  $value  The value to write.
     * @param  int  $seconds  How long to keep it. Ignored.
     *
     * @return bool Always true.
     */
    public function put( $key, $value, $seconds ): bool
    {
        $this->values[ $key ] = $value;

        return true;
    }

    /**
     * Stores multiple items in the cache for a given number of seconds.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $values  The values to write.
     * @param  int  $seconds  How long to keep them. Ignored.
     *
     * @return bool Always true.
     */
    public function putMany( array $values, $seconds ): bool
    {
        foreach ( $values as $key => $value ) {
            $this->put( $key, $value, $seconds );
        }

        return true;
    }

    /**
     * Increments the value of an item in the cache.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The key to increment.
     * @param  int  $value  The amount to add.
     *
     * @return int The new value.
     */
    public function increment( $key, $value = 1 ): int
    {
        $this->values[ $key ] = (int) ( $this->values[ $key ] ?? 0 ) + $value;

        return $this->values[ $key ];
    }

    /**
     * Decrements the value of an item in the cache.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The key to decrement.
     * @param  int  $value  The amount to subtract.
     *
     * @return int The new value.
     */
    public function decrement( $key, $value = 1 ): int
    {
        return $this->increment( $key, -$value );
    }

    /**
     * Stores an item in the cache indefinitely.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The key to write.
     * @param  mixed  $value  The value to write.
     *
     * @return bool Always true.
     */
    public function forever( $key, $value ): bool
    {
        return $this->put( $key, $value, 0 );
    }

    /**
     * Removes an item from the cache.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The key to forget.
     *
     * @return bool Always true.
     */
    public function forget( $key ): bool
    {
        unset( $this->values[ $key ] );

        return true;
    }

    /**
     * Removes all items from the cache.
     *
     * @since 1.0.0
     *
     * @return bool Always true.
     */
    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    /**
     * Gets the cache key prefix.
     *
     * @since 1.0.0
     *
     * @return string The empty prefix.
     */
    public function getPrefix(): string
    {
        return '';
    }
}
