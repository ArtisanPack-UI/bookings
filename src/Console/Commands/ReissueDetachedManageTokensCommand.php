<?php

/**
 * Reissue detached manage tokens command.
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

use ArtisanPackUI\Bookings\Services\ManageTokenService;
use Illuminate\Console\Command;

/**
 * Rotates every manage token in the system.
 *
 * A manage token is detached from any user account — it is the credential a
 * customer with no login uses to cancel or reschedule, and it lives in a link
 * that has been sent by email. That makes it something that can leak in ways a
 * password cannot: a forwarded confirmation, a mail archive, a referrer header
 * from a badly built third-party page. This command is what an operator reaches
 * for when one has, and it is deliberately blunt — every token in every site is
 * replaced, so every link the package has ever sent stops working.
 *
 * That bluntness is the cost of the guarantee, and it is not free: a customer
 * whose link no longer works has no way to manage their booking until somebody
 * sends them a new one. The `ap.bookings.manageTokenReissued` action fires per
 * booking with the new plain token — the only moment it can be read — so an
 * application that has mail wired up can re-send as the rotation runs.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ReissueDetachedManageTokensCommand extends Command
{
    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:reissue-detached-manage-tokens
        {--chunk= : How many bookings to rotate per query.}
        {--force : Rotate without asking for confirmation.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Rotates every booking manage token, invalidating all outstanding manage links.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @param  ManageTokenService  $tokens  The manage token service.
     *
     * @return int The command exit code.
     */
    public function handle( ManageTokenService $tokens ): int
    {
        $this->warn(
            __( 'This replaces the manage token on every booking. Every manage link already sent to a customer will stop working.' ),
        );

        if ( ! $this->option( 'force' ) && ! $this->confirm( __( 'Rotate every manage token?' ), false ) ) {
            $this->info( __( 'Nothing was rotated.' ) );

            return self::SUCCESS;
        }

        $rotated = $tokens->reissueAll( $this->chunkSize() );

        $this->info( __( ':count manage token(s) rotated.', [ 'count' => $rotated ] ) );

        return self::SUCCESS;
    }

    /**
     * Reads the chunk size off the command line.
     *
     * A non-numeric or non-positive `--chunk` falls back to the service default
     * rather than failing: this command is run in a hurry, by somebody dealing
     * with a leak, and a typo in a tuning knob is no reason to refuse to rotate.
     *
     * @since 1.0.0
     *
     * @return int|null The requested chunk size, or null to use the default.
     */
    protected function chunkSize(): ?int
    {
        $chunk = $this->option( 'chunk' );

        if ( ! is_numeric( $chunk ) ) {
            return null;
        }

        return max( 1, (int) $chunk );
    }
}
