<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\NotificationAudience;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Exceptions\InvalidBookingTransitionException;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use ArtisanPackUI\Bookings\Notifications\BookingConfirmation;
use ArtisanPackUI\Bookings\Notifications\BookingNotification;
use ArtisanPackUI\Bookings\Services\BookingService;
use ArtisanPackUI\Bookings\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as Notifier;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.bookings.notifications.channels', [ 'mail' ] );
    config()->set( 'artisanpack.bookings.notifications.database.notifiable', null );
    config()->set( 'artisanpack.bookings.public.manage_url', 'https://example.test/bookings/manage/{token}' );

    removeAllFilters( 'ap.bookings.notification.subject' );
} );

afterEach( function (): void {
    removeAllFilters( 'ap.bookings.notification.subject' );
} );

/**
 * Builds a booking with everything a realistic email would show.
 *
 * @param  array<string, mixed>  $attributes  Overrides for the booking.
 *
 * @return Booking The booking.
 */
function templateBooking( array $attributes = [] ): Booking
{
    $provider = ServiceProvider::factory()->create( [
        'name'     => 'Dr. Aroha Ngata',
        'timezone' => 'Pacific/Auckland',
    ] );

    $service = Service::factory()->create( [ 'name' => 'Initial consultation' ] );

    return Booking::factory()
        ->confirmed()
        ->for( $provider, 'provider' )
        ->for( $service, 'service' )
        ->create( array_merge( [
            'customer_name'     => 'Jamie Rivera',
            'customer_email'    => 'jamie@example.test',
            'customer_phone'    => '+1 555 0142',
            'customer_timezone' => 'America/Chicago',
        ], $attributes ) );
}

/**
 * Renders one lifecycle message for one audience.
 *
 * @param  NotificationType  $type  The lifecycle message.
 * @param  NotificationAudience  $audience  Who it is written for.
 * @param  Booking  $booking  The booking it concerns.
 *
 * @return string The rendered HTML body.
 */
function renderEmail( NotificationType $type, NotificationAudience $audience, Booking $booking ): string
{
    $service = app( NotificationService::class );

    $notification = in_array( $type, [ NotificationType::ProviderAssigned, NotificationType::ProviderUnassigned ], true )
        ? $service->notificationForProvider( $type, $booking )
        : $service->notificationFor( $type, $booking );

    return (string) $notification->for( $audience )->toMail( null )->render();
}

/**
 * Reports the errors a parser finds in a rendered email.
 *
 * `DOMDocument` is the validator here rather than a service over the network:
 * it is what is available offline and in CI, and it catches the failures that
 * actually happen to a template — an unclosed tag, a stray `</td>`, an
 * attribute that swallowed the rest of the element.
 *
 * @param  string  $html  The rendered HTML.
 *
 * @return array<int, string> The parse errors, empty when the markup is sound.
 */
function htmlErrors( string $html ): array
{
    $previous = libxml_use_internal_errors( true );
    libxml_clear_errors();

    $document = new DOMDocument();
    $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );

    $errors = array_map(
        static fn ( LibXMLError $error ): string => trim( $error->message ) . ' (line ' . $error->line . ')',
        libxml_get_errors(),
    );

    libxml_clear_errors();
    libxml_use_internal_errors( $previous );

    return $errors;
}

describe( 'the email templates', function (): void {
    it( 'renders every lifecycle message for every audience', function (
        NotificationType $type,
        NotificationAudience $audience,
    ): void {
        $booking = templateBooking();
        $html    = renderEmail( $type, $audience, $booking );

        expect( $html )->toContain( '<!DOCTYPE html>' )
            ->and( $html )->toContain( (string) $booking->booking_number )
            ->and( $html )->toContain( 'Initial consultation' )
            ->and( $html )->toContain( 'Dr. Aroha Ngata' );
    } )->with( 'lifecycleMessages' );

    it( 'produces markup a parser accepts', function (
        NotificationType $type,
        NotificationAudience $audience,
    ): void {
        $html = renderEmail( $type, $audience, templateBooking() );

        expect( htmlErrors( $html ) )->toBe( [] );
    } )->with( 'lifecycleMessages' );

    it( 'carries no scripts and no inline event handlers', function (
        NotificationType $type,
        NotificationAudience $audience,
    ): void {
        // CSP-safe per plan §9.6. Mail clients strip scripts anyway, so one here
        // would buy nothing and cost a spam score — and the same markup has to
        // pass a Content-Security-Policy on a "view in browser" page.
        $html = renderEmail( $type, $audience, templateBooking() );

        expect( $html )->not->toContain( '<script' )
            ->and( $html )->not->toContain( 'javascript:' )
            ->and( preg_match( '/\son[a-z]+\s*=/i', $html ) )->toBe( 0 );
    } )->with( 'lifecycleMessages' );
} );

describe( 'the manage link', function (): void {
    it( 'puts the customer\'s manage link in their confirmation', function (): void {
        // The token is read back out of the rendered link rather than pulled off
        // the booking first — pulling it is what the notification does, and a
        // test that got there first would leave the message with nothing to
        // render. Checking it against the stored hash proves the link carries
        // the credential that actually manages this booking.
        $booking = templateBooking();

        $html = renderEmail( NotificationType::Confirmation, NotificationAudience::Customer, $booking );

        expect( $html )->toContain( 'Manage your booking' )
            ->and( preg_match(
                '#https://example\.test/bookings/manage/([0-9a-f]{64})#',
                $html,
                $matches,
            ) )->toBe( 1 );

        expect( hash( 'sha256', $matches[ 1 ] ) )->toBe( $booking->manage_token_hash );
    } );

    it( 'keeps the manage link out of the provider copy', function (): void {
        // The token is the customer's whole credential. A copy of it sitting in
        // a provider mailbox is a copy the customer never agreed to.
        $html = renderEmail( NotificationType::ProviderAssigned, NotificationAudience::Provider, templateBooking() );

        expect( $html )->not->toContain( 'https://example.test/bookings/manage/' )
            ->and( $html )->not->toContain( 'Manage your booking' );
    } );

    it( 'renders no link when no manage URL is configured', function (): void {
        // A link to a page the application never mounted is worse than no link.
        config()->set( 'artisanpack.bookings.public.manage_url', null );

        $html = renderEmail( NotificationType::Confirmation, NotificationAudience::Customer, templateBooking() );

        expect( $html )->not->toContain( 'Manage your booking' );
    } );

    it( 'renders no link when the booking no longer holds its plain token', function (): void {
        // A reminder reads the booking back from the database, where the plain
        // token does not exist. Reissuing one to fill the gap would break the
        // link already in the customer's inbox.
        $booking = templateBooking()->fresh();

        $html = renderEmail( NotificationType::Confirmation, NotificationAudience::Customer, $booking );

        expect( $html )->not->toContain( 'Manage your booking' );
    } );

    it( 'keeps the plain token out of anything it serialises', function (): void {
        // A queued notification is written to the jobs table, and to failed_jobs
        // if it dies there. Both outlive the message, and neither is swept when
        // a booking is erased, so the customer's credential must not reach them.
        $notification = new BookingConfirmation( templateBooking() );

        $html = (string) $notification->toMail( null )->render();

        expect( preg_match( '#/manage/([0-9a-f]{64})#', $html, $matches ) )->toBe( 1 );

        expect( serialize( $notification ) )->not->toContain( $matches[ 1 ] );
    } );

    it( 'renders the same link however many times the message is built', function (): void {
        // The plain token is taken from the booking once and then gone. A
        // message rendered twice — a preview beside a send — must not lose it.
        $booking      = templateBooking();
        $notification = new BookingConfirmation( $booking );

        $first  = (string) $notification->toMail( null )->render();
        $second = (string) $notification->toMail( null )->render();

        expect( $first )->toContain( 'Manage your booking' )
            ->and( $second )->toContain( 'Manage your booking' );
    } );
} );

describe( 'the timezone each audience reads', function (): void {
    it( 'shows the customer their own timezone', function (): void {
        $booking = templateBooking();
        $local   = $booking->startTimeForCustomer();

        $html = renderEmail( NotificationType::Confirmation, NotificationAudience::Customer, $booking );

        expect( $html )->toContain( $local->format( 'l, j F Y \a\t H:i' ) )
            ->and( $html )->toContain( '(' . $local->format( 'T' ) . ')' );
    } );

    it( 'shows the provider their own timezone, and names the customer\'s alongside it', function (): void {
        // A provider scanning the morning needs the times they will actually work
        // them; the customer's zone is there so nobody rings Chicago at nine in
        // the morning Auckland time.
        $booking  = templateBooking();
        $provider = $booking->startTimeForProvider();
        $customer = $booking->startTimeForCustomer();

        $html = renderEmail( NotificationType::ProviderAssigned, NotificationAudience::Provider, $booking );

        expect( $html )->toContain( $provider->format( 'l, j F Y \a\t H:i' ) )
            ->and( $html )->toContain( $customer->format( 'l, j F Y \a\t H:i' ) )
            ->and( $html )->toContain( 'jamie@example.test' );
    } );

    it( 'falls back to the application timezone when nobody is assigned', function (): void {
        config()->set( 'app.timezone', 'Europe/London' );

        $booking = templateBooking( [ 'provider_id' => null ] );

        expect( $booking->startTimeForProvider()->getTimezone()->getName() )->toBe( 'Europe/London' );
    } );
} );

describe( 'the subject', function (): void {
    it( 'renders the subject a filter returned rather than the default', function (): void {
        addFilter(
            'ap.bookings.notification.subject',
            static fn ( string $subject ): string => 'Filtered: ' . $subject,
        );

        $notification = new BookingConfirmation( templateBooking() );
        $message      = $notification->toMail( null );

        expect( $message->subject )->toStartWith( 'Filtered: ' )
            ->and( (string) $message->render() )->toContain( 'Filtered: ' );
    } );

    it( 'asks the filter once per message, so header and heading agree', function (): void {
        // A subscriber that appends rather than replaces — a site name, a ticket
        // reference — would otherwise put a different string in the header from
        // the one printed at the top of the body.
        $calls = 0;

        addFilter(
            'ap.bookings.notification.subject',
            static function ( string $subject ) use ( &$calls ): string {
                $calls++;

                return $subject . ' #' . $calls;
            },
        );

        $message = ( new BookingConfirmation( templateBooking() ) )->toMail( null );

        expect( $calls )->toBe( 1 )
            ->and( $message->subject )->toEndWith( ' #1' )
            ->and( (string) $message->render() )->toContain( $message->subject );
    } );

    it( 'hands the filter the notification and the booking', function (): void {
        $seen = null;

        addFilter(
            'ap.bookings.notification.subject',
            static function ( string $subject, BookingNotification $notification, Booking $booking ) use ( &$seen ): string {
                $seen = [ $notification::class, $booking->getKey(), $notification->audience() ];

                return $subject;
            },
        );

        $booking = templateBooking();

        ( new BookingConfirmation( $booking ) )->for( NotificationAudience::Provider )->subject();

        expect( $seen )->toBe( [ BookingConfirmation::class, $booking->getKey(), NotificationAudience::Provider ] );
    } );

    it( 'words the staff subject for staff', function (): void {
        // "Your booking is confirmed" is a sentence about the reader's own
        // appointment, which is not what a staff reader is being told.
        $booking = templateBooking();

        $customer = ( new BookingConfirmation( $booking ) )->subject();
        $staff    = ( new BookingConfirmation( $booking ) )->for( NotificationAudience::Provider )->subject();

        expect( $customer )->toContain( 'confirmed' )
            ->and( $staff )->toContain( 'Jamie Rivera' )
            ->and( $staff )->not->toBe( $customer );
    } );

    it( 'leaves the audience of the copy it was asked for alone', function (): void {
        // for() returns a copy; the original stays addressed to the customer, or
        // whichever channel renders next sends staff wording to the customer.
        $notification = new BookingConfirmation( templateBooking() );

        $notification->for( NotificationAudience::Provider );

        expect( $notification->audience() )->toBe( NotificationAudience::Customer );
    } );
} );

describe( 'sending the confirmation', function (): void {
    it( 'writes a notification log row naming the address it went to', function (): void {
        Notifier::fake();

        $booking = Booking::factory()->requested()->create( [ 'customer_email' => 'jamie@example.test' ] );

        $sent = app( NotificationService::class )->send( NotificationType::Confirmation, $booking );

        expect( $sent )->toHaveCount( 1 );

        Notifier::assertSentOnDemandTimes( BookingConfirmation::class, 1 );

        $log = NotificationLog::query()
            ->where( 'booking_id', $booking->getKey() )
            ->where( 'type', NotificationType::Confirmation->value )
            ->where( 'channel', 'mail' )
            ->sole();

        expect( $log->recipient )->toBe( 'jamie@example.test' )
            ->and( $log->status->value )->toBe( 'sent' );
    } );

    it( 'does not confirm the same booking twice, so does not send twice', function (): void {
        // A lifecycle claim carries a null scheduled_for, and NULLs are distinct
        // in a unique index — so the log is not what stops a second confirmation
        // here. The state machine is: the second transition is refused before
        // any notification is reached.
        Notifier::fake();

        $booking = Booking::factory()->requested()->create( [ 'customer_email' => 'jamie@example.test' ] );

        app( BookingService::class )->confirm( $booking );

        expect( fn () => app( BookingService::class )->confirm( $booking ) )
            ->toThrow( InvalidBookingTransitionException::class );

        Notifier::assertSentOnDemandTimes( BookingConfirmation::class, 1 );

        expect( NotificationLog::query()
            ->where( 'booking_id', $booking->getKey() )
            ->where( 'type', NotificationType::Confirmation->value )
            ->count() )->toBe( 1 );
    } );

    it( 'refuses a second claim on a scheduled send', function (): void {
        // The reminder path is the one the log itself guards: a scheduled_for is
        // a real value, so the unique index sees the duplicate and the second
        // worker sends nothing.
        Notifier::fake();

        $booking = Booking::factory()->confirmed()->create( [ 'customer_email' => 'jamie@example.test' ] );
        $due     = $booking->start_time->copy()->subDay();

        $first  = app( NotificationService::class )->send( NotificationType::Reminder, $booking, $due );
        $second = app( NotificationService::class )->send( NotificationType::Reminder, $booking, $due );

        expect( $first )->toHaveCount( 1 )
            ->and( $second )->toBe( [] );

        expect( NotificationLog::query()
            ->where( 'booking_id', $booking->getKey() )
            ->where( 'type', NotificationType::Reminder->value )
            ->count() )->toBe( 1 );
    } );
} );

dataset( 'lifecycleMessages', static function (): iterable {
    // Customer lifecycle messages are written for the customer; the two
    // provider-facing messages are written only for the provider. Staff who are
    // not the provider are notified through the database channel, not by email,
    // so there is no staff email audience. Pairing a customer type with the
    // provider audience — or a provider type with the customer audience — has no
    // template and no meaning, so the matrix is built from the audiences each
    // type actually renders for.
    foreach ( [
        NotificationType::Confirmation,
        NotificationType::Reminder,
        NotificationType::Cancellation,
        NotificationType::Reschedule,
        NotificationType::NoShow,
    ] as $type ) {
        yield $type->value . ' / ' . NotificationAudience::Customer->value => [ $type, NotificationAudience::Customer ];
    }

    foreach ( [ NotificationType::ProviderAssigned, NotificationType::ProviderUnassigned ] as $type ) {
        yield $type->value . ' / ' . NotificationAudience::Provider->value => [ $type, NotificationAudience::Provider ];
    }
} );
