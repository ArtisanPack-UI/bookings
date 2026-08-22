<?php

/**
 * SMS driver fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Bookings\Contracts\SmsDriver;
use RuntimeException;

/**
 * A gateway that remembers what it was asked to send instead of sending it.
 *
 * Stands in for the Twilio and Vonage drivers that land in v1.1, and for an
 * application's own — which is the shape the contract exists to allow.
 *
 * @since 1.0.0
 */
class RecordingSmsDriver implements SmsDriver
{
    /**
     * What the driver has been asked to send.
     *
     * @since 1.0.0
     *
     * @var array<int, array{phone: string, message: string}>
     */
    public array $sent = [];

    /**
     * Constructs the driver.
     *
     * @since 1.0.0
     *
     * @param  bool  $fails  Whether sending should throw.
     */
    public function __construct( protected bool $fails = false )
    {
    }

    /**
     * Records the message that would have been sent.
     *
     * @since 1.0.0
     *
     * @param  string  $phone  The destination number.
     * @param  string  $message  The message body.
     *
     * @throws RuntimeException When the driver is configured to fail.
     *
     * @return void
     */
    public function send( string $phone, string $message ): void
    {
        if ( $this->fails ) {
            throw new RuntimeException( 'The recording SMS driver was told to fail.' );
        }

        $this->sent[] = [
            'phone'   => $phone,
            'message' => $message,
        ];
    }
}
