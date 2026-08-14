<?php

/**
 * Admin calendar connections index.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Livewire\Admin;

use ArtisanPackUI\Bookings\Models\CalendarConnection;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The list an administrator watches a site's calendar connections from.
 *
 * Calendar sync runs where nobody is looking — a sweep on a schedule, a push
 * that arrives while the operator is elsewhere — so the failures it produces are
 * only recoverable if there is a surface that shows them. This is that surface:
 * every connection the site owns, which provider it belongs to, how it syncs,
 * when it last succeeded, and the error it stopped on when it stopped.
 *
 * A connection that has failed its threshold is disabled by the sweep rather
 * than retried forever, which turns a broken calendar into a row an operator has
 * to notice. Re-authorising the external account is the driver package's job and
 * happens elsewhere; what belongs here is the other half — telling the package
 * the account is good again, which {@see self::reconnect()} does by clearing the
 * failure state so the next sweep tries once more.
 *
 * The query is site-scoped by the model, so nothing here names a tenant, and a
 * connection id belonging to another site resolves to a 404 through `findOrFail`
 * rather than being actionable.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class CalendarConnectionsIndex extends Component
{
    use WithPagination;

    /**
     * The text the list is filtered by.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url( as: 'q' )]
    public string $search = '';

    /**
     * Whether only the connections needing attention are shown.
     *
     * A connection needs attention when it has been disabled or has a failure
     * on record — the rows an operator comes here to act on rather than to
     * admire.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    #[Url]
    public bool $unhealthy = false;

    /**
     * Resets pagination when the search term changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Resets pagination when the health filter changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedUnhealthy(): void
    {
        $this->resetPage();
    }

    /**
     * Clears a connection's failure state so the next sync sweep tries again.
     *
     * Re-authorising the external account is the driver package's flow, not
     * this one's; by the time an operator reaches for this the account is
     * already good, and what remains is to undo the guard the sweep put up.
     * The sync token goes with the reset: a token is a cursor into a change
     * feed, and resuming from one that predates whatever broke the connection
     * would skip everything that changed while it was down. A null token forces
     * the next sync to start clean.
     *
     * @since 1.0.0
     *
     * @param  int  $connectionId  The connection to bring back into service.
     *
     * @return void
     */
    public function reconnect( int $connectionId ): void
    {
        $connection = CalendarConnection::query()->findOrFail( $connectionId );

        $connection->forceFill( [
            'is_active'                 => true,
            'disabled_at'               => null,
            'consecutive_failure_count' => 0,
            'last_sync_error'           => null,
            'sync_token'                => null,
        ] )->save();

        $this->dispatch( 'bookings-calendar-connection-reconnected', connectionId: $connectionId );
    }

    /**
     * Stops syncing a connection by hand.
     *
     * The model's own transition owns the write, so a manual disable and the
     * sweep's automatic one produce the same event and the same cleared token
     * rather than two different half-states.
     *
     * @since 1.0.0
     *
     * @param  int  $connectionId  The connection to stop syncing.
     *
     * @return void
     */
    public function disable( int $connectionId ): void
    {
        $connection = CalendarConnection::query()->findOrFail( $connectionId );

        $connection->disable( __( 'Disabled by an administrator.' ) );

        $this->dispatch( 'bookings-calendar-connection-disabled', connectionId: $connectionId );
    }

    /**
     * Gets the page of connections the current filters select.
     *
     * The provider is eager loaded because every row names it, and loading it
     * per row would turn one list into a query per connection.
     *
     * @since 1.0.0
     *
     * @return LengthAwarePaginator<int, CalendarConnection> The paginated connections.
     */
    public function connections(): LengthAwarePaginator
    {
        return CalendarConnection::query()
            ->with( 'provider' )
            ->when(
                $this->unhealthy,
                static fn ( $query ) => $query->where( function ( $query ): void {
                    $query->whereNotNull( 'disabled_at' )
                        ->orWhere( 'consecutive_failure_count', '>', 0 );
                } ),
            )
            ->when(
                '' !== trim( $this->search ),
                fn ( $query ) => $query->where( function ( $query ): void {
                    $term = '%' . trim( $this->search ) . '%';

                    $query->where( 'external_calendar_id', 'like', $term )
                        ->orWhereHas( 'provider', function ( $query ) use ( $term ): void {
                            $query->where( 'name', 'like', $term );
                        } );
                } ),
            )
            ->orderByDesc( 'disabled_at' )
            ->orderByDesc( 'consecutive_failure_count' )
            ->orderBy( 'id' )
            ->paginate( 15 );
    }

    /**
     * Renders the index.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'bookings::livewire.admin.calendar-connections-index', [
            'connections' => $this->connections(),
        ] );
    }
}
