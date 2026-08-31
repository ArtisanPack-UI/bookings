{{--
    The staff copy when a booking is marked a no-show. The details carry the
    customer's contact so a member of staff can follow up, and there is no
    manage link.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.1.0

    @var \ArtisanPackUI\Bookings\Models\Booking $booking
    @var array<string, string> $details
--}}
@extends( 'bookings::emails.layout' )

@section( 'body' )
    @include( 'bookings::emails.partials.details' )
    <p class="ap-bookings-muted" style="margin: 24px 0 0; font-size: 13px; line-height: 1.6; color: #71717a;">
        {{ __( 'Times are shown in the provider\'s timezone.' ) }}
    </p>
@endsection
