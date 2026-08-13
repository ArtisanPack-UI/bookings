{{--
    The customer's self-serve link.

    A bulletproof button — a table cell with the background on it and the anchor
    filling it — rather than a styled <a>, because Outlook ignores padding on an
    inline element and would render the "button" as a bare word of blue text.

    The URL is printed underneath as well. The link is the customer's only way
    back to their appointment, and a mail client that strips the anchor, a
    forward that flattens the HTML, or a person reading the plain-text part all
    leave them with nothing to type in otherwise.

    Rendered only when there is a link to render — see
    BookingNotification::manageUrl() for when there is not.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0

    @var string $manageUrl
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; margin: 24px 0 0;">
    <tr>
        <td align="center" bgcolor="#4338ca" style="border-radius: 6px; background-color: #4338ca;">
            <a href="{{ $manageUrl }}"
               style="display: inline-block; padding: 12px 24px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; font-weight: 600; line-height: 1; color: #ffffff; text-decoration: none;">
                {{ __( 'Manage your booking' ) }}
            </a>
        </td>
    </tr>
</table>

<p class="ap-bookings-muted" style="margin: 12px 0 0; font-size: 13px; line-height: 1.6; color: #71717a; word-break: break-all;">
    {{ __( 'Or paste this into your browser:' ) }}
    <br>
    {{ $manageUrl }}
</p>

<p class="ap-bookings-muted" style="margin: 12px 0 0; font-size: 13px; line-height: 1.6; color: #71717a;">
    {{ __( 'Keep this link to yourself — anyone who has it can change or cancel your appointment.' ) }}
</p>
