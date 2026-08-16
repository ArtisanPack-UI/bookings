<?php

declare( strict_types=1 );

use ArtisanPackUI\Bookings\Enums\BookingAssignmentStrategy;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Models\Booking;
use ArtisanPackUI\Bookings\Models\BookingSeries;
use ArtisanPackUI\Bookings\Models\IntakeSchemaVersion;
use ArtisanPackUI\Bookings\Models\NotificationLog;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\WebhookDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\TestsWithSqlite;

uses( TestsWithSqlite::class, RefreshDatabase::class );

describe( 'the booking factory', function (): void {
    it( 'produces a valid booking', function (): void {
        $booking = Booking::factory()->create();

        expect( $booking->exists )->toBeTrue()
            ->and( $booking->booking_number )->toStartWith( 'BK-' )
            ->and( $booking->status )->toBe( BookingStatus::Confirmed )
            ->and( $booking->assignment_strategy )->toBe( BookingAssignmentStrategy::Customer )
            ->and( $booking->end_time->gt( $booking->start_time ) )->toBeTrue();
    } );

    it( 'gives every booking a reference of its own', function (): void {
        $numbers = Booking::factory()->count( 20 )->create()->pluck( 'booking_number' );

        expect( $numbers->unique() )->toHaveCount( 20 );
    } );

    it( 'builds each lifecycle state', function (): void {
        expect( Booking::factory()->requested()->create()->status )->toBe( BookingStatus::Requested )
            ->and( Booking::factory()->cancelled()->create()->status )->toBe( BookingStatus::Cancelled )
            ->and( Booking::factory()->completed()->create()->status )->toBe( BookingStatus::Completed )
            ->and( Booking::factory()->unassigned()->create()->provider_id )->toBeNull();
    } );

    it( 'scopes to the bookings holding a slot', function (): void {
        Booking::factory()->confirmed()->create();
        Booking::factory()->requested()->create();
        Booking::factory()->cancelled()->create();
        Booking::factory()->completed()->create();

        expect( Booking::active()->count() )->toBe( 2 );
    } );
} );

describe( 'the manage token', function (): void {
    it( 'mints a plain token alongside its hash', function (): void {
        $minted = Booking::newToken();

        expect( $minted['token'] )->toBeString()
            ->and( $minted['token'] )->toHaveLength( 64 )
            ->and( $minted['hash'] )->toBe( hash( 'sha256', $minted['token'] ) );
    } );

    it( 'mints a token on create and stores only the hash', function (): void {
        $booking = Booking::factory()->create();
        $token   = $booking->pullPlainManageToken();

        expect( $token )->toBeString()
            ->and( $booking->manage_token_hash )->toBe( hash( 'sha256', $token ) )
            ->and( $booking->manage_token_hash )->not->toBe( $token );
    } );

    it( 'hands the plain token over once and only once', function (): void {
        $booking = Booking::factory()->create();

        expect( $booking->pullPlainManageToken() )->not->toBeNull()
            ->and( $booking->pullPlainManageToken() )->toBeNull();
    } );

    it( 'never gives the plain token back from a stored booking', function (): void {
        // The plain token lives in the confirmation email and the manage URL and
        // nowhere else. A booking read back out of the database has only the
        // hash, which is the entire point of storing a hash.
        $booking = Booking::factory()->create();

        expect( $booking->fresh()->pullPlainManageToken() )->toBeNull();
    } );

    it( 'keeps the hash out of serialised output', function (): void {
        $booking = Booking::factory()->create();

        expect( $booking->toArray() )->not->toHaveKey( 'manage_token_hash' )
            ->and( $booking->manage_token_hash )->not->toBeEmpty();
    } );

    it( 'refuses to let the hash be mass assigned', function (): void {
        // A caller who could set the hash could set it to the hash of a token
        // they already know, which is the attack the hashing exists to prevent.
        // Written through Booking::create() rather than the factory on purpose:
        // factories build their models unguarded, so the guard has to be checked
        // on the path request data would actually take.
        $chosen  = hash( 'sha256', 'chosen-by-me' );
        $booking = Booking::create( [
            'service_id'            => Service::factory()->create()->id,
            'manage_token_hash'     => $chosen,
            'customer_name'         => 'Sam',
            'customer_email'        => 'sam@example.test',
            'customer_timezone'     => 'America/Chicago',
            'start_time'            => '2026-05-01 15:00:00',
            'end_time'              => '2026-05-01 15:30:00',
            'intake_schema_version' => 1,
        ] );

        expect( $booking->manage_token_hash )->not->toBe( $chosen )
            ->and( $booking->manage_token_hash )->toBe( hash( 'sha256', $booking->pullPlainManageToken() ) );
    } );

    it( 'finds a booking by its plain token', function (): void {
        $booking = Booking::factory()->create();
        $token   = $booking->pullPlainManageToken();

        expect( Booking::findByManageToken( $token )?->is( $booking ) )->toBeTrue()
            ->and( Booking::findByManageToken( 'not-a-real-token' ) )->toBeNull();
    } );
} );

describe( 'the booking casts', function (): void {
    it( 'round-trips intake data through JSON', function (): void {
        $data = [ 'goal' => 'Plan the migration', 'attendees' => [ 'sam', 'dana' ], 'budget' => 5000 ];

        $booking = Booking::factory()->create( [ 'intake_data' => $data ] );

        expect( $booking->fresh()->intake_data )->toBe( $data );
    } );

    it( 'reads the times back as Carbon instances in UTC', function (): void {
        $booking = Booking::factory()->startingAt( '2026-06-01 14:00:00', 60 )->create();

        $fresh = $booking->fresh();

        // The zone is asserted, not just the clock face. Without it this passes
        // under any application timezone and says nothing about the UTC contract
        // the column is documented to hold.
        expect( config( 'app.timezone' ) )->toBe( 'UTC' )
            ->and( $fresh->start_time )->toBeInstanceOf( Carbon::class )
            ->and( $fresh->start_time->getTimezone()->getName() )->toBe( 'UTC' )
            ->and( $fresh->start_time->format( 'Y-m-d H:i' ) )->toBe( '2026-06-01 14:00' )
            ->and( $fresh->end_time->getTimezone()->getName() )->toBe( 'UTC' )
            ->and( $fresh->end_time->format( 'Y-m-d H:i' ) )->toBe( '2026-06-01 15:00' );
    } );

    it( 'renders the start time back in the customer timezone', function (): void {
        $booking = Booking::factory()
            ->startingAt( '2026-06-01 14:00:00' )
            ->create( [ 'customer_timezone' => 'America/Chicago' ] );

        expect( $booking->startTimeForCustomer()->format( 'H:i' ) )->toBe( '09:00' )
            ->and( $booking->customer_timezone )->toBe( 'America/Chicago' );
    } );

    it( 'reads the status and strategy back as enums', function (): void {
        $booking = Booking::factory()->create( [
            'status'              => 'no_show',
            'assignment_strategy' => 'round_robin',
        ] );

        expect( $booking->fresh()->status )->toBe( BookingStatus::NoShow )
            ->and( $booking->fresh()->assignment_strategy )->toBe( BookingAssignmentStrategy::RoundRobin )
            ->and( $booking->fresh()->occupiesSlot() )->toBeFalse();
    } );
} );

describe( 'series occurrences', function (): void {
    it( 'produces a coherent head and occurrences', function (): void {
        $bookings = Booking::factory()->confirmed()->count( 4 )->forSeries()->create();
        $series   = $bookings->first()->series;

        expect( $series )->toBeInstanceOf( BookingSeries::class )
            ->and( $bookings->pluck( 'series_index' )->all() )->toBe( [ 1, 2, 3, 4 ] )
            ->and( $bookings->pluck( 'service_id' )->unique()->all() )->toBe( [ $series->service_id ] )
            ->and( $bookings->pluck( 'customer_email' )->unique()->all() )->toBe( [ $series->customer_email ] )
            ->and( $bookings->pluck( 'status' )->unique()->all() )->toBe( [ BookingStatus::Confirmed ] )
            ->and( $series->occurrences()->count() )->toBe( 4 );
    } );

    it( 'keeps a weekly series on the same clock face across a spring forward', function (): void {
        // 8 March 2026 is when the US clocks go forward. A series stored as an
        // instant would drift an hour here; a floating start plus a timezone
        // does not, which is the whole reason the rule is stored the way it is.
        $series = BookingSeries::factory()
            ->startingLocally( '2026-03-01 15:00', 'America/Chicago' )
            ->create();

        $bookings = Booking::factory()->count( 2 )->forSeries( $series )->create();

        $localTimes = $bookings->map(
            fn ( Booking $booking ): string => $booking->start_time->copy()
                ->setTimezone( 'America/Chicago' )
                ->format( 'Y-m-d H:i' ),
        );

        expect( $localTimes->all() )->toBe( [ '2026-03-01 15:00', '2026-03-08 15:00' ] )
            ->and( $bookings->map( fn ( Booking $b ): string => $b->start_time->format( 'H:i' ) )->all() )
            ->toBe( [ '21:00', '20:00' ] );
    } );

    it( 'materialises a series from the factory', function (): void {
        $series = BookingSeries::factory()->withOccurrences( 3 )->create();

        expect( $series->occurrences()->count() )->toBe( 3 )
            ->and( $series->attachedOccurrences()->count() )->toBe( 3 )
            ->and( $series->nextSeriesIndex() )->toBe( 4 );
    } );

    it( 'detaches an occurrence without cutting it loose from the series', function (): void {
        $series  = BookingSeries::factory()->withOccurrences( 3 )->create();
        $edited  = $series->occurrences()->first();
        $changed = $edited->detachFromSeries();

        expect( $changed )->toBeTrue()
            ->and( $edited->fresh()->isDetachedFromSeries() )->toBeTrue()
            ->and( $edited->fresh()->series_id )->toBe( $series->id )
            ->and( $series->attachedOccurrences()->count() )->toBe( 2 )
            ->and( $series->occurrences()->count() )->toBe( 3 );
    } );

    it( 'detaches only once', function (): void {
        $series = BookingSeries::factory()->withOccurrences( 1 )->create();
        $edited = $series->occurrences()->first();

        $edited->detachFromSeries();

        expect( $edited->detachFromSeries() )->toBeFalse();
    } );

    it( 'refuses to detach a booking that was never part of a series', function (): void {
        expect( Booking::factory()->create()->detachFromSeries() )->toBeFalse();
    } );

    it( 'strips a series index off a booking with no series', function (): void {
        // The index is a position within a rule. Leaving a stale one behind
        // would let a later re-materialisation match on a position in a rule
        // that no longer exists.
        $booking = Booking::factory()->create( [ 'series_index' => 7 ] );

        expect( $booking->series_index )->toBeNull()
            ->and( $booking->fresh()->series_index )->toBeNull();
    } );

    it( 'leaves the index alone on a booking that never loaded its series', function (): void {
        // A partially hydrated booking has no series_id attribute at all, and
        // reading one back gives null whether it has a series or not. Acting on
        // that would let an unrelated status update wipe the position data every
        // occurrence in an admin list depends on.
        $series = BookingSeries::factory()->withOccurrences( 2 )->create();

        $partial         = Booking::query()->select( 'id', 'status' )->first();
        $partial->status = BookingStatus::Completed;
        $partial->save();

        expect( $series->occurrences()->pluck( 'series_index' )->all() )->toBe( [ 1, 2 ] );
    } );

    it( 'keeps detachment off the mass-assignable list', function (): void {
        // Detaching is a transition with a method behind it, the same shape as
        // disabling a webhook — not an attribute a request body gets to set.
        $booking = Booking::factory()->create();

        $booking->fill( [ 'detached_from_series_at' => now() ] );

        expect( $booking->detached_from_series_at )->toBeNull();
    } );

    it( 'does not reissue an index a soft-deleted occurrence still holds', function (): void {
        // A soft delete does not free the position: the row is still there and
        // can be restored, and (series_id, series_index) is only a plain index,
        // so a duplicate would go in without complaint.
        $series = BookingSeries::factory()->withOccurrences( 3 )->create();

        // reorder() first. occurrences() already orders by series_index ascending,
        // and orderByDesc() would append a second column rather than replace the
        // first — leaving occurrence 1 deleted, the highest index still visible,
        // and this test green whether or not withTrashed() is there at all.
        $last = $series->occurrences()->reorder()->orderByDesc( 'series_index' )->first();

        expect( $last->series_index )->toBe( 3 );

        $last->delete();

        expect( $series->occurrences()->count() )->toBe( 2 )
            ->and( $series->occurrences()->max( 'series_index' ) )->toBe( 2 )
            ->and( $series->nextSeriesIndex() )->toBe( 4 );
    } );

    it( 'nulls the index when the series behind it goes away', function (): void {
        $series  = BookingSeries::factory()->withOccurrences( 2 )->create();
        $booking = $series->occurrences()->first();

        $booking->series_id = null;
        $booking->save();

        expect( $booking->fresh()->series_index )->toBeNull();
    } );
} );

describe( 'the booking series model', function (): void {
    it( 'reads its floating start back in its own timezone', function (): void {
        $series = BookingSeries::factory()
            ->startingLocally( '2026-03-01 15:00', 'America/Chicago' )
            ->create();

        expect( $series->dtstart()->format( 'Y-m-d H:i' ) )->toBe( '2026-03-01 15:00' )
            ->and( $series->dtstart()->timezoneName )->toBe( 'America/Chicago' )
            ->and( $series->dtstart()->utcOffset() )->toBe( -360 )
            ->and( $series->until() )->toBeNull();
    } );

    it( 'reads a bounded end back the same way', function (): void {
        $series = BookingSeries::factory()
            ->startingLocally( '2026-03-01 15:00', 'America/Chicago' )
            ->untilLocal( '2026-06-01 15:00' )
            ->create();

        expect( $series->until()?->format( 'Y-m-d H:i' ) )->toBe( '2026-06-01 15:00' )
            ->and( $series->occurrence_count )->toBeNull();
    } );

    it( 'round-trips metadata through JSON', function (): void {
        $metadata = [ 'source' => 'widget', 'coupon' => [ 'code' => 'SPRING', 'percent' => 10 ] ];

        $series = BookingSeries::factory()->create( [ 'metadata' => $metadata ] );

        expect( $series->fresh()->metadata )->toBe( $metadata );
    } );

    it( 'scopes away cancelled series', function (): void {
        BookingSeries::factory()->count( 2 )->create();
        $cancelled = BookingSeries::factory()->cancelled()->create();

        expect( BookingSeries::active()->count() )->toBe( 2 )
            ->and( $cancelled->isCancelled() )->toBeTrue();
    } );

    it( 'soft deletes and erases independently', function (): void {
        $erased = BookingSeries::factory()->erased()->create();

        expect( $erased->isPiiErased() )->toBeTrue()
            ->and( $erased->customer_name )->toBe( '[erased]' )
            ->and( $erased->deleted_at )->toBeNull();

        $erased->delete();

        expect( BookingSeries::query()->count() )->toBe( 0 )
            ->and( BookingSeries::withTrashed()->count() )->toBe( 1 );
    } );
} );

describe( 'intake schema versions', function (): void {
    it( 'records a version of a service form', function (): void {
        $version = IntakeSchemaVersion::factory()->create();

        expect( $version->exists )->toBeTrue()
            ->and( $version->version )->toBe( 1 )
            ->and( $version->schema )->toHaveKey( 'fields' );
    } );

    it( 'refuses two rows claiming to be the same version of one service', function (): void {
        // Versions are what bookings point at. A service with two rows for
        // version 3 makes an old booking's answers ambiguous.
        $service = Service::factory()->create();

        IntakeSchemaVersion::factory()->for( $service )->version( 3 )->create();

        expect( fn () => IntakeSchemaVersion::factory()->for( $service )->version( 3 )->create() )
            ->toThrow( UniqueConstraintViolationException::class );
    } );

    it( 'lets two services each have their own version 1', function (): void {
        IntakeSchemaVersion::factory()->for( Service::factory() )->create();
        IntakeSchemaVersion::factory()->for( Service::factory() )->create();

        expect( IntakeSchemaVersion::query()->count() )->toBe( 2 );
    } );

    it( 'builds a consecutive history for one service', function (): void {
        $service = Service::factory()->create();

        IntakeSchemaVersion::factory()->for( $service )->history( 3 )->create();

        expect( IntakeSchemaVersion::forService( $service )->orderBy( 'version' )->pluck( 'version' )->all() )
            ->toBe( [ 1, 2, 3 ] );
    } );

    it( 'gives a service back the schema a booking was captured against', function (): void {
        $service = Service::factory()->create();

        IntakeSchemaVersion::factory()->for( $service )->version( 2 )->create( [
            'schema' => [ 'fields' => [ [ 'name' => 'legacy_goal', 'type' => 'text' ] ] ],
        ] );

        expect( $service->intakeSchemaForVersion( 2 ) )
            ->toBe( [ 'fields' => [ [ 'name' => 'legacy_goal', 'type' => 'text' ] ] ] )
            ->and( $service->intakeSchemaForVersion( 99 ) )->toBeNull();
    } );
} );

describe( 'booking erasure', function (): void {
    it( 'redacts rather than nulls, and marks the row as erased', function (): void {
        // The columns are NOT NULL on purpose: a booking having had a customer
        // is not a fact the schema stops asserting to make erasure easier. The
        // marker is what tells a redacted row apart from a real one.
        $booking = Booking::factory()->erased()->create();

        expect( $booking->isPiiErased() )->toBeTrue()
            ->and( $booking->customer_name )->toBe( '[erased]' )
            ->and( $booking->customer_email )->not->toBeNull()
            ->and( $booking->intake_data )->toBeNull();
    } );

    it( 'separates erased bookings from intact ones', function (): void {
        $erased = Booking::factory()->erased()->create();
        $intact = Booking::factory()->create();

        expect( Booking::piiErased()->get()->modelKeys() )->toBe( [ $erased->id ] )
            ->and( Booking::notPiiErased()->get()->modelKeys() )->toBe( [ $intact->id ] );
    } );

    it( 'scrubs the personal columns and marks the row when erased', function (): void {
        $booking = Booking::factory()->create( [
            'customer_name'  => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
            'customer_phone' => '+15551234567',
            'intake_data'    => [ 'goal' => 'Learn to book.' ],
            'notes'          => 'Called ahead.',
        ] );

        $booking->erasePersonalData();

        $fresh = $booking->fresh();

        expect( $fresh->isPiiErased() )->toBeTrue()
            ->and( $fresh->customer_name )->toBe( Booking::PII_PLACEHOLDER )
            ->and( $fresh->customer_name )->not->toBe( 'Ada Lovelace' )
            ->and( $fresh->customer_email )->not->toBe( 'ada@example.test' )
            ->and( $fresh->customer_phone )->toBeNull()
            ->and( $fresh->intake_data )->toBeNull()
            ->and( $fresh->notes )->toBeNull()
            ->and( $fresh->pii_erased_at )->not->toBeNull();
    } );

    it( 'leaves an already-erased booking untouched', function (): void {
        $booking = Booking::factory()->erased()->create();

        $markedAt = $booking->pii_erased_at;

        $booking->erasePersonalData();

        expect( $booking->fresh()->pii_erased_at->equalTo( $markedAt ) )->toBeTrue();
    } );

    it( 'redacts the customer-facing notification log but keeps staff rows', function (): void {
        // The log is the second place the customer's address lands — in
        // `recipient` and, when a send fails, quoted back inside `error`. Staff
        // sends record an internal notifiable reference on the `database`
        // channel, so erasure leaves those as the record of who was told.
        $booking = Booking::factory()->create();

        $mail = NotificationLog::factory()->for( $booking )->failed()->create( [
            'channel'   => 'mail',
            'recipient' => 'ada@example.test',
        ] );
        $sms = NotificationLog::factory()->for( $booking )->create( [
            'channel'   => 'sms',
            'recipient' => '+15551234567',
        ] );
        $staff = NotificationLog::factory()->for( $booking )->create( [
            'channel'   => 'database',
            'recipient' => 'App\\Models\\User:12',
        ] );

        $booking->erasePersonalData();

        expect( $mail->fresh()->recipient )->toBe( Booking::PII_PLACEHOLDER )
            ->and( $mail->fresh()->error )->toBeNull()
            ->and( $sms->fresh()->recipient )->toBe( Booking::PII_PLACEHOLDER )
            ->and( $staff->fresh()->recipient )->toBe( 'App\\Models\\User:12' );
    } );

    it( 'deletes the webhook deliveries that carry a copy of the booking', function (): void {
        // A delivery payload is a whole copy of the booking, name and all, and
        // there is no redacting inside stored JSON — so erasure deletes the
        // booking's own deliveries and leaves everyone else's.
        $booking = Booking::factory()->create();
        $other   = Booking::factory()->create();

        $mine = WebhookDelivery::factory()->create( [
            'payload' => [ 'event' => 'booking.created', 'data' => [ 'booking' => [ 'id' => $booking->id ] ] ],
        ] );
        $theirs = WebhookDelivery::factory()->create( [
            'payload' => [ 'event' => 'booking.created', 'data' => [ 'booking' => [ 'id' => $other->id ] ] ],
        ] );

        $booking->erasePersonalData();

        expect( WebhookDelivery::query()->whereKey( $mine->id )->exists() )->toBeFalse()
            ->and( WebhookDelivery::query()->whereKey( $theirs->id )->exists() )->toBeTrue();
    } );

    it( 'redacts the series only once its last intact occurrence is erased', function (): void {
        // The series is the template future occurrences are built from, so it
        // holds the customer's name too — but redacting it while a sibling
        // occurrence is still live would poison every occurrence made after.
        $series = BookingSeries::factory()->create( [
            'customer_name'  => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
        ] );

        $first  = Booking::factory()->for( $series, 'series' )->create();
        $second = Booking::factory()->for( $series, 'series' )->create();

        $first->erasePersonalData();

        expect( $series->fresh()->isPiiErased() )->toBeFalse();

        $second->erasePersonalData();

        expect( $series->fresh()->isPiiErased() )->toBeTrue()
            ->and( $series->fresh()->customer_name )->toBe( BookingSeries::PII_PLACEHOLDER )
            ->and( $series->fresh()->customer_email )->not->toBe( 'ada@example.test' );
    } );
} );
