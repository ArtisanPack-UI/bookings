{{--
    The general configuration surface.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@extends( $bookingsAdminLayout )

@section( 'title', __( 'Settings' ) )

@section( 'content' )
	<livewire:artisanpack-bookings-admin-settings />
@endsection
