<?php

/**
 * Admin notifications log.
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

use ArtisanPackUI\Bookings\Models\NotificationLog;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The log an administrator answers "why didn't that go out?" from.
 *
 * A customer says the reminder never came; a member of staff swears the
 * cancellation notice did not. This is where the answer is — every notification
 * the package tried to send about a booking, the channel it went out on, and
 * whether it was sent, is still pending, or failed with the reason the transport
 * gave. Filtering to the failures turns a vague complaint into the row that
 * explains it.
 *
 * The log is read-only on purpose. Resending is not a button here: a
 * notification is claimed against a booking, a type, a channel, and a schedule
 * so it goes out exactly once, and a resend that bypassed that claim is the one
 * way to send a customer the same reminder twice. What went wrong is a thing to
 * read; making it go right is a fix to the channel, not a retry of the row.
 *
 * The log carries no site of its own; each row belongs to a booking that does.
 * Every query reaches the row through its site-scoped {@see \ArtisanPackUI\Bookings\Models\Booking},
 * so a notification about another site's booking is never listed here.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class NotificationsLog extends Component
{
    use WithPagination;

    /**
     * The recipient or booking reference the log is filtered by.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url( as: 'q' )]
    public string $search = '';

    /**
     * The send status the log is filtered by, or an empty string for all.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url]
    public string $status = '';

    /**
     * The lifecycle type the log is filtered by, or an empty string for all.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url]
    public string $type = '';

    /**
     * The channel the log is filtered by, or an empty string for all.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url]
    public string $channel = '';

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
     * Resets pagination when the type filter changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedType(): void
    {
        $this->resetPage();
    }

    /**
     * Resets pagination when the channel filter changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedChannel(): void
    {
        $this->resetPage();
    }

    /**
     * Gets the page of notification records the current filters select.
     *
     * The booking rides along as a withCount-free eager load of just its
     * reference, because every row names the booking it was about and loading
     * the whole booking per row is more than a reference needs.
     *
     * @since 1.0.0
     *
     * @return LengthAwarePaginator<int, NotificationLog> The paginated records.
     */
    public function notifications(): LengthAwarePaginator
    {
        return NotificationLog::query()
            ->whereHas( 'booking' )
            ->with( 'booking:id,booking_number' )
            ->when(
                '' !== $this->status,
                fn ( $query ) => $query->where( 'status', $this->status ),
            )
            ->when(
                '' !== $this->type,
                fn ( $query ) => $query->where( 'type', $this->type ),
            )
            ->when(
                '' !== trim( $this->channel ),
                fn ( $query ) => $query->where( 'channel', trim( $this->channel ) ),
            )
            ->when(
                '' !== trim( $this->search ),
                fn ( $query ) => $query->where( function ( $query ): void {
                    $term = '%' . trim( $this->search ) . '%';

                    $query->where( 'recipient', 'like', $term )
                        ->orWhereHas( 'booking', function ( $query ) use ( $term ): void {
                            $query->where( 'booking_number', 'like', $term );
                        } );
                } ),
            )
            ->latest( 'id' )
            ->paginate( 20 );
    }

    /**
     * Gets the channels present in the site's log for the filter control.
     *
     * The list is drawn from the log itself rather than from config so it only
     * ever offers a channel there are rows to find — a channel switched off
     * after it sent leaves its history behind, and a channel opted into by a
     * subscriber never appears in config at all. Reached through the booking so
     * the list is site-scoped like everything else here.
     *
     * @since 1.0.0
     *
     * @return Collection<int, string> The distinct channel names, sorted.
     */
    public function channels(): Collection
    {
        return NotificationLog::query()
            ->whereHas( 'booking' )
            ->distinct()
            ->orderBy( 'channel' )
            ->pluck( 'channel' );
    }

    /**
     * Renders the log.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'bookings::livewire.admin.notifications-log', [
            'notifications' => $this->notifications(),
            'channels'      => $this->channels(),
        ] );
    }
}
