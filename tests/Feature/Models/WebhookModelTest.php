<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\NotificationStatus;
use ArtisanPackUI\Bookings\Enums\NotificationType;
use ArtisanPackUI\Bookings\Enums\WebhookDeliveryStatus;
use ArtisanPackUI\Bookings\Events\WebhookDisabled;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Models\Webhook;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'the webhook factory', function (): void {
    it( 'produces a valid endpoint', function (): void {
        $webhook = Webhook::factory()->create();

        expect( $webhook->exists )->toBeTrue()
            ->and( $webhook->is_active )->toBeTrue()
            ->and( $webhook->isDisabled() )->toBeFalse()
            ->and( $webhook->url )->toStartWith( 'https://' );
    } );

    it( 'round-trips its event list through JSON', function (): void {
        $events = [ 'booking.created', 'booking.rescheduled', 'booking.no_show' ];

        $webhook = Webhook::factory()->subscribedTo( $events )->create();

        expect( $webhook->fresh()->events )->toBe( $events )
            ->and( $webhook->subscribesTo( 'booking.rescheduled' ) )->toBeTrue()
            ->and( $webhook->subscribesTo( 'booking.cancelled' ) )->toBeFalse();
    } );

    it( 'keeps the signing secret out of serialised output', function (): void {
        // Knowing the secret is what lets somebody forge a payload the endpoint
        // will trust.
        $webhook = Webhook::factory()->create();

        expect( $webhook->toArray() )->not->toHaveKey( 'secret' )
            ->and( $webhook->secret )->not->toBeEmpty();
    } );
} );

describe( 'disabling a webhook', function (): void {
    it( 'stamps the row and announces it', function (): void {
        Event::fake( [ WebhookDisabled::class ] );

        $webhook = Webhook::factory()->failing()->create();

        expect( $webhook->disable( 'Ten consecutive failures.' ) )->toBeTrue();

        $webhook->refresh();

        expect( $webhook->disabled_at )->not->toBeNull()
            ->and( $webhook->is_active )->toBeFalse()
            ->and( $webhook->isDisabled() )->toBeTrue();

        Event::assertDispatched(
            WebhookDisabled::class,
            fn ( WebhookDisabled $event ): bool => $event->webhook->is( $webhook )
                && 'Ten consecutive failures.' === $event->reason,
        );
    } );

    it( 'says nothing the second time', function (): void {
        Event::fake( [ WebhookDisabled::class ] );

        $webhook = Webhook::factory()->create();
        $webhook->disable( 'First.' );

        expect( $webhook->disable( 'Second.' ) )->toBeFalse();

        Event::assertDispatchedTimes( WebhookDisabled::class, 1 );
    } );

    it( 'scopes disabled endpoints out of delivery', function (): void {
        Webhook::factory()->count( 2 )->create();
        Webhook::factory()->disabled()->create();

        expect( Webhook::active()->count() )->toBe( 2 );
    } );

    it( 'announces once even when two workers hold the same row', function (): void {
        // The case a read-then-write guard cannot survive: both instances were
        // loaded while the endpoint was still active, so both pass any check made
        // against their own cached attributes. Only the conditional update can
        // separate them, and the consumer hears about the outage once.
        Event::fake( [ WebhookDisabled::class ] );

        $webhook = Webhook::factory()->create();

        $workerOne = Webhook::query()->findOrFail( $webhook->id );
        $workerTwo = Webhook::query()->findOrFail( $webhook->id );

        expect( $workerOne->isDisabled() )->toBeFalse()
            ->and( $workerTwo->isDisabled() )->toBeFalse();

        $first  = $workerOne->disable( 'Ten consecutive failures.' );
        $second = $workerTwo->disable( 'Ten consecutive failures.' );

        expect( [ $first, $second ] )->toBe( [ true, false ] );

        Event::assertDispatchedTimes( WebhookDisabled::class, 1 );
    } );
} );

describe( 'webhook deliveries', function (): void {
    it( 'builds every status', function (): void {
        expect( WebhookDelivery::factory()->pending()->create()->status )->toBe( WebhookDeliveryStatus::Pending )
            ->and( WebhookDelivery::factory()->success()->create()->status )->toBe( WebhookDeliveryStatus::Success )
            ->and( WebhookDelivery::factory()->failed()->create()->status )->toBe( WebhookDeliveryStatus::Failed )
            ->and( WebhookDelivery::factory()->dead()->create()->status )->toBe( WebhookDeliveryStatus::Dead );
    } );

    it( 'knows which statuses will be attempted again', function (): void {
        expect( WebhookDeliveryStatus::Pending->isRetryable() )->toBeTrue()
            ->and( WebhookDeliveryStatus::Failed->isRetryable() )->toBeTrue()
            ->and( WebhookDeliveryStatus::Success->isRetryable() )->toBeFalse()
            ->and( WebhookDeliveryStatus::Dead->isRetryable() )->toBeFalse();
    } );

    it( 'round-trips the payload through JSON', function (): void {
        $payload = [ 'event' => 'booking.created', 'data' => [ 'id' => 7, 'tags' => [ 'vip' ] ] ];

        $delivery = WebhookDelivery::factory()->create( [ 'payload' => $payload ] );

        expect( $delivery->fresh()->payload )->toBe( $payload );
    } );

    it( 'picks up a brand new delivery as due', function (): void {
        // A queued delivery has no next_attempt_at yet. A sweep that only
        // matched a scheduled time would leave it sitting there for good.
        $pending = WebhookDelivery::factory()->pending()->create();

        expect( WebhookDelivery::due()->get()->modelKeys() )->toBe( [ $pending->id ] );
    } );

    it( 'picks up a failed delivery once its backoff has elapsed', function (): void {
        $ready   = WebhookDelivery::factory()->due()->create();
        $waiting = WebhookDelivery::factory()->failed( 30 )->create();

        expect( WebhookDelivery::due()->get()->modelKeys() )->toBe( [ $ready->id ] )
            ->and( WebhookDelivery::due( now()->addHour() )->get()->modelKeys() )
            ->toBe( [ $ready->id, $waiting->id ] );
    } );

    it( 'leaves succeeded and dead deliveries alone', function (): void {
        WebhookDelivery::factory()->success()->create();
        WebhookDelivery::factory()->dead()->create();

        expect( WebhookDelivery::due( now()->addYear() )->count() )->toBe( 0 );
    } );

    it( 'waits for a backoff rather than retrying a failure on every pass', function (): void {
        // A failed delivery with no next_attempt_at is a row whose backoff was
        // never written. Counting it as due would have the sweep pick it up on
        // every single pass with no delay between attempts — a retry storm aimed
        // at an endpoint that is already failing.
        $stranded = WebhookDelivery::factory()->create( [
            'status'          => WebhookDeliveryStatus::Failed,
            'next_attempt_at' => null,
        ] );

        expect( WebhookDelivery::due()->get()->modelKeys() )->not->toContain( $stranded->id )
            ->and( WebhookDelivery::due( now()->addYear() )->get()->modelKeys() )
            ->not->toContain( $stranded->id );
    } );

    it( 'keeps the status reachable as the leading index column', function (): void {
        // The sweep asks one question — what is due now — and the index is
        // ordered the way the question is asked. Written with the status inside
        // both branches of the OR instead, the planner has no leading-column
        // condition to scan and falls back to reading the table.
        $sql = WebhookDelivery::due()->toBase()->toSql();

        expect( $sql )->toContain( 'where "booking_webhook_deliveries"."status" in (?, ?) and (' );
    } );

    it( 'reaches its endpoint', function (): void {
        $webhook = Webhook::factory()->create();
        WebhookDelivery::factory()->count( 3 )->for( $webhook )->create();

        expect( $webhook->deliveries()->count() )->toBe( 3 )
            ->and( $webhook->deliveries()->first()->webhook->is( $webhook ) )->toBeTrue();
    } );
} );

describe( 'the notification log', function (): void {
    it( 'claims the right to send a scheduled reminder', function (): void {
        $booking = Booking::factory()->create();

        $claimed = NotificationLog::logSend(
            $booking,
            NotificationType::Reminder,
            'mail',
            'sam@example.test',
            '2026-05-01 15:00:00',
        );

        expect( $claimed )->toBeInstanceOf( NotificationLog::class )
            ->and( $claimed->status )->toBe( NotificationStatus::Pending )
            ->and( $claimed->type )->toBe( NotificationType::Reminder )
            ->and( $claimed->booking->is( $booking ) )->toBeTrue();
    } );

    it( 'treats a duplicate claim as a no-op', function (): void {
        // This is what makes the reminder cron idempotent. A cron that overlaps
        // itself, a queue that delivers twice, a retry after a timeout — all of
        // them race to insert the same key, exactly one wins, and the losers do
        // nothing rather than emailing the customer twice.
        $booking = Booking::factory()->create();

        $first  = NotificationLog::logSend( $booking, NotificationType::Reminder, 'mail', 'sam@example.test', '2026-05-01 15:00:00' );
        $second = NotificationLog::logSend( $booking, NotificationType::Reminder, 'mail', 'sam@example.test', '2026-05-01 15:00:00' );

        expect( $first )->not->toBeNull()
            ->and( $second )->toBeNull()
            ->and( NotificationLog::query()->count() )->toBe( 1 );
    } );

    it( 'leaves the connection usable after a losing claim', function (): void {
        // Postgres aborts an entire transaction on any failed statement, so the
        // insert runs inside a savepoint. Without it a caught duplicate would
        // take every query after it down too.
        $booking = Booking::factory()->create();

        NotificationLog::logSend( $booking, NotificationType::Reminder, 'mail', 'sam@example.test', '2026-05-01 15:00:00' );
        NotificationLog::logSend( $booking, NotificationType::Reminder, 'mail', 'sam@example.test', '2026-05-01 15:00:00' );

        expect( NotificationLog::query()->count() )->toBe( 1 )
            ->and( Booking::query()->count() )->toBe( 1 );
    } );

    it( 'keys the claim on the instant, not on how the caller spelled it', function (): void {
        // Eloquent formats a date-time in whatever zone the Carbon carries,
        // without converting. Two callers naming the same moment in different
        // zones — one from startTimeForCustomer(), one from the stored UTC
        // start_time — would otherwise write two different strings, collide with
        // nothing, and send the customer the same reminder twice.
        $booking = Booking::factory()->create();

        $chicago = Carbon::parse( '2026-05-01 09:00:00', 'America/Chicago' );
        $utc     = $chicago->copy()->utc();

        expect( $utc->format( 'H:i' ) )->not->toBe( $chicago->format( 'H:i' ) );

        $first  = NotificationLog::logSend( $booking, NotificationType::Reminder, 'mail', 'sam@example.test', $chicago );
        $second = NotificationLog::logSend( $booking, NotificationType::Reminder, 'mail', 'sam@example.test', $utc );

        expect( $first )->not->toBeNull()
            ->and( $second )->toBeNull()
            ->and( NotificationLog::query()->count() )->toBe( 1 );
    } );

    it( 'claims per booking, type, and schedule rather than per channel', function (): void {
        // The unique key the schema actually has. Sending one reminder twice is
        // the failure this prevents, so losing a second channel is the safe side
        // to err on — but it is a real limit, and this pins it so that a change
        // of mind about multi-channel sends has to be deliberate.
        $booking = Booking::factory()->create();

        $mail = NotificationLog::logSend( $booking, NotificationType::Reminder, 'mail', 'sam@example.test', '2026-05-01 15:00:00' );
        $sms  = NotificationLog::logSend( $booking, NotificationType::Reminder, 'sms', '+15555550123', '2026-05-01 15:00:00' );

        expect( $mail )->not->toBeNull()
            ->and( $sms )->toBeNull();
    } );

    it( 'still logs unscheduled sends more than once', function (): void {
        // Two reschedules genuinely warrant two emails, which falls out of NULLs
        // being distinct in a unique index on every engine the package supports.
        $booking = Booking::factory()->create();

        $first  = NotificationLog::logSend( $booking, NotificationType::Reschedule, 'mail', 'sam@example.test' );
        $second = NotificationLog::logSend( $booking, NotificationType::Reschedule, 'mail', 'sam@example.test' );

        expect( $first )->not->toBeNull()
            ->and( $second )->not->toBeNull()
            ->and( NotificationLog::query()->count() )->toBe( 2 );
    } );

    it( 'separates the same schedule on two bookings', function (): void {
        $first  = Booking::factory()->create();
        $second = Booking::factory()->create();

        NotificationLog::logSend( $first, NotificationType::Reminder, 'mail', 'sam@example.test', '2026-05-01 15:00:00' );
        NotificationLog::logSend( $second, NotificationType::Reminder, 'mail', 'dana@example.test', '2026-05-01 15:00:00' );

        expect( NotificationLog::query()->count() )->toBe( 2 );
    } );

    it( 'accepts a plain string type', function (): void {
        $log = NotificationLog::logSend( Booking::factory()->create(), 'confirmation', 'mail', 'sam@example.test' );

        expect( $log?->type )->toBe( NotificationType::Confirmation );
    } );

    it( 'records the outcome of a send', function (): void {
        $log = NotificationLog::factory()->create();

        expect( $log->markSent() )->toBeTrue()
            ->and( $log->fresh()->status )->toBe( NotificationStatus::Sent )
            ->and( $log->fresh()->sent_at )->not->toBeNull();

        $failed = NotificationLog::factory()->create();

        expect( $failed->markFailed( 'Mailbox full.' ) )->toBeTrue()
            ->and( $failed->fresh()->status )->toBe( NotificationStatus::Failed )
            ->and( $failed->fresh()->error )->toBe( 'Mailbox full.' );
    } );

    it( 'builds reminder, sent, and failed entries', function (): void {
        expect( NotificationLog::factory()->reminder()->create()->type )->toBe( NotificationType::Reminder )
            ->and( NotificationLog::factory()->sent()->create()->status )->toBe( NotificationStatus::Sent )
            ->and( NotificationLog::factory()->failed()->create()->status )->toBe( NotificationStatus::Failed )
            ->and( NotificationLog::factory()->onChannel( 'sms' )->create()->channel )->toBe( 'sms' );
    } );

    it( 'reaches its booking from either direction', function (): void {
        $booking = Booking::factory()->create();
        NotificationLog::factory()->count( 2 )->for( $booking )->sequence(
            [ 'type' => NotificationType::Confirmation ],
            [ 'type' => NotificationType::Cancellation ],
        )->create();

        expect( $booking->notificationLogs()->count() )->toBe( 2 );
    } );
} );
