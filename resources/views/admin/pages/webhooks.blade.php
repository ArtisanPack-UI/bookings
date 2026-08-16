{{--
    Webhook endpoints, with a chosen endpoint's delivery log beside them.

    The list dispatches `bookings-view-webhook-deliveries`; the hand-off reloads
    with `?webhook=…` so the delivery log mounts scoped to that endpoint.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@extends( $bookingsAdminLayout )

@section( 'title', __( 'Webhooks' ) )

@section( 'content' )
	<livewire:artisanpack-bookings-admin-webhooks />

	<div class="mt-8">
		<livewire:artisanpack-bookings-admin-webhook-deliveries
			:webhook-id="$webhookId"
			:key="'webhook-deliveries-' . ( $webhookId ?? 'all' )"
		/>
	</div>

	@push( 'scripts' )
		@include( 'bookings::admin.partials.handoff', [ 'handoff' => [
			[ 'on' => 'bookings-view-webhook-deliveries', 'set' => 'webhook', 'from' => 'webhookId' ],
		] ] )
	@endpush
@endsection
