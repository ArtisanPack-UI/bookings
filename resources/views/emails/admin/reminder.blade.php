{{--
    The staff copy of an upcoming-appointment reminder.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0

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
