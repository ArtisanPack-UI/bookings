{{--
    The providers list, with the editor and per-provider schedule editor beside
    it.

    The list dispatches `bookings-edit-provider` (null id for a new one) and
    `bookings-edit-provider-availability`; the hand-off reloads with `?provider=…`
    or `?availability=…` so the right editor mounts on the row.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@extends( $bookingsAdminLayout )

@php
    $editProviderId   = is_numeric( $providerId ) ? (int) $providerId : null;
    $showProviderForm = null !== $providerId;
@endphp

@section( 'title', __( 'Providers' ) )

@section( 'content' )
	<livewire:artisanpack-bookings-admin-providers />

	@if ( $showProviderForm )
		<div class="mt-8">
			<livewire:artisanpack-bookings-admin-provider-editor
				:provider-id="$editProviderId"
				:key="'provider-editor-' . ( $editProviderId ?? 'new' )"
			/>
		</div>
	@endif

	@isset( $availability )
		<div class="mt-8">
			<livewire:artisanpack-bookings-admin-availability-editor
				:provider-id="$availability"
				:key="'availability-editor-' . $availability"
			/>
		</div>
	@endisset

	@push( 'scripts' )
		@include( 'bookings::admin.partials.handoff', [ 'handoff' => [
			[ 'on' => 'bookings-edit-provider', 'set' => 'provider', 'from' => 'providerId', 'default' => 'new' ],
			[ 'on' => 'bookings-provider-saved', 'set' => 'provider', 'from' => 'providerId' ],
			[ 'on' => 'bookings-provider-editor-cancelled', 'remove' => 'provider' ],
			[ 'on' => 'bookings-edit-provider-availability', 'set' => 'availability', 'from' => 'providerId' ],
			[ 'on' => 'bookings-provider-availability-saved', 'remove' => 'availability' ],
			[ 'on' => 'bookings-provider-availability-editor-cancelled', 'remove' => 'availability' ],
		] ] )
	@endpush
@endsection
