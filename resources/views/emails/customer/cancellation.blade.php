{{--
    The customer's cancellation notice. The details describe the appointment
    that was called off, which is why they are worded in the past here.

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
        {{ __( 'Nothing further is needed from you. Book again whenever you are ready.' ) }}
    </p>
@endsection
