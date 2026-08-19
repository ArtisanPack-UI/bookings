<?php

/**
 * Erase bookings command.
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

use ArtisanPackUI\Bookings\Models\Booking;
use Illuminate\Console\Command;

/**
 * Hard-scrubs the personal data on a booking, or on every booking for an email.
 *
 * This is the erasure half of the two obligations a booking's data carries — the
 * right-to-erasure request, as against the retention window {@see
 * PruneBookingsCommand} serves. It overwrites the personal columns in place and
 * marks the row erased rather than deleting it, so aggregate reporting — how many
 * bookings a service took, when — keeps working on a row that no longer names
 * anyone. The redaction and everything it cascades to live in {@see
 * Booking::erasePersonalData()}; this command only decides which rows to run it
 * against.
 *
 * The selection reaches soft-deleted rows on purpose. A booking pruned for
 * retention still holds intact personal data, and an erasure request has to be
 * able to reach it — a request the package could not honour because the row had
 * already dropped out of the default scope is the request most in need of
 * honouring.
 *
 * It is never scheduled. Erasure answers a request a person made, so it is
 * something an operator runs against a named booking or address, in front of the
 * output, and never something a clock decides to do.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class EraseBookingsCommand extends Command
{
    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:erase
        {--booking= : The reference number of a single booking to erase.}
        {--email= : Erase every booking made with this email address.}
        {--dry-run : Report what would be erased without changing anything.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Hard-scrubs the personal data on a booking, or on every booking for an email address.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @return int The command exit code.
     */
    public function handle(): int
    {
        $bookingNumber = $this->normalizedOption( 'booking' );
        $email         = $this->normalizedOption( 'email' );

        // Exactly one selector: erasing "everything" or "nothing" are both far
        // likelier a mistake than an intent, so neither is offered here.
        if ( ( null === $bookingNumber ) === ( null === $email ) ) {
            $this->error( __( 'Pass exactly one of --booking or --email.' ) );

            return self::FAILURE;
        }

        if ( null !== $bookingNumber ) {
            return $this->eraseByNumber( $bookingNumber );
        }

        return $this->eraseByEmail( $email );
    }

    /**
     * Erases the single booking a reference number identifies.
     *
     * A missing reference is treated as a failure rather than a quiet no-op: an
     * erasure asked for a booking that cannot be found is likelier a mistyped
     * reference than a request already satisfied, and reporting success would
     * tell an operator the person's data was scrubbed when it was not.
     *
     * @since 1.0.0
     *
     * @param  string  $number  The booking reference to erase.
     *
     * @return int The command exit code.
     */
    protected function eraseByNumber( string $number ): int
    {
        $booking = Booking::withTrashed()->where( 'booking_number', $number )->first();

        if ( ! $booking instanceof Booking ) {
            $this->error( __( 'No booking found with reference :number.', [ 'number' => $number ] ) );

            return self::FAILURE;
        }

        if ( $booking->isPiiErased() ) {
            $this->info( __( 'Booking :number was already erased; nothing to do.', [ 'number' => $number ] ) );

            return self::SUCCESS;
        }

        if ( $this->option( 'dry-run' ) ) {
            $this->info( __( ':count booking(s) would be erased.', [ 'count' => 1 ] ) );

            return self::SUCCESS;
        }

        $booking->erasePersonalData();

        $this->info( __( ':count booking(s) erased.', [ 'count' => 1 ] ) );

        return self::SUCCESS;
    }

    /**
     * Erases every booking made with an email address.
     *
     * Zero matches is reported as success, not failure: an address with no
     * intact booking data is a request already satisfied, which is exactly what
     * a re-run of an erasure that already happened looks like.
     *
     * @since 1.0.0
     *
     * @param  string  $email  The customer email to erase every booking for.
     *
     * @return int The command exit code.
     */
    protected function eraseByEmail( string $email ): int
    {
        $bookings = Booking::withTrashed()
            ->where( 'customer_email', $email )
            ->notPiiErased()
            ->get();

        if ( $bookings->isEmpty() ) {
            $this->info( __( 'No bookings with intact personal data were found for :email.', [ 'email' => $email ] ) );

            return self::SUCCESS;
        }

        if ( $this->option( 'dry-run' ) ) {
            $this->info( __( ':count booking(s) would be erased.', [ 'count' => $bookings->count() ] ) );

            return self::SUCCESS;
        }

        $bookings->each( function ( Booking $booking ): void {
            $booking->erasePersonalData();
        } );

        $this->info( __( ':count booking(s) erased.', [ 'count' => $bookings->count() ] ) );

        return self::SUCCESS;
    }

    /**
     * Reads a string option, treating an empty value as absent.
     *
     * `--email=` with nothing after it arrives as an empty string rather than
     * null, and an empty selector is the "erase everything" the exactly-one
     * guard exists to refuse — so it is folded back to null here to be caught
     * there rather than run against every booking whose email is blank.
     *
     * @since 1.0.0
     *
     * @param  string  $name  The option name.
     *
     * @return string|null The trimmed value, or null when absent or empty.
     */
    protected function normalizedOption( string $name ): ?string
    {
        $value = $this->option( $name );

        if ( ! is_string( $value ) ) {
            return null;
        }

        $value = trim( $value );

        return '' === $value ? null : $value;
    }
}
