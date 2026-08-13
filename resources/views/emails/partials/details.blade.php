{{--
    The appointment, as a labelled table.

    The rows come from the notification rather than from the booking, so the
    times are already in the zone this audience reads them in and a published
    copy of this file cannot quietly start showing the server's.

    Two cells per row rather than a definition list: Outlook's Word renderer
    collapses <dl> margins unpredictably, and a label sitting on the same line as
    its value is what a person scanning for "when" is looking for.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0

    @var array<string, string> $details
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-collapse: collapse; width: 100%;">
    @foreach ( $details as $label => $value )
        <tr>
            <th align="left" valign="top" scope="row" class="ap-bookings-muted"
                style="padding: 8px 16px 8px 0; font-size: 13px; line-height: 1.5; font-weight: 600; color: #71717a; white-space: nowrap;">
                {{ $label }}
            </th>
            <td align="left" valign="top" class="ap-bookings-text"
                style="padding: 8px 0; font-size: 15px; line-height: 1.5; color: #18181b;">
                {{ $value }}
            </td>
        </tr>
    @endforeach
</table>
