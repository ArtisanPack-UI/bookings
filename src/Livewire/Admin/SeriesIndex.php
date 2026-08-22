<?php

/**
 * Admin series index.
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

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Exceptions\SeriesException;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use ArtisanPackUI\Bookings\Services\SeriesService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The list an administrator manages a site's recurring booking series from.
 *
 * Every series the site owns, searchable by customer, with the two things an
 * administrator does to one without opening the editor: cancelling the whole
 * arrangement, and counting how many of its occurrences have been detached — the
 * flag that says somebody moved one appointment away from the rule. Opening a
 * series to edit it is a hand-off: this component dispatches the intent and the
 * page that mounts it decides whether the editor is a modal, a panel, or another
 * page, exactly as {@see ProvidersIndex} does.
 *
 * The query is site-scoped by the model, so nothing here names a tenant.
 * Cancelled series are hidden until they are asked for, since a live arrangement
 * is the ordinary thing an administrator comes here to manage.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SeriesIndex extends Component
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
     * Whether cancelled series are shown instead of live ones.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    #[Url]
    public bool $cancelled = false;

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
     * Switches the list between live and cancelled series.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedCancelled(): void
    {
        $this->resetPage();
    }

    /**
     * Asks the host page to open the editor for a series.
     *
     * @since 1.0.0
     *
     * @param  int  $seriesId  The series to edit.
     *
     * @return void
     */
    public function edit( int $seriesId ): void
    {
        $this->dispatch( 'bookings-edit-series', seriesId: $seriesId );
    }

    /**
     * Calls off a whole arrangement, along with everything still to come.
     *
     * Routed through {@see SeriesService} rather than writing `cancelled_at`
     * here, so the future occurrences are cancelled with it and
     * {@see \ArtisanPackUI\Bookings\Events\SeriesCancelled} fires with the actor
     * stamped as the admin. A series already cancelled is left as it is and the
     * reason shown rather than throwing.
     *
     * @since 1.0.0
     *
     * @param  int  $seriesId  The series to cancel.
     *
     * @return void
     */
    public function cancel( int $seriesId ): void
    {
        $series = BookingSeries::query()->findOrFail( $seriesId );

        try {
            app( SeriesService::class )->cancel( $series, BookingActor::Admin );
        } catch ( SeriesException ) {
            // The exception text names rules and ids an operator cannot act on;
            // a translated message stands in for it rather than reaching the bag.
            $this->addError( 'series', __( 'This recurring booking could not be cancelled.' ) );

            return;
        }

        $this->resetErrorBag();

        $this->dispatch( 'bookings-series-cancelled', seriesId: $seriesId );
    }

    /**
     * Gets the page of series the current filters select.
     *
     * Each row carries how many occurrences it has and how many of those have
     * been detached from the rule, so the list can flag an arrangement somebody
     * has edited an appointment out of without opening it. Newest first, since a
     * series just created is the one most likely to need a second look.
     *
     * @since 1.0.0
     *
     * @return LengthAwarePaginator<int, BookingSeries> The paginated series.
     */
    public function series(): LengthAwarePaginator
    {
        return BookingSeries::query()
            ->with( [ 'service', 'provider' ] )
            ->withCount( [
                'occurrences',
                'occurrences as detached_occurrences_count' => static fn ( $query ) => $query->whereNotNull( 'detached_from_series_at' ),
            ] )
            ->when(
                $this->cancelled,
                static fn ( $query ) => $query->whereNotNull( 'cancelled_at' ),
                static fn ( $query ) => $query->whereNull( 'cancelled_at' ),
            )
            ->when(
                '' !== trim( $this->search ),
                function ( $query ): void {
                    $term = '%' . trim( $this->search ) . '%';

                    $query->where( function ( $query ) use ( $term ): void {
                        $query->where( 'customer_name', 'like', $term )
                            ->orWhere( 'customer_email', 'like', $term );
                    } );
                },
            )
            ->orderByDesc( 'created_at' )
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
        return view( 'bookings::livewire.admin.series-index', [
            'series' => $this->series(),
        ] );
    }
}
