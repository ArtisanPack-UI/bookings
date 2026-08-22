{{--
    The recurring-series list, with its editor beside it.

    The list dispatches `bookings-edit-series`; the hand-off reloads with
    `?series=…` so the editor mounts on the row. A save remounts the editor on
    the saved id; closing the editor clears the query string.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@extends( $bookingsAdminLayout )

@php( $editSeriesId = is_numeric( $seriesId ) ? (int) $seriesId : null )

@section( 'title', __( 'Series' ) )

@section( 'content' )
	<livewire:artisanpack-bookings-admin-series />

	@isset( $editSeriesId )
		<div class="mt-8">
			<livewire:artisanpack-bookings-admin-series-editor
				:series-id="$editSeriesId"
				:key="'series-editor-' . $editSeriesId"
			/>
		</div>
	@endisset

	@push( 'scripts' )
		@include( 'bookings::admin.partials.handoff', [ 'handoff' => [
			[ 'on' => 'bookings-edit-series', 'set' => 'series', 'from' => 'seriesId' ],
			[ 'on' => 'bookings-series-saved', 'set' => 'series', 'from' => 'seriesId' ],
			[ 'on' => 'bookings-series-editor-cancelled', 'remove' => 'series' ],
		] ] )
	@endpush
@endsection
