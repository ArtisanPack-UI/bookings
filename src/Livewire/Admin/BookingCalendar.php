<?php

/**
 * Admin booking calendar.
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

use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The week an administrator looks at to see the site's bookings at a glance.
 *
 * Seven days across, each day its bookings in the order they happen, so the
 * shape of the week — the full mornings, the empty afternoons — is legible
 * without opening anything. It is a calendar rendered on the server out of the
 * same query the list uses, not a JavaScript widget fed by an API: the package
 * is mounted inside whatever admin shell the host runs, and a server-rendered
 * grid fits that shell rather than fighting it, and reads back in a test the way
 * the list does.
 *
 * The days are laid out in the application's own zone, and each booking is
 * placed on the day it falls on there, so a late booking near a zone boundary
 * lands where the administrator reading the calendar expects it, not where its
 * stored UTC instant would put it. Choosing a booking hands off to the detail
 * view; the calendar itself only shows.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BookingCalendar extends Component
{
    /**
     * The first day of the week being shown, as `Y-m-d`.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url]
    public string $weekStart = '';

    /**
     * The provider the calendar is filtered to, or an empty string for all.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url]
    public string $providerId = '';

    /**
     * The service the calendar is filtered to, or an empty string for all.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url]
    public string $serviceId = '';

    /**
     * Opens the calendar on the week containing today.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function mount(): void
    {
        if ( '' === $this->weekStart ) {
            $this->weekStart = $this->today()->startOfWeek()->toDateString();
        }
    }

    /**
     * Steps back to the previous week.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function previousWeek(): void
    {
        $this->weekStart = $this->windowStart()->subWeek()->toDateString();
    }

    /**
     * Steps forward to the next week.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function nextWeek(): void
    {
        $this->weekStart = $this->windowStart()->addWeek()->toDateString();
    }

    /**
     * Returns to the week containing today.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function thisWeek(): void
    {
        $this->weekStart = $this->today()->startOfWeek()->toDateString();
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
     * Gets the week's bookings, keyed by the day they fall on.
     *
     * Each key is a `Y-m-d` date in the application's zone and each value is that
     * day's bookings in ascending time order, so the view can render a column per
     * day without deciding anything about placement itself.
     *
     * @since 1.0.0
     *
     * @return Collection<string, Collection<int, Booking>> The days' bookings.
     */
    public function bookingsByDay(): Collection
    {
        $timezone = $this->timezone();
        $start    = $this->windowStart();
        $end      = $start->addWeek();

        return Booking::query()
            ->with( [ 'service', 'provider' ] )
            ->where( 'start_time', '>=', $start->utc() )
            ->where( 'start_time', '<', $end->utc() )
            ->when( '' !== $this->providerId, fn ( Builder $query ) => $query->where( 'provider_id', (int) $this->providerId ) )
            ->when( '' !== $this->serviceId, fn ( Builder $query ) => $query->where( 'service_id', (int) $this->serviceId ) )
            ->orderBy( 'start_time' )
            ->get()
            ->groupBy( static fn ( Booking $booking ): string => $booking->start_time->copy()->setTimezone( $timezone )->toDateString() );
    }

    /**
     * Gets the seven days of the shown week.
     *
     * @since 1.0.0
     *
     * @return array<int, CarbonImmutable> The days, Monday first.
     */
    public function days(): array
    {
        $start = $this->windowStart();

        return array_map( static fn ( int $offset ): CarbonImmutable => $start->addDays( $offset ), range( 0, 6 ) );
    }

    /**
     * Renders the calendar.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'bookings::livewire.admin.booking-calendar', [
            'days'          => $this->days(),
            'bookingsByDay' => $this->bookingsByDay(),
            'timezone'      => $this->timezone(),
            'providers'     => ServiceProvider::query()->orderBy( 'name' )->pluck( 'name', 'id' ),
            'services'      => Service::query()->orderBy( 'name' )->pluck( 'name', 'id' ),
        ] );
    }

    /**
     * Gets the first day of the shown week as a zoned instant at midnight.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable The start of the week in the display zone.
     */
    protected function windowStart(): CarbonImmutable
    {
        $timezone = $this->timezone();

        try {
            $day = CarbonImmutable::parse( $this->weekStart, $timezone );
        } catch ( InvalidFormatException ) {
            $day = $this->today();
        }

        return $day->startOfWeek();
    }

    /**
     * Gets today as a zoned instant.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable The current day in the display zone.
     */
    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::now( $this->timezone() );
    }

    /**
     * Gets the zone the calendar is laid out in.
     *
     * @since 1.0.0
     *
     * @return string The timezone identifier.
     */
    protected function timezone(): string
    {
        return (string) config( 'app.timezone', 'UTC' );
    }
}
