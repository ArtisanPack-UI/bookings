{{--
    Service-level closures. Self-contained CRUD over an inline form.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@extends( $bookingsAdminLayout )

@section( 'title', __( 'Blackout Dates' ) )

@section( 'content' )
	<livewire:artisanpack-bookings-admin-blackout-dates />
@endsection
