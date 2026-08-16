{{--
    Admin-authored booking — booking on behalf of a customer (plan §6.5).

    A successful save dispatches `bookings-booking-created`; the hand-off sends
    the operator to that booking's detail. Cancelling returns to the list.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@extends( $bookingsAdminLayout )

@section( 'title', __( 'New Booking' ) )

@section( 'content' )
	<livewire:artisanpack-bookings-admin-booking-create />

	@push( 'scripts' )
		@include( 'bookings::admin.partials.handoff', [ 'handoff' => [
			[
				'on'    => 'bookings-booking-created',
				'visit' => \ArtisanPackUI\Bookings\Support\AdminNav::url( 'artisanpack.bookings.admin.bookings' ) . '?booking=__ID__',
				'from'  => 'bookingId',
			],
			[
				'on'    => 'bookings-booking-create-cancelled',
				'visit' => \ArtisanPackUI\Bookings\Support\AdminNav::url( 'artisanpack.bookings.admin.bookings' ),
			],
		] ] )
	@endpush
@endsection
