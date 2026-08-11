<?php

/**
 * iCal feed token command.
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

use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\IcalTokenService;
use Illuminate\Console\Command;

use function __;
use function is_numeric;
use function is_string;

/**
 * Issues, rotates, and withdraws a provider's calendar feed token.
 *
 * Only the hash of a feed token is stored, so there is no screen anywhere that
 * can show a provider their subscription URL a second time. This command is the
 * moment the URL exists in readable form — it is printed once, here, and the
 * operator's job is to get it to the provider.
 *
 * Running it against a provider who already has a feed **rotates** the token.
 * Every calendar client already subscribed to the old URL stops updating at
 * that moment, and does so quietly: a subscribed feed that starts 404ing does
 * not announce itself, it just stops changing. That is the cost of not keeping a
 * recoverable copy, and it is why the command asks before it does it.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class IcalTokenCommand extends Command
{
    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:ical-token
        {provider : The provider\'s id or slug.}
        {--revoke : Withdraw the feed instead of issuing a token.}
        {--force : Rotate or revoke without asking for confirmation.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Issues, rotates, or revokes a provider\'s iCal feed token and prints the subscription URL.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @param  IcalTokenService  $tokens  The feed token service.
     *
     * @return int The command exit code.
     */
    public function handle( IcalTokenService $tokens ): int
    {
        $provider = $this->resolveProvider();

        if ( ! $provider instanceof ServiceProvider ) {
            $this->error( __( 'No provider was found for that id or slug.' ) );

            return self::FAILURE;
        }

        if ( $this->option( 'revoke' ) ) {
            return $this->revoke( $tokens, $provider );
        }

        return $this->issue( $tokens, $provider );
    }

    /**
     * Issues or rotates the provider's token.
     *
     * @since 1.0.0
     *
     * @param  IcalTokenService  $tokens  The feed token service.
     * @param  ServiceProvider  $provider  The provider to issue for.
     *
     * @return int The command exit code.
     */
    protected function issue( IcalTokenService $tokens, ServiceProvider $provider ): int
    {
        if ( $tokens->hasFeed( $provider ) ) {
            $this->warn( __(
                ':name already has a feed. Issuing a new token replaces it, and every calendar already subscribed to the old URL will stop updating without saying so.',
                [ 'name' => $provider->name ],
            ) );

            if ( ! $this->option( 'force' ) && ! $this->confirm( __( 'Replace the existing token?' ), false ) ) {
                $this->info( __( 'Nothing was changed.' ) );

                return self::SUCCESS;
            }
        }

        $url = $tokens->feedUrl( $tokens->issueFor( $provider ) );

        $this->info( __( 'Subscription URL for :name:', [ 'name' => $provider->name ] ) );
        $this->line( $url );

        // Said plainly because it is the one thing an operator will assume is
        // untrue: every other credential in a system like this can be looked up
        // again somewhere.
        $this->warn( __( 'This URL is shown once and cannot be recovered. Send it to the provider now.' ) );

        return self::SUCCESS;
    }

    /**
     * Withdraws the provider's feed.
     *
     * @since 1.0.0
     *
     * @param  IcalTokenService  $tokens  The feed token service.
     * @param  ServiceProvider  $provider  The provider to revoke.
     *
     * @return int The command exit code.
     */
    protected function revoke( IcalTokenService $tokens, ServiceProvider $provider ): int
    {
        if ( ! $tokens->hasFeed( $provider ) ) {
            $this->info( __( ':name has no feed to revoke.', [ 'name' => $provider->name ] ) );

            return self::SUCCESS;
        }

        if ( ! $this->option( 'force' ) && ! $this->confirm(
            __( 'Revoke :name\'s feed? Every calendar subscribed to it stops updating.', [ 'name' => $provider->name ] ),
            false,
        ) ) {
            $this->info( __( 'Nothing was changed.' ) );

            return self::SUCCESS;
        }

        $tokens->revokeFor( $provider );

        $this->info( __( 'The feed for :name has been revoked.', [ 'name' => $provider->name ] ) );

        return self::SUCCESS;
    }

    /**
     * Gets the provider named on the command line.
     *
     * A numeric argument is read as an id and anything else as a slug, so an
     * operator can use whichever they have in front of them. Inactive providers
     * resolve here — the feed will 404 for them, but revoking the token of
     * somebody who has just been deactivated is exactly the thing an operator
     * needs to be able to do.
     *
     * @since 1.0.0
     *
     * @return ServiceProvider|null The provider, or null when none matches.
     */
    protected function resolveProvider(): ?ServiceProvider
    {
        $argument = $this->argument( 'provider' );

        if ( ! is_string( $argument ) || '' === $argument ) {
            return null;
        }

        $query = ServiceProvider::query();

        return is_numeric( $argument )
            ? $query->find( (int) $argument )
            : $query->where( 'slug', $argument )->first();
    }
}
