{{--
    The customer's reschedule notice. The time in the table is the new one —
    the booking has already moved by the time this is sent.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0

    @var \ArtisanPackUI\Bookings\Models\Booking $booking
    @var array<string, string> $details
--}}
@extends( 'bookings::emails.layout' )

@section( 'body' )
    @include( 'bookings::emails.partials.details' )

    <p class="ap-bookings-text" style="margin: 24px 0 0; font-size: 15px; line-height: 1.6; color: #3f3f46;">
        {{ __( 'Please check the new time works for you and get in touch if it does not.' ) }}
    </p>
@endsection
