{{--
    A service's intake-schema editor, with its version history.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@extends( $bookingsAdminLayout )

@section( 'title', __( 'Intake Schema' ) )

@section( 'content' )
	<livewire:artisanpack-bookings-admin-intake-schema-editor
		:service-id="$serviceId"
		:key="'intake-schema-editor-' . $serviceId"
	/>
@endsection
