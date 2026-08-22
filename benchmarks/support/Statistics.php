<?php

/**
 * Benchmark statistics helper.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Benchmarks;

use InvalidArgumentException;

/**
 * Turns a list of timings into the summary a benchmark reports.
 *
 * Percentiles are taken by the nearest-rank method on the sorted samples: the
 * p95 is the smallest sample at least 95% of the others fall at or below, which
 * is the reading a latency target like "p95 < 200ms" is written against. Nothing
 * here interpolates between samples — a run reports a value it actually observed.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
final class Statistics
{
    /**
     * Summarises a set of samples.
     *
     * @since 1.0.0
     *
     * @param  array<int, float>  $samples  The observed values, in any order.
     *
     * @throws InvalidArgumentException When there are no samples to summarise.
     *
     * @return array{count: int, min: float, max: float, mean: float, p50: float, p90: float, p95: float, p99: float}
     *                                                                                                                The summary, in the same unit the samples were given in.
     */
    public static function summarize( array $samples ): array
    {
        if ( [] === $samples ) {
            throw new InvalidArgumentException( 'Cannot summarise an empty set of samples.' );
        }

        $sorted = array_values( $samples );
        sort( $sorted );

        $count = count( $sorted );

        return [
            'count' => $count,
            'min'   => $sorted[0],
            'max'   => $sorted[ $count - 1 ],
            'mean'  => array_sum( $sorted ) / $count,
            'p50'   => self::percentile( $sorted, 50 ),
            'p90'   => self::percentile( $sorted, 90 ),
            'p95'   => self::percentile( $sorted, 95 ),
            'p99'   => self::percentile( $sorted, 99 ),
        ];
    }

    /**
     * Gets a percentile of an already-sorted, non-empty set of samples.
     *
     * Nearest-rank: the rank is `ceil( p/100 * n )`, clamped into the array so
     * the 100th percentile lands on the last sample rather than one past it.
     *
     * @since 1.0.0
     *
     * @param  array<int, float>  $sorted  The samples, ascending.
     * @param  float  $percentile  The percentile to read, 0–100.
     *
     * @return float The sample at that rank.
     */
    public static function percentile( array $sorted, float $percentile ): float
    {
        $count = count( $sorted );
        $rank  = (int) ceil( ( $percentile / 100 ) * $count );
        $index = max( 1, min( $count, $rank ) ) - 1;

        return $sorted[ $index ];
    }
}
