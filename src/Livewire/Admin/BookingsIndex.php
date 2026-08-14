<?php

/**
 * Admin bookings index.
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

use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The list an administrator searches, filters, and exports a site's bookings from.
 *
 * Every booking the site owns, narrowed by the things an administrator actually
 * comes here to narrow by — who it is, what it is, who is giving it, and where
 * it sits in its lifecycle — and handed off to the detail view for the single
 * booking actions rather than acting on a row from the list. The list is a place
 * to find a booking; {@see BookingDetail} is where cancelling, rescheduling, and
 * marking a no-show live, because each of those wants a reason or a new time the
 * width of a table row has no room for.
 *
 * The query is site-scoped by the model, so nothing here names a tenant. The
 * export streams the same filtered set the list is showing rather than a fresh
 * query, so what an administrator downloads is exactly what they were looking at.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingsIndex extends Component
{
    use WithPagination;

    /**
     * The text the list is filtered by.
     *
     * Matched against the customer's name and email and the booking number, so
     * an administrator can paste any of the three they were handed and find the
     * booking without knowing which one it is.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url( as: 'q' )]
    public string $search = '';

    /**
     * The status the list is filtered to, or an empty string for every status.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url]
    public string $status = '';

    /**
     * The provider the list is filtered to, or an empty string for every provider.
     *
     * Kept as a string because it is bound to a `<select>` whose empty option
     * is the "all providers" case; it is cast to an id only when a query is built.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url]
    public string $providerId = '';

    /**
     * The service the list is filtered to, or an empty string for every service.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url]
    public string $serviceId = '';

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
     * Resets pagination when the status filter changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Resets pagination when the provider filter changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedProviderId(): void
    {
        $this->resetPage();
    }

    /**
     * Resets pagination when the service filter changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedServiceId(): void
    {
        $this->resetPage();
    }

    /**
     * Asks the host page to open the detail view for a booking.
     *
     * @since 1.0.0
     *
     * @param  int  $bookingId  The booking to open.
     *
     * @return void
     */
    public function view( int $bookingId ): void
    {
        $this->dispatch( 'bookings-view-booking', bookingId: $bookingId );
    }

    /**
     * Streams the filtered bookings as a CSV download.
     *
     * The rows are the same set the list is showing, in the same order, walked a
     * chunk at a time so a site with a long history exports without holding every
     * booking in memory at once. Times are written in ISO 8601 with their offset
     * rather than a locale format, so a spreadsheet importing them keeps the
     * instant rather than guessing a timezone.
     *
     * @since 1.0.0
     *
     * @return StreamedResponse The CSV download.
     */
    public function export(): StreamedResponse
    {
        $query = $this->baseQuery()->with( [ 'service', 'provider' ] );

        $columns = [
            __( 'Booking number' ),
            __( 'Status' ),
            __( 'Service' ),
            __( 'Provider' ),
            __( 'Customer name' ),
            __( 'Customer email' ),
            __( 'Starts' ),
            __( 'Ends' ),
        ];

        return response()->streamDownload( static function () use ( $query, $columns ): void {
            $handle = fopen( 'php://output', 'wb' );

            fputcsv( $handle, $columns );

            $query->chunk( 200, static function ( $bookings ) use ( $handle ): void {
                foreach ( $bookings as $booking ) {
                    fputcsv( $handle, array_map( self::csvCell( ... ), [
                        $booking->booking_number,
                        $booking->status->value,
                        $booking->service?->name ?? '',
                        $booking->provider?->name ?? '',
                        $booking->customer_name,
                        $booking->customer_email,
                        $booking->start_time->toIso8601String(),
                        $booking->end_time->toIso8601String(),
                    ] ) );
                }
            } );

            fclose( $handle );
        }, 'bookings.csv', [ 'Content-Type' => 'text/csv' ] );
    }

    /**
     * Gets the page of bookings the current filters select.
     *
     * @since 1.0.0
     *
     * @return LengthAwarePaginator<int, Booking> The paginated bookings.
     */
    public function bookings(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->with( [ 'service', 'provider' ] )
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
        return view( 'bookings::livewire.admin.bookings-index', [
            'bookings'  => $this->bookings(),
            'statuses'  => BookingStatus::cases(),
            'providers' => ServiceProvider::query()->orderBy( 'name' )->pluck( 'name', 'id' ),
            'services'  => Service::query()->orderBy( 'name' )->pluck( 'name', 'id' ),
        ] );
    }

    /**
     * Builds the query the current filters describe.
     *
     * Shared by the list and the export so the two can never disagree about what
     * the filters mean.
     *
     * @since 1.0.0
     *
     * @return Builder<Booking> The filtered, ordered query.
     */
    protected function baseQuery(): Builder
    {
        $status = BookingStatus::tryFrom( $this->status );

        return Booking::query()
            ->when(
                '' !== trim( $this->search ),
                fn ( Builder $query ) => $query->where( function ( Builder $query ): void {
                    $term = '%' . trim( $this->search ) . '%';

                    $query->where( 'customer_name', 'like', $term )
                        ->orWhere( 'customer_email', 'like', $term )
                        ->orWhere( 'booking_number', 'like', $term );
                } ),
            )
            ->when( null !== $status, fn ( Builder $query ) => $query->where( 'status', $status ) )
            ->when( '' !== $this->providerId, fn ( Builder $query ) => $query->where( 'provider_id', (int) $this->providerId ) )
            ->when( '' !== $this->serviceId, fn ( Builder $query ) => $query->where( 'service_id', (int) $this->serviceId ) )
            ->orderByDesc( 'start_time' );
    }

    /**
     * Neutralises a value a spreadsheet might read as a formula.
     *
     * Customer names and emails are typed by the public, so a booking made under
     * a name like `=HYPERLINK(…)` writes a cell that Excel or Sheets evaluates the
     * moment an administrator opens the export. Prefixing the danger characters
     * with an apostrophe keeps the text readable while stopping the spreadsheet
     * from treating it as anything but text.
     *
     * @since 1.0.0
     *
     * @param  string  $value  The cell value.
     *
     * @return string The value, safe to write.
     */
    protected static function csvCell( string $value ): string
    {
        if ( 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ) {
            return "'" . $value;
        }

        return $value;
    }
}
