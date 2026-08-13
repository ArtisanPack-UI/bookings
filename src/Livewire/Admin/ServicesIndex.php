<?php

/**
 * Admin services index.
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

use ArtisanPackUI\Bookings\Models\Service;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The list an administrator manages a site's services from.
 *
 * Every service the site owns, searchable, with the switches that change one
 * without opening it — active on or off, and the soft delete that retires a
 * service without taking its history with it. Opening one for a fuller edit, or
 * creating a new one, is a hand-off: this component dispatches the intent and
 * the page that mounts it decides what to show, because whether the editor is a
 * modal, a panel, or another page is the host application's layout to make, not
 * this list's.
 *
 * The query is site-scoped by the model, so nothing here names a tenant. Deleted
 * services are excluded by the soft-delete scope and can be asked for
 * separately, since a retired service is the ordinary thing an administrator
 * comes here to bring back.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ServicesIndex extends Component
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
     * Whether retired (soft-deleted) services are shown instead of live ones.
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
     * Switches the list between live and retired services.
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
     * Asks the host page to open the editor for a new service.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function create(): void
    {
        $this->dispatch( 'bookings-edit-service', serviceId: null );
    }

    /**
     * Asks the host page to open the editor for an existing service.
     *
     * @since 1.0.0
     *
     * @param  int  $serviceId  The service to edit.
     *
     * @return void
     */
    public function edit( int $serviceId ): void
    {
        $this->dispatch( 'bookings-edit-service', serviceId: $serviceId );
    }

    /**
     * Flips a service between bookable and not.
     *
     * @since 1.0.0
     *
     * @param  int  $serviceId  The service to toggle.
     *
     * @return void
     */
    public function toggleActive( int $serviceId ): void
    {
        $service = Service::query()->findOrFail( $serviceId );

        $service->update( [ 'is_active' => ! $service->is_active ] );
    }

    /**
     * Retires a service without erasing it.
     *
     * A soft delete rather than a hard one: the service's past bookings and the
     * versions of its intake form still have to render, and a row removed from
     * under them would leave every one of them pointing at nothing.
     *
     * @since 1.0.0
     *
     * @param  int  $serviceId  The service to retire.
     *
     * @return void
     */
    public function delete( int $serviceId ): void
    {
        Service::query()->findOrFail( $serviceId )->delete();

        $this->dispatch( 'bookings-service-deleted', serviceId: $serviceId );
    }

    /**
     * Brings a retired service back into service.
     *
     * @since 1.0.0
     *
     * @param  int  $serviceId  The service to restore.
     *
     * @return void
     */
    public function restore( int $serviceId ): void
    {
        Service::withTrashed()
            ->whereKey( $serviceId )
            ->firstOrFail()
            ->restore();

        $this->dispatch( 'bookings-service-restored', serviceId: $serviceId );
    }

    /**
     * Gets the page of services the current filters select.
     *
     * @since 1.0.0
     *
     * @return LengthAwarePaginator<int, Service> The paginated services.
     */
    public function services(): LengthAwarePaginator
    {
        return Service::query()
            ->when( $this->trashed, static fn ( $query ) => $query->onlyTrashed() )
            ->when(
                '' !== trim( $this->search ),
                fn ( $query ) => $query->where( function ( $query ): void {
                    $term = '%' . trim( $this->search ) . '%';

                    $query->where( 'name', 'like', $term )
                        ->orWhere( 'slug', 'like', $term );
                } ),
            )
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
        return view( 'bookings::livewire.admin.services-index', [
            'services' => $this->services(),
        ] );
    }
}
