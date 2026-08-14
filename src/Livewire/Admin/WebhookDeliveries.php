<?php

/**
 * Admin webhook deliveries ledger.
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

use ArtisanPackUI\Bookings\Enums\WebhookDeliveryStatus;
use ArtisanPackUI\Bookings\Models\Webhook;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use ArtisanPackUI\Bookings\Services\WebhookDispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The ledger an administrator reads a site's webhook delivery attempts from.
 *
 * Every attempt to deliver an event is a row here — what was sent, when it was
 * tried, what came back, and whether the retry sweep will come back for it. It
 * is the answer to a consumer that claims an event never arrived: the payload
 * that went out is stored, so an operator can see exactly what was sent rather
 * than reconstruct it.
 *
 * A delivery that gave up can be replayed. {@see self::replay()} does not
 * resurrect the dead row — an attempt is a record of a moment and rewriting it
 * would lose what happened — it queues a fresh delivery of the same event to the
 * same endpoint, which the ledger then shows alongside the one it retries.
 *
 * Deliveries carry no site of their own; they belong to an endpoint that does.
 * Every query and every action reaches the delivery through its site-scoped
 * {@see Webhook}, so a delivery whose endpoint belongs to another site is
 * neither listed nor replayable — it resolves to a 404 the same way a directly
 * scoped row would.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class WebhookDeliveries extends Component
{
    use WithPagination;

    /**
     * The endpoint the ledger is narrowed to, or null for every endpoint's.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    #[Url]
    public ?int $webhookId = null;

    /**
     * The delivery status the ledger is filtered by, or an empty string for all.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Url]
    public string $status = '';

    /**
     * The delivery whose stored payload is expanded, or null when none is.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    public ?int $expandedId = null;

    /**
     * Focuses the ledger on one endpoint when the host opens it for one.
     *
     * @since 1.0.0
     *
     * @param  int|null  $webhookId  The endpoint to scope to, or null for all.
     *
     * @return void
     */
    public function mount( ?int $webhookId = null ): void
    {
        $this->webhookId = $webhookId;
    }

    /**
     * Resets pagination when the endpoint filter changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedWebhookId(): void
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
     * Shows or hides the stored payload for one delivery.
     *
     * The payload is a copy of the event body, which means it carries the
     * customer's name and email, so it is folded away until an operator asks
     * for it rather than printed into every row of the ledger.
     *
     * @since 1.0.0
     *
     * @param  int  $deliveryId  The delivery whose payload to toggle.
     *
     * @return void
     */
    public function togglePayload( int $deliveryId ): void
    {
        $this->expandedId = $this->expandedId === $deliveryId ? null : $deliveryId;
    }

    /**
     * Queues a fresh delivery of a past attempt's event to the same endpoint.
     *
     * The delivery is reached through its endpoint so a cross-site id finds
     * nothing, and the endpoint is loaded the same scoped way for the same
     * reason. A replay onto a disabled endpoint is refused rather than queued to
     * die on arrival: disabling an endpoint is how an operator says stop sending
     * to it, and honouring a replay would be sending to it.
     *
     * Only a failed or dead attempt is replayable. Replay is a recovery action,
     * and a delivery the consumer already accepted has nothing to recover — re-
     * sending it would hand a non-idempotent consumer the same event twice. A
     * pending attempt is still on its way, so replaying it duplicates a delivery
     * that has not finished. The guard is enforced here rather than only in the
     * markup so a direct action call cannot get around the hidden button.
     *
     * @since 1.0.0
     *
     * @param  WebhookDispatcher  $dispatcher  The delivery dispatcher.
     * @param  int  $deliveryId  The past attempt to replay.
     *
     * @return void
     */
    public function replay( WebhookDispatcher $dispatcher, int $deliveryId ): void
    {
        $delivery = WebhookDelivery::query()
            ->whereHas( 'webhook' )
            ->whereKey( $deliveryId )
            ->firstOrFail();

        if ( ! in_array( $delivery->status, [ WebhookDeliveryStatus::Failed, WebhookDeliveryStatus::Dead ], true ) ) {
            $this->dispatch( 'bookings-webhook-delivery-replay-refused', deliveryId: $deliveryId );

            return;
        }

        $webhook = Webhook::query()->findOrFail( $delivery->webhook_id );

        if ( $webhook->isDisabled() || ! $webhook->is_active ) {
            $this->dispatch( 'bookings-webhook-delivery-replay-refused', deliveryId: $deliveryId );

            return;
        }

        $replayed = $dispatcher->queue( $webhook, $delivery->event_type, $delivery->payload );

        $this->dispatch(
            'bookings-webhook-delivery-replayed',
            deliveryId: $deliveryId,
            replayId: $replayed->getKey(),
        );
    }

    /**
     * Gets the endpoints an operator can narrow the ledger to.
     *
     * @since 1.0.0
     *
     * @return Collection<int, Webhook> The site's endpoints.
     */
    public function webhookOptions(): Collection
    {
        return Webhook::query()
            ->orderBy( 'name' )
            ->get( [ 'id', 'name' ] );
    }

    /**
     * Gets the page of deliveries the current filters select.
     *
     * @since 1.0.0
     *
     * @return LengthAwarePaginator<int, WebhookDelivery> The paginated deliveries.
     */
    public function deliveries(): LengthAwarePaginator
    {
        return WebhookDelivery::query()
            ->whereHas( 'webhook' )
            ->with( 'webhook:id,name' )
            ->when(
                null !== $this->webhookId,
                fn ( $query ) => $query->where( 'webhook_id', $this->webhookId ),
            )
            ->when(
                '' !== $this->status,
                fn ( $query ) => $query->where( 'status', $this->status ),
            )
            ->latest( 'id' )
            ->paginate( 20 );
    }

    /**
     * Renders the ledger.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'bookings::livewire.admin.webhook-deliveries', [
            'deliveries'     => $this->deliveries(),
            'webhookOptions' => $this->webhookOptions(),
        ] );
    }
}
