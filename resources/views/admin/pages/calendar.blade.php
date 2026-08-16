{{--
    The month calendar of bookings.

    Selecting a booking dispatches `bookings-view-booking`; the hand-off opens
    that booking's own detail route.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@extends( $bookingsAdminLayout )

@section( 'title', __( 'Calendar' ) )

@section( 'content' )
	<livewire:artisanpack-bookings-admin-booking-calendar />

	@push( 'scripts' )
		@include( 'bookings::admin.partials.handoff', [ 'handoff' => [
			[
				'on'    => 'bookings-view-booking',
				'visit' => \ArtisanPackUI\Bookings\Support\AdminNav::url( 'artisanpack.bookings.admin.bookings' ) . '?booking=__ID__',
				'from'  => 'bookingId',
			],
		] ] )
	@endpush
@endsection
