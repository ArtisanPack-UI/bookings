<?php

/**
 * Admin-audience email recipients.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Notifications;

use ArtisanPackUI\Bookings\Notifications\Channels\Concerns\FitsRecipientColumn;
use ArtisanPackUI\Bookings\Providers\BookingsServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Resolves the staff who receive an admin-audience email copy of a booking notice.
 *
 * The same people the `database` channel notifies, resolved the way *that
 * installation's* database channel resolves them — which of the two answers is
 * not this class's guess to make. {@see BookingsServiceProvider::usesCmsNotifications()}
 * is the one place the package decides whether staff notices go through
 * cms-framework's centre or Laravel's own table, honouring the `driver` config
 * as well as which package is installed, and this reads that same answer: by the
 * cms-framework role when the CMS centre answers, by the `notifiable` model and
 * its `ids` when Laravel's channel does. Deciding it here by inspecting the user
 * model instead would drift from the bound channel — a standalone app whose user
 * model happened to carry a `roles` relationship would be emailed by role while
 * its database notice went to the id list, two different audiences.
 *
 * Where the two database channels hand the whole audience to cms-framework or to
 * Laravel's notification system to fan out, the admin email path routes mail to
 * each address itself — so this returns the recipient *models*, from which
 * Laravel reads each address, rather than delivering anything. It records who was
 * notified the way the database channels do: an internal reference to the role or
 * the notifiable keys, never a staff address, so the erasure sweep that redacts
 * `recipient` for a booking leaves the record of who was told intact.
 *
 * One thing it does *not* mirror: cms-framework's per-user notification-centre
 * preferences. Those govern whether a staff member sees the notice in the CMS
 * centre, a channel the admin email is not — it is a direct send, like the
 * provider email, to whichever staff the role or id list names. An installation
 * that does not want the email sent for a given booking suppresses the whole
 * copy through `ap.bookings.notification.sending`, the same hook the customer
 * and provider sends pass through.
 *
 * The resolution runs once and is remembered, so {@see self::logReference()} can
 * name exactly the audience {@see self::recipients()} returned without a second
 * query disagreeing with the first.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.1.0
 */
class AdminAudienceRecipients
{
    use FitsRecipientColumn;

    /**
     * The resolved recipients, once they have been read.
     *
     * @since 1.1.0
     *
     * @var Collection<int, Model>|null
     */
    protected ?Collection $resolved = null;

    /**
     * Whether the resolved recipients came from the role rather than the id list.
     *
     * Set by {@see self::recipients()} when it resolves, and read by
     * {@see self::logReference()} to decide which reference to record.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    protected bool $byRole = false;

    /**
     * Gets the staff to email, resolving them once and remembering the answer.
     *
     * @since 1.1.0
     *
     * @return Collection<int, Model> The recipients, empty when none are configured.
     */
    public function recipients(): Collection
    {
        if ( null !== $this->resolved ) {
            return $this->resolved;
        }

        if ( $this->resolvesByRole() ) {
            $this->byRole = true;

            /** @var class-string<Model> $userModel */
            $userModel = $this->roleUserModel();
            $role      = (string) $this->role();

            return $this->resolved = $userModel::query()
                ->whereHas( 'roles', static function ( $query ) use ( $role ): void {
                    $query->where( 'name', $role );
                } )
                ->get();
        }

        $class = $this->notifiableClass();
        $ids   = $this->ids();

        if ( null === $class || [] === $ids ) {
            return $this->resolved = new Collection();
        }

        return $this->resolved = $class::query()->whereKey( $ids )->get();
    }

    /**
     * Gets what the notification log should record as the recipient.
     *
     * The role, or the notifiable keys it fell back to — an internal reference
     * either way, and summarised rather than truncated when a wide audience
     * would overrun the column, the way both database channels record theirs.
     *
     * @since 1.1.0
     *
     * @return string The audience, as the log should record it.
     */
    public function logReference(): string
    {
        $recipients = $this->recipients();

        if ( $this->byRole ) {
            return $this->fitRecipient( 'role:' . (string) $this->role(), 'role', $recipients->count() );
        }

        $class = (string) $this->notifiableClass();
        $keys  = $recipients
            ->map( static fn ( Model $notifiable ): string => (string) $notifiable->getKey() )
            ->all();

        return $this->fitRecipient(
            sprintf( '%s:%s', $class, implode( ',', $keys ) ),
            $class,
            count( $keys ),
        );
    }

    /**
     * Determines whether the audience is resolved by role rather than id list.
     *
     * The role is taken only when the database channel this installation binds is
     * the cms-framework one — the same decision {@see BookingsServiceProvider::usesCmsNotifications()}
     * makes for the channel itself, honouring the `driver` config as well as
     * which package is installed. Laravel's standalone channel ignores the role
     * entirely, so the admin email must too, or the two would target different
     * staff. A configured role the user model cannot answer — no `roles`
     * relationship to filter on — falls back to the id list rather than resolving
     * nobody.
     *
     * @since 1.1.0
     *
     * @return bool True when the role names the audience, false for the id list.
     */
    protected function resolvesByRole(): bool
    {
        return BookingsServiceProvider::usesCmsNotifications()
            && null !== $this->role()
            && null !== $this->roleUserModel();
    }

    /**
     * Gets the configured role to notify, when there is one.
     *
     * @since 1.1.0
     *
     * @return string|null The role name, or null when none is set.
     */
    protected function role(): ?string
    {
        $role = config( 'artisanpack.bookings.notifications.database.role' );

        if ( ! is_string( $role ) || '' === $role ) {
            return null;
        }

        return $role;
    }

    /**
     * Gets the host user model to resolve a role against, when it can carry one.
     *
     * The role is a cms-framework concept, and the query that reads it lives on
     * the host's user model — the same `whereHas( 'roles', ... )` cms-framework's
     * own notification manager uses. A user model without a `roles` relationship
     * cannot answer a role, so this returns null and {@see self::recipients()}
     * falls to the notifiable list rather than fataling on a relationship that
     * is not there. This is also what keeps the role path inert on an
     * installation that has no role system: nothing has a `roles` relationship,
     * so the role is quietly ignored.
     *
     * @since 1.1.0
     *
     * @return class-string<Model>|null The user model, or null when it has no roles.
     */
    protected function roleUserModel(): ?string
    {
        $class = config( 'auth.providers.users.model' );

        if ( ! is_string( $class ) || '' === $class ) {
            return null;
        }

        if ( ! class_exists( $class ) || ! is_a( $class, Model::class, true ) ) {
            return null;
        }

        if ( ! method_exists( $class, 'roles' ) ) {
            return null;
        }

        /** @var class-string<Model> $class */
        return $class;
    }

    /**
     * Gets the configured notifiable class, when it is usable.
     *
     * The same validation {@see Channels\DatabaseChannel::notifiableClass()}
     * applies: a class named in configuration but absent from the application
     * returns null rather than fataling inside a notification send.
     *
     * @since 1.1.0
     *
     * @return class-string<Model>|null The notifiable class, or null.
     */
    protected function notifiableClass(): ?string
    {
        $class = config( 'artisanpack.bookings.notifications.database.notifiable' );

        if ( ! is_string( $class ) || '' === $class ) {
            return null;
        }

        if ( ! class_exists( $class ) || ! is_a( $class, Model::class, true ) ) {
            return null;
        }

        /** @var class-string<Model> $class */
        return $class;
    }

    /**
     * Gets the explicitly configured notifiable keys to notify.
     *
     * The same filtering {@see Channels\DatabaseChannel::recipients()} applies:
     * blank and zero entries are dropped, and a numeric string becomes an int so
     * it matches an integer key.
     *
     * @since 1.1.0
     *
     * @return array<int, int|string> The notifiable keys.
     */
    protected function ids(): array
    {
        return array_values( array_filter(
            array_map(
                static fn ( mixed $id ): int|string => is_numeric( $id ) ? (int) $id : (string) $id,
                (array) config( 'artisanpack.bookings.notifications.database.ids', [] ),
            ),
            static fn ( int|string $id ): bool => '' !== $id && 0 !== $id,
        ) );
    }
}
