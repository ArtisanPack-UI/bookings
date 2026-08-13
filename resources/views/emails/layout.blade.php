{{--
    The shell every booking email is drawn in.

    Table-based and inline-styled because that is what mail clients render.
    Outlook on Windows lays out through Word, which has no flexbox and no grid;
    Gmail strips a <style> block on forwarded messages and on some mobile
    clients; and a message that arrives as an unstyled column of text is still
    readable, which is why nothing here depends on the stylesheet to be legible.

    There is no <script> anywhere in these templates and no on* attribute, per
    plan §9.6. Mail clients strip scripts anyway, so one here buys nothing and
    costs a spam score — and the same markup is what a Content-Security-Policy
    on a "view in browser" page has to accept.

    The one <style> block carries the dark-scheme and small-screen hints, both of
    which are progressive: drop the block and the message renders exactly as it
    does on a client that ignores it.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0

    @var \ArtisanPackUI\Bookings\Enums\NotificationAudience $audience
    @var \ArtisanPackUI\Bookings\Models\Booking $booking
    @var array<string, string> $details
    @var string $greeting
    @var string $heading
    @var string|null $manageUrl
    @var string $openingLine
    @var string $signature
--}}
<!DOCTYPE html>
<html lang="{{ str_replace( '_', '-', app()->getLocale() ) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $heading }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .ap-bookings-wrap {
                width: 100% !important;
            }

            .ap-bookings-pad {
                padding: 24px !important;
            }
        }

        @media (prefers-color-scheme: dark) {
            .ap-bookings-body {
                background-color: #18181b !important;
            }

            .ap-bookings-card {
                background-color: #27272a !important;
            }

            .ap-bookings-text {
                color: #e4e4e7 !important;
            }

            .ap-bookings-muted {
                color: #a1a1aa !important;
            }

            .ap-bookings-rule {
                border-color: #3f3f46 !important;
            }
        }
    </style>
</head>
<body class="ap-bookings-body" style="margin: 0; padding: 0; width: 100%; background-color: #f4f4f5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-collapse: collapse; background-color: #f4f4f5;">
    <tr>
        <td align="center" style="padding: 32px 12px;">
            <table role="presentation" class="ap-bookings-wrap" width="600" cellpadding="0" cellspacing="0" border="0"
                   style="border-collapse: collapse; width: 600px; max-width: 100%;">
                <tr>
                    <td class="ap-bookings-card ap-bookings-pad"
                        style="padding: 32px; background-color: #ffffff; border-radius: 8px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                        <h1 class="ap-bookings-text"
                            style="margin: 0 0 16px; font-size: 20px; line-height: 1.3; font-weight: 600; color: #18181b;">
                            {{ $heading }}
                        </h1>

                        <p class="ap-bookings-text" style="margin: 0 0 12px; font-size: 15px; line-height: 1.6; color: #3f3f46;">
                            {{ $greeting }}
                        </p>

                        <p class="ap-bookings-text" style="margin: 0 0 24px; font-size: 15px; line-height: 1.6; color: #3f3f46;">
                            {{ $openingLine }}
                        </p>

                        @yield( 'body' )

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                               style="border-collapse: collapse; margin: 24px 0 0;">
                            <tr>
                                <td class="ap-bookings-rule" style="border-top: 1px solid #e4e4e7; font-size: 0; line-height: 0;">&nbsp;</td>
                            </tr>
                        </table>

                        <p class="ap-bookings-muted" style="margin: 24px 0 0; font-size: 13px; line-height: 1.6; color: #71717a;">
                            {{ $signature }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
