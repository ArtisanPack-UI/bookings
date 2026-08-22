{{--
    A single booking's detail, cancel, reschedule, and no-show controls.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@extends( $bookingsAdminLayout )

@section( 'title', __( 'Booking' ) )

@section( 'content' )
	<livewire:artisanpack-bookings-admin-booking-detail
		:booking="$booking"
		:key="'booking-detail-' . $booking->getKey()"
	/>
@endsection
