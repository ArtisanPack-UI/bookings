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
use function ctype_digit;
use function is_string;

/**
 * Issues, rotates, and withdraws a provider's calendar feed token.
 *
 * Only the hash of a feed token is stored, so there is no screen anywhere that
 * can show a provider their subscription URL a second time. This command is the
 * moment the URL exists in readable form — it is printed once, here, and the
 * operator's job is to get it to the provider.
 *
 * The URL it prints is not single-use. It can be pasted into as many calendar
 * clients as the provider owns; what cannot be done is look it up again later.
 *
 * Running the command against a provider who already has a feed **rotates** the
 * token, which is for a URL that has been lost or exposed rather than for adding
 * a device. Every calendar client already subscribed to the old URL stops
 * updating at that moment, and does so quietly: a subscribed feed that starts
 * 404ing does not announce itself, it just stops changing. That is why the
 * command asks before it does it.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class IcalTokenCommand extends Command
{
    /**
     * How many times a contested rotation is re-asked before giving up.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const MAX_ATTEMPTS = 3;

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

        // The feed resolves through an `active()` query, so a token issued to a
        // deactivated provider addresses a URL that 404s. Printing one would be
        // the command handing over a subscription that has never worked and
        // saying nothing about it.
        if ( ! $provider->is_active ) {
            $this->error( __(
                ':name is not active, so their feed would 404. Reactivate them first.',
                [ 'name' => $provider->name ],
            ) );

            return self::FAILURE;
        }

        return $this->issue( $tokens, $provider );
    }

    /**
     * Issues or rotates the provider's token.
     *
     * The write is conditional on the hash that was inspected before the prompt.
     * A confirmation is a human deciding, which takes as long as it takes, and
     * anything that issues a token in that window — a second operator at another
     * terminal, an admin screen, a job — would otherwise have its token
     * overwritten by this command without either operator finding out. Whoever
     * loses is told, re-reads, and is asked again about what they can now see.
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
        // Bounded rather than a `while ( true )`: each pass needs somebody to
        // answer a prompt, so a loop that could not give up would be a command
        // that hangs on a provider something else is rotating in a tight loop.
        for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
            $expected = $provider->ical_token_hash;

            if ( null !== $expected ) {
                $this->warn( __(
                    ':name already has a feed. Issuing a new token replaces it, and every calendar already subscribed to the old URL will stop updating without saying so.',
                    [ 'name' => $provider->name ],
                ) );

                if ( ! $this->option( 'force' ) && ! $this->confirm( __( 'Replace the existing token?' ), false ) ) {
                    $this->info( __( 'Nothing was changed.' ) );

                    return self::SUCCESS;
                }
            }

            $token = $tokens->issueIfUnchanged( $provider, $expected );

            if ( null !== $token ) {
                return $this->report( $tokens, $provider, $token );
            }

            $this->warn( __( ':name\'s feed token changed while that was being answered.', [ 'name' => $provider->name ] ) );

            $provider = $provider->fresh() ?? $provider;
        }

        $this->error( __( 'Something else keeps rotating that feed token. Nothing was changed.' ) );

        return self::FAILURE;
    }

    /**
     * Prints the subscription URL, which exists nowhere else.
     *
     * @since 1.0.0
     *
     * @param  IcalTokenService  $tokens  The feed token service.
     * @param  ServiceProvider  $provider  The provider the token is for.
     * @param  string  $token  The new plain feed token.
     *
     * @return int The command exit code.
     */
    protected function report( IcalTokenService $tokens, ServiceProvider $provider, string $token ): int
    {
        $this->info( __( 'Subscription URL for :name:', [ 'name' => $provider->name ] ) );
        $this->line( $tokens->feedUrl( $token ) );

        // Said plainly because it is the one thing an operator will assume is
        // untrue: every other credential in a system like this can be looked up
        // again somewhere. The URL itself keeps working for as long as nobody
        // rotates it — it can be pasted into as many calendar clients as the
        // provider has — but this is the last time anybody can read it off a
        // screen, so it has to be kept rather than looked up later.
        $this->warn( __( 'This URL is shown once and cannot be recovered. Send it to the provider now, and keep it if you may need it again.' ) );

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
     * An operator uses whichever identifier they have in front of them, so both
     * are accepted — and the slug is tried first, which is what keeps the two
     * from colliding. A provider whose slug is `42` is found by typing `42`;
     * only when nothing answers to the argument as a slug is it read as an id.
     *
     * The id branch is `ctype_digit()` rather than `is_numeric()`, which accepts
     * far more than an id can be. `is_numeric( '1e3' )` is true and
     * `(int) '1e3'` is 1000 — so a slug in scientific notation, or one with
     * leading whitespace, would have quietly resolved to a completely unrelated
     * provider and rotated *their* feed token. The two operations this command
     * performs are not ones to perform on the wrong provider.
     *
     * Inactive providers resolve here. The feed 404s for them and
     * {@see self::handle()} refuses to issue them a token, but revoking the feed
     * of somebody who has just been deactivated is exactly the thing an operator
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

        $provider = ServiceProvider::query()->where( 'slug', $argument )->first();

        if ( $provider instanceof ServiceProvider ) {
            return $provider;
        }

        return ctype_digit( $argument ) ? ServiceProvider::query()->find( (int) $argument ) : null;
    }
}
