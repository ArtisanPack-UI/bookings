{{--
    The customer's confirmation. The only message that carries the manage link:
    the plain token exists once, on the booking that minted it, and this is the
    message sent while it still does.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0

    @var \ArtisanPackUI\Bookings\Models\Booking $booking
    @var array<string, string> $details
    @var string|null $manageUrl
--}}
@extends( 'bookings::emails.layout' )

@section( 'body' )
    @include( 'bookings::emails.partials.details' )

    @if ( null !== $manageUrl )
        @include( 'bookings::emails.partials.manage-link' )
    @endif
@endsection
