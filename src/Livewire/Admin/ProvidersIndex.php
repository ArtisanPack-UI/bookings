<?php

/**
 * Admin providers index.
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

use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The list an administrator manages a site's service providers from.
 *
 * Every provider the site owns, searchable, with the switches that change one
 * without opening it — active on or off, and the soft delete that retires a
 * provider without taking their bookings' history with it. Opening one for a
 * fuller edit, editing their availability, or creating a new one is a hand-off:
 * this component dispatches the intent and the page that mounts it decides what
 * to show, because whether the editor is a modal, a panel, or another page is
 * the host application's layout to make, not this list's.
 *
 * The query is site-scoped by the model, so nothing here names a tenant. Deleted
 * providers are excluded by the soft-delete scope and can be asked for
 * separately, since a retired provider is the ordinary thing an administrator
 * comes here to bring back.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ProvidersIndex extends Component
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
     * Whether retired (soft-deleted) providers are shown instead of live ones.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    #[Url]
    public bool $trashed = false;

    /**
     * Resets pagination when the search term changes.
     *
     * Without this a filter that narrows the list to fewer pages can leave the
     * viewer stranded on a page number the filtered result no longer has,
     * looking at an empty list that has matches on page one.
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
     * Switches the list between live and retired providers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedTrashed(): void
    {
        $this->resetPage();
    }

    /**
     * Asks the host page to open the editor for a new provider.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function create(): void
    {
        $this->dispatch( 'bookings-edit-provider', providerId: null );
    }

    /**
     * Asks the host page to open the editor for an existing provider.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider to edit.
     *
     * @return void
     */
    public function edit( int $providerId ): void
    {
        $this->dispatch( 'bookings-edit-provider', providerId: $providerId );
    }

    /**
     * Asks the host page to open the availability editor for a provider.
     *
     * Availability is its own editor rather than a section of the provider form:
     * a provider's weekly hours and per-date exceptions are a different shape of
     * work from their name and contact details, and the two are edited — and
     * saved — apart so a change to one cannot quietly rewrite the other.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider whose availability to edit.
     *
     * @return void
     */
    public function editAvailability( int $providerId ): void
    {
        $this->dispatch( 'bookings-edit-provider-availability', providerId: $providerId );
    }

    /**
     * Flips a provider between assignable and not.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider to toggle.
     *
     * @return void
     */
    public function toggleActive( int $providerId ): void
    {
        $provider = ServiceProvider::query()->findOrFail( $providerId );

        $provider->update( [ 'is_active' => ! $provider->is_active ] );
    }

    /**
     * Retires a provider without erasing them.
     *
     * A soft delete rather than a hard one: the provider's past bookings still
     * have to render, and a row removed from under them would leave every one of
     * them pointing at nothing.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider to retire.
     *
     * @return void
     */
    public function delete( int $providerId ): void
    {
        ServiceProvider::query()->findOrFail( $providerId )->delete();

        $this->dispatch( 'bookings-provider-deleted', providerId: $providerId );
    }

    /**
     * Brings a retired provider back into service.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider to restore.
     *
     * @return void
     */
    public function restore( int $providerId ): void
    {
        ServiceProvider::withTrashed()
            ->whereKey( $providerId )
            ->firstOrFail()
            ->restore();

        $this->dispatch( 'bookings-provider-restored', providerId: $providerId );
    }

    /**
     * Gets the page of providers the current filters select.
     *
     * Ordered by the administrator's own sort order first, then by name, so the
     * list reads the way a customer-facing picker built from the same order
     * would — a provider moved to the top of the rota is at the top here too.
     *
     * @since 1.0.0
     *
     * @return LengthAwarePaginator<int, ServiceProvider> The paginated providers.
     */
    public function providers(): LengthAwarePaginator
    {
        return ServiceProvider::query()
            ->when( $this->trashed, static fn ( $query ) => $query->onlyTrashed() )
            ->when(
                '' !== trim( $this->search ),
                fn ( $query ) => $query->where( function ( $query ): void {
                    $term = '%' . trim( $this->search ) . '%';

                    $query->where( 'name', 'like', $term )
                        ->orWhere( 'slug', 'like', $term )
                        ->orWhere( 'email', 'like', $term );
                } ),
            )
            ->orderBy( 'sort_order' )
            ->orderBy( 'name' )
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
        return view( 'bookings::livewire.admin.providers-index', [
            'providers' => $this->providers(),
        ] );
    }
}
