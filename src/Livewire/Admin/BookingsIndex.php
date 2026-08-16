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

use ArtisanPackUI\Bookings\Enums\BookingActor;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Exceptions\BookingException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Services\BookingService;
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
 * What the list does own is the actions there is no per-booking decision to make
 * about: an administrator selects rows and cancels, reassigns, or erases the
 * personal data on all of them at once. Each is the single-booking operation run
 * in a loop rather than a batch write, so whatever the one-booking action emits,
 * it emits once per booking: bulk cancel fires `ap.bookings.cancelling` and
 * `cancelled` and dispatches {@see \ArtisanPackUI\Bookings\Events\BookingCancelled}
 * for every booking, so a subscriber counting cancellations sees N of them; bulk
 * reassign fires the provider-selection filters `ap.bookings.availableProviders`
 * and `ap.bookings.roundRobin.selectProvider` once for each booking it moves.
 * Reassignment is deliberately silent otherwise — it registers no lifecycle hook
 * or event of its own, per the hook contract this work was cut against. Erasure
 * is not a lifecycle change and fires nothing; it overwrites the personal columns
 * in place and marks the row erased.
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
     * The ids of the bookings a bulk action will act on.
     *
     * Held as strings because they arrive from checkbox inputs, and cast to ids
     * only when a bulk action loads the rows. A filter change empties this: a
     * selection made against one filtered view must not carry over and act on
     * bookings the administrator can no longer see.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public array $selected = [];

    /**
     * Whether the "select every booking on this page" checkbox is ticked.
     *
     * Bound to the header checkbox and kept in step with {@see self::$selected}
     * by {@see self::updatedSelectPage()} — ticking it selects the visible page,
     * unticking it clears the selection.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $selectPage = false;

    /**
     * The outcome of the last bulk action, shown once and then cleared.
     *
     * Empty when there is nothing to report. A bulk action sets it to a sentence
     * saying how many bookings it touched, and the next filter change or action
     * clears it so a stale count is never left on screen.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $statusMessage = '';

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
     * Resets pagination and clears the selection when the search term changes.
     *
     * Without the page reset a filter that narrows the list to fewer pages can
     * leave the viewer stranded on a page number the filtered result no longer
     * has, looking at an empty list that has matches on page one. The selection
     * is cleared for the same reason a bulk action never trusts a stale one: rows
     * chosen against the previous filter must not survive into a view that no
     * longer shows them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    /**
     * Resets pagination and clears the selection when the status filter changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    /**
     * Resets pagination and clears the selection when the provider filter changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedProviderId(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    /**
     * Resets pagination and clears the selection when the service filter changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedServiceId(): void
    {
        $this->resetPage();
        $this->clearSelection();
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
     * Selects or clears every booking on the page in step with the header box.
     *
     * Ticking the header checkbox fills the selection with the ids the current
     * page is showing rather than every booking the filters match: an action is
     * only ever offered the rows in front of the administrator, so "select all"
     * means the page, not the history behind it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedSelectPage( bool $value ): void
    {
        $this->selected = $value
            ? $this->bookings()->getCollection()->map( static fn ( Booking $booking ): string => (string) $booking->id )->all()
            : [];
    }

    /**
     * Cancels every selected booking, one lifecycle event at a time.
     *
     * Each booking is cancelled through {@see BookingService} exactly as the
     * detail view cancels one, so `ap.bookings.cancelling` and `cancelled` fire
     * once per booking and the cancellation notification is sent for each. A
     * booking already past cancelling — one that no longer holds a slot — is
     * skipped rather than aborting the batch, so a selection that mixes live and
     * finished bookings cancels the ones it can.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function cancelSelected(): void
    {
        $service = app( BookingService::class );

        $count = $this->eachSelected( static function ( Booking $booking ) use ( $service ): bool {
            try {
                $service->cancel( $booking, BookingActor::Admin );
            } catch ( BookingException ) {
                return false;
            }

            return true;
        } );

        $this->statusMessage = trans_choice(
            '{0}No bookings were cancelled.|{1}:count booking was cancelled.|[2,*]:count bookings were cancelled.',
            $count,
            [ 'count' => $count ],
        );
    }

    /**
     * Reassigns every selected booking to another available provider.
     *
     * Each booking is handed to {@see BookingService::reassign()}, which fires
     * `ap.bookings.availableProviders` and `ap.bookings.roundRobin.selectProvider`
     * once for that booking, so a bulk reassign fires each hook once per booking.
     * A booking with no other provider free at its time is left where it is and
     * skipped, so the count reports how many actually moved.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function reassignSelected(): void
    {
        $service = app( BookingService::class );

        $count = $this->eachSelected( static function ( Booking $booking ) use ( $service ): bool {
            // A booking whose service has since been soft-deleted has nothing to
            // resolve providers against; reassign() rejects it by argument rather
            // than as a BookingException, so it is skipped here instead of being
            // allowed to abort the rest of the batch.
            if ( null === $booking->service ) {
                return false;
            }

            try {
                $service->reassign( $booking );
            } catch ( BookingException ) {
                return false;
            }

            return true;
        } );

        $this->statusMessage = trans_choice(
            '{0}No bookings were reassigned.|{1}:count booking was reassigned.|[2,*]:count bookings were reassigned.',
            $count,
            [ 'count' => $count ],
        );
    }

    /**
     * Erases the personal data on every selected booking.
     *
     * Erasure is not a state transition and fires no lifecycle hook: each row's
     * personal columns are overwritten in place and the row marked erased. A
     * booking already erased is left untouched and not counted, so running this
     * twice over the same selection reports nothing the second time.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function erasePiiSelected(): void
    {
        $count = $this->eachSelected( static function ( Booking $booking ): bool {
            if ( $booking->isPiiErased() ) {
                return false;
            }

            $booking->erasePersonalData();

            return true;
        } );

        $this->statusMessage = trans_choice(
            '{0}No bookings were erased.|{1}Personal data was erased on :count booking.|[2,*]Personal data was erased on :count bookings.',
            $count,
            [ 'count' => $count ],
        );
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
     * Runs a bulk action over the selected bookings and counts the ones it took.
     *
     * The rows are loaded through the model, so the site scope decides which
     * selected ids resolve to a booking — an id smuggled in for a booking on
     * another site simply is not found. The callback returns whether it acted on
     * a booking, and the total of the trues is what the caller reports. The
     * selection is cleared once, here, so every action ends with an empty
     * selection and a header checkbox that no longer claims a stale page.
     *
     * @since 1.0.0
     *
     * @param  callable(Booking): bool  $action  What to do to each booking, returning
     *                                           whether it counted.
     *
     * @return int How many bookings the action took.
     */
    protected function eachSelected( callable $action ): int
    {
        $bookings = Booking::query()
            ->whereIn( 'id', array_map( 'intval', $this->selected ) )
            ->with( [ 'service', 'provider' ] )
            ->get();

        $count = 0;

        foreach ( $bookings as $booking ) {
            if ( $action( $booking ) ) {
                ++$count;
            }
        }

        $this->clearSelection();

        return $count;
    }

    /**
     * Empties the selection, unticks the header checkbox, clears the last message.
     *
     * The message is cleared here so a filter change takes the stale count off
     * screen with the selection it described. {@see self::eachSelected()} clears
     * the selection before its caller writes the new message, so an action's own
     * result is set after this runs and survives it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function clearSelection(): void
    {
        $this->selected      = [];
        $this->selectPage    = false;
        $this->statusMessage = '';
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
