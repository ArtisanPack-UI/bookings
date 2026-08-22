<?php

/**
 * Admin webhooks index.
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

use ArtisanPackUI\Bookings\Models\Webhook;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The list an administrator manages a site's outbound webhook endpoints from.
 *
 * Every endpoint the site delivers events to, with the two facts that decide
 * whether it is working — whether it is still active, and how many times in a
 * row it has failed — alongside a way in to the delivery ledger behind each one.
 *
 * An endpoint that fails often enough is disabled rather than retried forever, so
 * a dead consumer stops holding a queue busy. Bringing it back is a deliberate
 * act an operator takes here once the consumer is fixed: {@see self::enable()}
 * clears the failure count so the endpoint gets a clean start rather than
 * resuming one attempt short of being disabled again.
 *
 * The query is site-scoped by the model, so nothing here names a tenant, and an
 * endpoint id belonging to another site resolves to a 404 through `findOrFail`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class WebhooksIndex extends Component
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
     * Whether only disabled endpoints are shown.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    #[Url]
    public bool $disabledOnly = false;

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
     * Resets pagination when the disabled filter changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedDisabledOnly(): void
    {
        $this->resetPage();
    }

    /**
     * Brings a disabled endpoint back into service.
     *
     * The failure count is reset alongside the flags. An endpoint is disabled
     * for failing its threshold, so re-enabling it without clearing the count
     * leaves it one failure from being disabled again — the operator fixed the
     * consumer, so the tally that condemned it no longer describes anything.
     *
     * @since 1.0.0
     *
     * @param  int  $webhookId  The endpoint to bring back.
     *
     * @return void
     */
    public function enable( int $webhookId ): void
    {
        $webhook = Webhook::query()->findOrFail( $webhookId );

        $webhook->forceFill( [
            'is_active'                 => true,
            'disabled_at'               => null,
            'consecutive_failure_count' => 0,
        ] )->save();

        $this->dispatch( 'bookings-webhook-enabled', webhookId: $webhookId );
    }

    /**
     * Stops delivering to an endpoint by hand.
     *
     * The model's own transition owns the write, so a manual disable produces
     * the same event and the same terminal state the automatic one does.
     *
     * @since 1.0.0
     *
     * @param  int  $webhookId  The endpoint to stop delivering to.
     *
     * @return void
     */
    public function disable( int $webhookId ): void
    {
        $webhook = Webhook::query()->findOrFail( $webhookId );

        $webhook->disable( __( 'Disabled by an administrator.' ) );

        $this->dispatch( 'bookings-webhook-disabled', webhookId: $webhookId );
    }

    /**
     * Asks the host page to open the delivery ledger for an endpoint.
     *
     * The ledger is its own component — a webhook has many deliveries and one
     * list of them does not belong nested inside another — so opening it is a
     * hand-off the host page decides how to present.
     *
     * @since 1.0.0
     *
     * @param  int  $webhookId  The endpoint whose deliveries to show.
     *
     * @return void
     */
    public function viewDeliveries( int $webhookId ): void
    {
        $this->dispatch( 'bookings-view-webhook-deliveries', webhookId: $webhookId );
    }

    /**
     * Gets the page of endpoints the current filters select.
     *
     * The delivery count rides along as a withCount so the list can show how
     * busy each endpoint is without loading a delivery to count them.
     *
     * @since 1.0.0
     *
     * @return LengthAwarePaginator<int, Webhook> The paginated endpoints.
     */
    public function webhooks(): LengthAwarePaginator
    {
        return Webhook::query()
            ->withCount( 'deliveries' )
            ->when(
                $this->disabledOnly,
                static fn ( $query ) => $query->whereNotNull( 'disabled_at' ),
            )
            ->when(
                '' !== trim( $this->search ),
                fn ( $query ) => $query->where( function ( $query ): void {
                    $term = '%' . trim( $this->search ) . '%';

                    $query->where( 'name', 'like', $term )
                        ->orWhere( 'url', 'like', $term );
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
        return view( 'bookings::livewire.admin.webhooks-index', [
            'webhooks' => $this->webhooks(),
        ] );
    }
}
