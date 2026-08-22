{{--
    The services list, with the editor beside it.

    The list dispatches `bookings-edit-service` (with a null id for a new one);
    the hand-off reloads with `?service=…` so the editor mounts on the row. A
    save remounts the editor on the saved id; cancelling clears the query string.
    A service's intake schema is edited on its own `services/{service}/intake-schema`
    route rather than here.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@extends( $bookingsAdminLayout )

@php
    $editServiceId   = is_numeric( $serviceId ) ? (int) $serviceId : null;
    $showServiceForm = null !== $serviceId;
@endphp

@section( 'title', __( 'Services' ) )

@section( 'content' )
	<livewire:artisanpack-bookings-admin-services />

	@if ( $showServiceForm )
		<div class="mt-8">
			<livewire:artisanpack-bookings-admin-service-editor
				:service-id="$editServiceId"
				:key="'service-editor-' . ( $editServiceId ?? 'new' )"
			/>
		</div>
	@endif

	@push( 'scripts' )
		@include( 'bookings::admin.partials.handoff', [ 'handoff' => [
			[ 'on' => 'bookings-edit-service', 'set' => 'service', 'from' => 'serviceId', 'default' => 'new' ],
			[ 'on' => 'bookings-service-saved', 'set' => 'service', 'from' => 'serviceId' ],
			[ 'on' => 'bookings-service-editor-cancelled', 'remove' => 'service' ],
		] ] )
	@endpush
@endsection
