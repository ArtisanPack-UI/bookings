<?php

/**
 * Intake schema version factory.
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

use ArtisanPackUI\Bookings\Models\IntakeSchemaVersion;
use ArtisanPackUI\Bookings\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds recorded versions of a service's intake form.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @extends Factory<IntakeSchemaVersion>
 */
class IntakeSchemaVersionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<IntakeSchemaVersion>
     */
    protected $model = IntakeSchemaVersion::class;

    /**
     * Defines the model's default state.
     *
     * The version is always 1. `(service_id, version)` is unique, so a factory
     * that guessed at a number would produce a collision that looked like a bug
     * in whatever was being tested — {@see self::version()} and
     * {@see self::history()} are how a service gets more than one.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'version'    => 1,
            'schema'     => [
                'fields' => [
                    [ 'name' => 'goal', 'type' => 'textarea', 'label' => 'What would you like to cover?' ],
                ],
            ],
        ];
    }

    /**
     * Records the schema under a given version number.
     *
     * @since 1.0.0
     *
     * @param  int  $version  The version to record.
     *
     * @return static The configured factory.
     */
    public function version( int $version ): static
    {
        return $this->state( fn (): array => [ 'version' => $version ] );
    }

    /**
     * Numbers a run of versions consecutively from one.
     *
     * @since 1.0.0
     *
     * @param  int  $count  How many versions the run holds.
     *
     * @return static The configured factory.
     */
    public function history( int $count = 3 ): static
    {
        return $this->count( $count )->sequence(
            ...array_map(
                static fn ( int $version ): array => [ 'version' => $version ],
                range( 1, $count ),
            ),
        );
    }
}
