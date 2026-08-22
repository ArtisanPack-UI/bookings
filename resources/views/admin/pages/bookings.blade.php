{{--
    The bookings list, and the detail of a chosen row beside it.

    The list dispatches `bookings-view-booking`; the hand-off catches it and
    reloads with `?booking=…` so the detail mounts on the chosen row — the same
    intent a richer host shell might answer with a drawer.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@extends( $bookingsAdminLayout )

@section( 'title', __( 'Bookings' ) )

@section( 'content' )
	<livewire:artisanpack-bookings-admin-bookings />

	@isset( $booking )
		<div class="mt-8">
			<livewire:artisanpack-bookings-admin-booking-detail
				:booking="$booking"
				:key="'booking-detail-' . $booking->getKey()"
			/>
		</div>
	@endisset

	@push( 'scripts' )
		@include( 'bookings::admin.partials.handoff', [ 'handoff' => [
			[ 'on' => 'bookings-view-booking', 'set' => 'booking', 'from' => 'bookingId' ],
			[ 'on' => 'bookings-booking-updated', 'set' => 'booking', 'from' => 'bookingId' ],
		] ] )
	@endpush
@endsection
