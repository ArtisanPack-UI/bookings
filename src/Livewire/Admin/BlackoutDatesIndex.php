<?php

/**
 * Admin blackout dates index.
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

use ArtisanPackUI\Bookings\Models\Scopes\BelongsToSiteScope;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceBlackoutDate;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The list an administrator manages a site's closures from.
 *
 * A blackout is a date range a service cannot be booked in — a holiday, a
 * retreat, an off-week — and it is the right tool where a provider-level
 * override is the wrong one: the closure belongs to the service, not to whoever
 * happens to staff it. A blackout with no service named closes every service on
 * the site at once, which is how a whole-business shutdown is written; one that
 * names a service closes only that service, for the days a single offering
 * pauses while everything else runs.
 *
 * Unlike the services and providers lists, editing happens here rather than in a
 * handed-off editor. A blackout is three fields — a range, an optional service,
 * a reason — with no second surface like a provider's availability to justify a
 * component of its own, so the list opens an inline form over itself instead. A
 * save creates or updates the one row and closes the form; nothing is diffed,
 * because there is nothing but the one row to diff.
 *
 * The query is site-scoped by the model, so nothing here names a tenant, and a
 * blackout id from another site is a 404 rather than a row an administrator
 * could edit or delete. The service dropdown is likewise drawn from the
 * site-scoped services list, and a service id is validated back against it so a
 * closure cannot be pinned to a service the site does not own.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class BlackoutDatesIndex extends Component
{
    use WithPagination;

    /**
     * The text the list is filtered by, matched against a blackout's reason.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url( as: 'q' )]
    public string $search = '';

    /**
     * The blackout being edited, or null while creating one or with no form open.
     *
     * Locked: it is the row a save writes over, and a client that could repoint
     * it could edit a closure the administrator never opened — including, if the
     * id crossed a tenant boundary, one the site-scoped list would never have
     * shown them.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    #[Locked]
    public ?int $editingId = null;

    /**
     * Whether the inline create/edit form is open.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $showForm = false;

    /**
     * The service the form's blackout closes, or null for every service.
     *
     * A nullable int rather than a string: the "All services" option submits an
     * empty value, which Livewire casts to null here, and every other option
     * submits a service id.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    public ?int $serviceId = null;

    /**
     * The first day the form's blackout closes, as a `Y-m-d` string.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $startsOn = '';

    /**
     * The last day the form's blackout closes, as a `Y-m-d` string.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $endsOn = '';

    /**
     * The reason shown against the blackout, if any.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $reason = '';

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
     * Opens a blank form for a new blackout.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function create(): void
    {
        $this->resetForm();

        $this->showForm = true;
    }

    /**
     * Opens the form on an existing blackout.
     *
     * @since 1.0.0
     *
     * @param  int  $blackoutId  The blackout to edit.
     *
     * @return void
     */
    public function edit( int $blackoutId ): void
    {
        $blackout = ServiceBlackoutDate::query()->findOrFail( $blackoutId );

        $this->resetForm();

        $this->editingId = $blackout->getKey();
        $this->serviceId = $blackout->service_id;
        $this->startsOn  = $blackout->starts_on->toDateString();
        $this->endsOn    = $blackout->ends_on->toDateString();
        $this->reason    = (string) $blackout->reason;
        $this->showForm  = true;
    }

    /**
     * Validates the form and creates or updates the blackout.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function save(): void
    {
        $this->validate();

        $attributes = [
            'service_id' => $this->serviceId,
            'starts_on'  => $this->startsOn,
            'ends_on'    => $this->endsOn,
            'reason'     => '' === trim( $this->reason ) ? null : $this->reason,
        ];

        if ( null === $this->editingId ) {
            $blackout        = ServiceBlackoutDate::query()->create( $attributes );
            $this->editingId = $blackout->getKey();
        } else {
            ServiceBlackoutDate::query()->findOrFail( $this->editingId )->update( $attributes );
        }

        $this->dispatch( 'bookings-blackout-saved', blackoutId: $this->editingId );

        $this->resetForm();
    }

    /**
     * Closes the form without saving.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->resetForm();
    }

    /**
     * Deletes a blackout, reopening the days it closed.
     *
     * A hard delete rather than a soft one: a blackout is a rule, not a record
     * anything downstream points back at, so a lifted closure has nothing to
     * keep readable and is simply gone.
     *
     * @since 1.0.0
     *
     * @param  int  $blackoutId  The blackout to delete.
     *
     * @return void
     */
    public function delete( int $blackoutId ): void
    {
        ServiceBlackoutDate::query()->findOrFail( $blackoutId )->delete();

        if ( $blackoutId === $this->editingId ) {
            $this->resetForm();
        }

        $this->dispatch( 'bookings-blackout-deleted', blackoutId: $blackoutId );
    }

    /**
     * Gets the services an administrator pins a blackout to, ordered by name.
     *
     * Site-scoped by the model and keyed by id so the form can offer them as a
     * dropdown alongside the site-wide option. Retired services are left out:
     * a closure is a forward-looking rule, and there is no reason to pin a new
     * one to a service that no longer takes bookings.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The id-to-name map.
     */
    public function services(): array
    {
        return Service::query()
            ->orderBy( 'name' )
            ->pluck( 'name', 'id' )
            ->all();
    }

    /**
     * Gets the page of blackouts the current filter selects.
     *
     * The service is eager loaded so the list can name each closure's service
     * without a query per row, and the ranges are ordered soonest first so the
     * next closure an administrator has to think about is at the top.
     *
     * @since 1.0.0
     *
     * @return LengthAwarePaginator<int, ServiceBlackoutDate> The paginated blackouts.
     */
    public function blackouts(): LengthAwarePaginator
    {
        return ServiceBlackoutDate::query()
            ->with( 'service' )
            ->when(
                '' !== trim( $this->search ),
                fn ( $query ) => $query->where( 'reason', 'like', '%' . trim( $this->search ) . '%' ),
            )
            ->orderBy( 'starts_on' )
            ->orderBy( 'ends_on' )
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
        return view( 'bookings::livewire.admin.blackout-dates-index', [
            'blackouts' => $this->blackouts(),
            // The dropdown the service list feeds only exists while the form is
            // open, so the query is skipped on the search, pagination, and delete
            // round trips that never render it.
            'services'  => $this->showForm ? $this->services() : [],
        ] );
    }

    /**
     * Clears the form back to its empty state and closes it.
     *
     * The error bag is reset with it so a validation message from an abandoned
     * edit does not hang over the next one opened.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->serviceId = null;
        $this->startsOn  = '';
        $this->endsOn    = '';
        $this->reason    = '';
        $this->showForm  = false;

        $this->resetErrorBag();
    }

    /**
     * Gets the validation rules the form is checked against.
     *
     * The service is validated the same way the site scope filters: when a site
     * is in context the id must be one that site owns, so a closure cannot be
     * pinned across a tenant boundary; when no site is in context the scope is
     * inert and every service is visible, so the rule is left unconstrained to
     * match — pinning it to `site_id IS NULL` instead would reject the very
     * services the dropdown offered. The end date may equal the start — a
     * one-day closure is a range that opens and closes on the same day — but
     * never precede it.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The rules.
     */
    protected function rules(): array
    {
        $siteId = BelongsToSiteScope::currentSiteId();

        $serviceExists = Rule::exists( 'services', 'id' );

        if ( null !== $siteId ) {
            $serviceExists->where( 'site_id', $siteId );
        }

        return [
            'serviceId' => [
                'nullable',
                'integer',
                $serviceExists,
            ],
            'startsOn'  => [ 'required', 'date' ],
            'endsOn'    => [ 'required', 'date', 'after_or_equal:startsOn' ],
            'reason'    => [ 'nullable', 'string', 'max:255' ],
        ];
    }

    /**
     * Gets the human-readable names the validator uses for the form's fields.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The field-to-label map.
     */
    protected function validationAttributes(): array
    {
        return [
            'serviceId' => __( 'service' ),
            'startsOn'  => __( 'start date' ),
            'endsOn'    => __( 'end date' ),
            'reason'    => __( 'reason' ),
        ];
    }
}
